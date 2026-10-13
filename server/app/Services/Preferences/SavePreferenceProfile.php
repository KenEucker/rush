<?php

namespace App\Services\Preferences;

use App\Exceptions\RevisionConflict;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\PreferenceProfile;
use App\Models\Season;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SavePreferenceProfile
{
    public function handle(User $actor, OrganizationMembership $membership, string $id, ?int $revision, array $input): PreferenceProfile
    {
        return DB::transaction(function () use ($actor, $membership, $id, $revision, $input) {
            $membership = OrganizationMembership::query()->lockForUpdate()->findOrFail($membership->id);
            Gate::forUser($actor)->authorize('create', [PreferenceProfile::class, $membership]);
            abort_unless(($input['membership_id'] ?? null) === $membership->id, 403);
            Organization::query()->whereKey($membership->organization_id)->lockForUpdate()->firstOrFail();
            $current = PreferenceProfile::query()->lockForUpdate()->find($id);
            if ($current) {
                Gate::forUser($actor)->authorize('update', $current);
                abort_unless($current->organization_membership_id === $membership->id, 403);
            }
            $v = Validator::make([...$input, 'id' => $id, 'expected_revision' => $revision], [
                'id' => ['required', 'uuid'], 'expected_revision' => ['nullable', 'integer', 'between:1,2147483646'],
                'season_id' => ['present', 'nullable', 'uuid'], 'week_starts_on' => ['present', 'nullable', 'date_format:Y-m-d'],
                'preferred_shift_ids' => ['present', 'nullable', 'array', 'list', 'max:1000'],
                'preferred_shift_ids.*' => ['required', 'uuid', 'distinct:ignore_case'],
                'hours_mode' => ['present', 'nullable', 'in:none,target,maximum'],
                'desired_weekly_minutes' => ['present', 'nullable', 'integer', 'between:0,10080'],
                'days_off' => ['present', 'nullable', 'in:none,consecutive,separated'],
            ])->validate();
            $v['season_id'] = $v['season_id'] === null ? null : strtolower($v['season_id']);
            $weekly = $v['season_id'] !== null;
            if ($weekly !== ($v['week_starts_on'] !== null)) {
                throw ValidationException::withMessages(['week_starts_on' => 'Choose both a season and its week start.']);
            }
            if (! $weekly && ($v['preferred_shift_ids'] === null || $v['hours_mode'] === null || $v['days_off'] === null)) {
                throw ValidationException::withMessages(['profile' => 'The default must specify every preference. Choose no preference when appropriate.']);
            }
            if (($v['hours_mode'] === 'target') !== ($v['desired_weekly_minutes'] !== null)) {
                throw ValidationException::withMessages(['desired_weekly_minutes' => 'Specify desired hours only for a numeric target.']);
            }
            if ($weekly) {
                $season = Season::query()->where('organization_id', $membership->organization_id)->find($v['season_id']);
                if (! $season) {
                    throw ValidationException::withMessages(['season_id' => 'Choose a season in your organization.']);
                }
                $date = CarbonImmutable::parse($v['week_starts_on'], 'UTC');
                if ($date->dayOfWeekIso !== $season->week_starts_on || $date->addDays(6)->format('Y-m-d') < $season->starts_on || $v['week_starts_on'] > $season->ends_on) {
                    throw ValidationException::withMessages(['week_starts_on' => 'Choose a configured week start whose week overlaps this season.']);
                }
            }
            $key = $weekly ? $v['season_id'].':'.$v['week_starts_on'] : 'default';
            if ($current && $current->profile_key !== $key) {
                throw ValidationException::withMessages(['week_starts_on' => 'A saved profile cannot be moved to a different week.']);
            }
            if ($current?->revision !== $revision || PreferenceProfile::query()->where('organization_membership_id', $membership->id)->where('profile_key', $key)->where('id', '!=', $id)->exists()) {
                throw new RevisionConflict;
            }
            $ids = $v['preferred_shift_ids'] === null ? null : array_map('strtolower', $v['preferred_shift_ids']);
            if ($ids !== null) {
                $valid = DB::table('shift_definitions')->join('phases', 'phases.id', '=', 'shift_definitions.phase_id')
                    ->join('seasons', 'seasons.id', '=', 'phases.season_id')->where('seasons.organization_id', $membership->organization_id)
                    ->when($weekly, fn ($query) => $query->where('seasons.id', $v['season_id'])
                        ->where('phases.starts_on', '<=', $date->addDays(6)->format('Y-m-d'))->where('phases.ends_on', '>=', $v['week_starts_on']))
                    ->whereIn('shift_definitions.id', $ids)->count();
                if ($valid !== count($ids)) {
                    throw ValidationException::withMessages(['preferred_shift_ids' => 'Choose current shifts applicable to this profile. Refresh configuration and review your saved intent.']);
                }
                sort($ids);
            }
            $before = $current?->only(['season_id', 'week_starts_on', 'preferred_shift_ids', 'hours_mode', 'desired_weekly_minutes', 'days_off']);
            $record = $current ?? new PreferenceProfile(['id' => $id, 'organization_id' => $membership->organization_id,
                'organization_membership_id' => $membership->id, 'profile_key' => $key]);
            $record->fill(collect($v)->only(['season_id', 'week_starts_on', 'hours_mode', 'desired_weekly_minutes', 'days_off'])->all());
            $record->fill(['preferred_shift_ids' => $ids, 'revision' => ($revision ?? 0) + 1])->save();
            DB::table('preference_profile_changes')->insert(['id' => (string) Str::uuid(), 'preference_profile_id' => $id,
                'actor_id' => $actor->id, 'revision' => $record->revision, 'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
                'after' => json_encode($record->only(['season_id', 'week_starts_on', 'preferred_shift_ids', 'hours_mode', 'desired_weekly_minutes', 'days_off']), JSON_THROW_ON_ERROR), 'occurred_at' => now('UTC')]);

            return $record;
        }, 3);
    }
}
