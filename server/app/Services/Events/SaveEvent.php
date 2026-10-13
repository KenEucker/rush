<?php

namespace App\Services\Events;

use App\Exceptions\RevisionConflict;
use App\Models\Event;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\Seasons\SeasonCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaveEvent
{
    public function handle(User $actor, Organization $organization, string $seasonId, string $id, array $input): Event
    {
        return DB::transaction(function () use ($actor, $organization, $seasonId, $id, $input) {
            OrganizationMembership::query()->where('organization_id', $organization->id)->where('user_id', $actor->id)->lockForUpdate()->first();
            $configuration = app(EventConfiguration::class);
            $configuration->season($actor, $organization, $seasonId);
            Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $season = $configuration->season($actor, $organization, $seasonId);
            $values = Validator::make([...$input, 'id' => $id], [
                'id' => ['required', 'uuid'],
                'expected_revision' => ['present', 'nullable', 'integer', 'between:1,2147483646'],
                'expected_season_revision' => ['required', 'integer', 'min:1'],
                'name' => ['required', 'string', 'max:120'],
                'starts_local' => ['required', 'date_format:Y-m-d H:i:s'],
                'ends_local' => ['required', 'date_format:Y-m-d H:i:s'],
                'starts_offset' => ['nullable', 'string', 'regex:/^[+-]\d{2}:\d{2}$/D'],
                'ends_offset' => ['nullable', 'string', 'regex:/^[+-]\d{2}:\d{2}$/D'],
                'paid' => ['required', 'boolean'], 'counts_scheduled' => ['required', 'boolean'],
                'target_type' => ['required', Rule::in(['none', 'number', 'percentage'])],
                'target_value' => [Rule::requiredIf(($input['target_type'] ?? 'none') !== 'none'), 'nullable', 'integer', 'min:0', ($input['target_type'] ?? '') === 'percentage' ? 'max:100' : 'max:10000'],
                'attendance_membership_ids' => ['present', 'array', 'max:1000'],
                'attendance_membership_ids.*' => ['required', 'uuid', 'distinct'],
                'reason' => ['required', 'string', 'max:500'],
                'recurrence' => ['prohibited'],
            ])->validate();
            $event = Event::query()->lockForUpdate()->find($id);
            abort_if($event && ($event->organization_id !== $organization->id || $event->season_id !== $seasonId), 404);
            $revision = $values['expected_revision'] === null ? null : (int) $values['expected_revision'];
            if ($event?->revision !== $revision || $season->revision !== (int) $values['expected_season_revision']) {
                throw new RevisionConflict;
            }
            if ($values['target_type'] === 'none' && ($values['target_value'] ?? null) !== null) {
                throw ValidationException::withMessages(['target_value' => 'Leave the value empty when there is no staffing target.']);
            }
            $calendar = app(SeasonCalendar::class);
            $start = $calendar->resolveLocal($values['starts_local'], $season->timezone, $values['starts_offset'] ?? null);
            $end = $calendar->resolveLocal($values['ends_local'], $season->timezone, $values['ends_offset'] ?? null);
            $seasonEnd = $calendar->dayBoundary(CarbonImmutable::parse($season->ends_on, 'UTC')->addDay()->format('Y-m-d'), $season->timezone);
            if ($end <= $start || $start < $calendar->dayBoundary($season->starts_on, $season->timezone) || $end > $seasonEnd) {
                throw ValidationException::withMessages(['ends_local' => 'Choose a positive event interval entirely within the season.']);
            }
            $members = OrganizationMembership::query()->where('organization_id', $organization->id)
                ->whereIn('id', $values['attendance_membership_ids'])->lockForUpdate()->get();
            if ($members->count() !== count($values['attendance_membership_ids']) || $members->contains(fn ($member) => ! $member->is_active || $member->role->value !== 'ranger')) {
                throw ValidationException::withMessages(['attendance_membership_ids' => 'Choose active Rangers from this organization.']);
            }
            $before = $event ? $configuration->snapshot($event) : null;
            $event ??= new Event(['id' => $id, 'organization_id' => $organization->id, 'season_id' => $seasonId]);
            $event->fill([...collect($values)->only(['name', 'paid', 'counts_scheduled', 'target_type'])->all(),
                'target_value' => $values['target_value'] ?? null, 'starts_at' => $start, 'ends_at' => $end,
                'timezone' => $season->timezone, 'season_revision' => $season->revision, 'revision' => ($revision ?? 0) + 1])->save();
            DB::table('event_attendances')->where('event_id', $id)->update(['is_assigned' => false, 'updated_at' => now('UTC')]);
            foreach ($members as $member) {
                $attendance = DB::table('event_attendances')->where('event_id', $id)->where('organization_membership_id', $member->id)->first();
                DB::table('event_attendances')->updateOrInsert(['event_id' => $id, 'organization_membership_id' => $member->id], [
                    'id' => $attendance?->id ?? (string) Str::uuid(), 'organization_id' => $organization->id, 'is_assigned' => true,
                    'created_at' => $attendance?->created_at ?? now('UTC'), 'updated_at' => now('UTC'),
                ]);
            }
            DB::table('event_changes')->insert(['id' => (string) Str::uuid(), 'event_id' => $id, 'actor_id' => $actor->id,
                'revision' => $event->revision, 'reason' => $values['reason'], 'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
                'after' => json_encode($configuration->snapshot($event), JSON_THROW_ON_ERROR), 'occurred_at' => now('UTC')]);

            return $event;
        }, 3);
    }
}
