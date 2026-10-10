<?php

namespace App\Services\Seasons;

use App\Exceptions\RevisionConflict;
use App\Http\Resources\SeasonResource;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Phase;
use App\Models\Season;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaveSeason
{
    public function handle(User $actor, Organization $organization, string $id, array $input): Season
    {
        return DB::transaction(function () use ($actor, $organization, $id, $input) {
            OrganizationMembership::query()->where('organization_id', $organization->id)
                ->where('user_id', $actor->id)->lockForUpdate()->first();
            Gate::forUser($actor)->authorize('manage', $organization);
            // Also serializes creation and changes by different managers.
            Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $values = Validator::make([...$input, 'id' => $id], [
                'id' => ['required', 'uuid'],
                'expected_revision' => ['present', 'nullable', 'integer', 'min:1', 'max:2147483646'],
                'name' => ['required', 'string', 'max:120'],
                'starts_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:9998-12-31'],
                'ends_on' => ['required', 'date_format:Y-m-d', 'after:starts_on', 'before_or_equal:9998-12-31'],
                'timezone' => ['required', 'string', Rule::in(\DateTimeZone::listIdentifiers())],
                'week_starts_on' => ['required', 'integer', 'between:1,7'],
                'phases' => ['present', 'array', 'max:100'],
                'phases.*' => ['array:id,name,starts_on,ends_on'],
                'phases.*.id' => ['nullable', 'uuid', 'distinct'],
                'phases.*.name' => ['required', 'string', 'max:120'],
                'phases.*.starts_on' => ['required', 'date_format:Y-m-d'],
                'phases.*.ends_on' => ['required', 'date_format:Y-m-d', 'after:phases.*.starts_on'],
                'reason' => [Rule::requiredIf(($input['expected_revision'] ?? null) !== null), 'nullable', 'string', 'max:500'],
            ], [
                'ends_on.after' => 'The season end date must be at least the day after its start date.',
                'phases.*.ends_on.after' => 'The phase end date must be at least the day after its start date.',
            ])->validate();
            $season = Season::query()->lockForUpdate()->find($id);
            abort_if($season && $season->organization_id !== $organization->id, 404);
            $revision = $values['expected_revision'] === null ? null : (int) $values['expected_revision'];
            if ($season?->revision !== $revision) {
                throw new RevisionConflict;
            }
            $existing = $season?->phases()->get()->keyBy('id') ?? collect();
            $submittedIds = collect($values['phases'])->pluck('id')->filter();
            if ($existing->keys()->diff($submittedIds)->isNotEmpty()) {
                throw ValidationException::withMessages(['phases' => 'Keep existing phases; edit their names or dates instead of removing them.']);
            }
            $previousEnd = null;
            foreach (collect($values['phases'])->sortBy('starts_on') as $index => $phase) {
                if (! empty($phase['id']) && ! $existing->has($phase['id']) && Phase::query()->whereKey($phase['id'])->exists()) {
                    throw ValidationException::withMessages(["phases.$index.id" => 'This phase identifier is unavailable.']);
                }
                if ($phase['starts_on'] < $values['starts_on'] || $phase['ends_on'] > $values['ends_on']) {
                    throw ValidationException::withMessages(["phases.$index.starts_on" => 'Each phase must be an ordered date range within the season.']);
                }
                if ($previousEnd !== null && $phase['starts_on'] <= $previousEnd) {
                    throw ValidationException::withMessages(["phases.$index.starts_on" => 'Phase dates cannot overlap (end dates are inclusive).']);
                }
                $previousEnd = $phase['ends_on'];
            }
            $before = $season ? (new SeasonResource($season->load('phases')))->resolve() : null;
            $season ??= new Season(['id' => $id, 'organization_id' => $organization->id]);
            $season->fill(collect($values)->only(['name', 'starts_on', 'ends_on', 'timezone', 'week_starts_on'])->all());
            $season->revision = ($revision ?? 0) + 1;
            $season->save();
            foreach ($values['phases'] as $phase) {
                $record = $existing->get($phase['id'] ?? '') ?? new Phase(['id' => $phase['id'] ?? (string) Str::uuid(), 'season_id' => $season->id]);
                $record->fill(collect($phase)->only(['name', 'starts_on', 'ends_on'])->all())->save();
            }
            $season->load('phases');
            DB::table('season_changes')->insert([
                'id' => (string) Str::uuid(), 'season_id' => $season->id, 'actor_id' => $actor->id,
                'revision' => $season->revision, 'reason' => filled($values['reason'] ?? null) ? $values['reason'] : 'Initial season creation.',
                'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
                'after' => json_encode((new SeasonResource($season))->resolve(), JSON_THROW_ON_ERROR),
                'occurred_at' => now('UTC'),
            ]);

            return $season;
        }, 3);
    }
}
