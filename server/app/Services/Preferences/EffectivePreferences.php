<?php

namespace App\Services\Preferences;

use App\Models\OrganizationMembership;
use App\Models\PreferenceProfile;
use App\Models\Season;
use App\Services\Seasons\SeasonCalendar;
use Carbon\CarbonImmutable;

/** Internal scheduling input; callers must authorize the planning/read operation. */
class EffectivePreferences
{
    public function forInstant(OrganizationMembership $membership, Season $season, CarbonImmutable $instant): array
    {
        abort_unless($season->organization_id === $membership->organization_id, 403);
        $week = app(SeasonCalendar::class)->week($season, $instant)['starts_on'];
        $records = PreferenceProfile::query()->where('organization_membership_id', $membership->id)
            ->whereIn('profile_key', ['default', $season->id.':'.$week])->get()->keyBy('profile_key');
        $default = $records->get('default');
        $override = $records->get($season->id.':'.$week);
        $hours = $override?->hours_mode !== null ? $override : $default;

        return ['week_starts_on' => $week,
            'preferred_shift_ids' => $override?->preferred_shift_ids ?? $default?->preferred_shift_ids ?? [],
            'hours_mode' => $hours?->hours_mode ?? 'none', 'desired_weekly_minutes' => $hours?->desired_weekly_minutes,
            'days_off' => $override?->days_off ?? $default?->days_off ?? 'none',
            'input_revisions' => ['default' => $default ? ['id' => $default->id, 'revision' => $default->revision] : null,
                'weekly' => $override ? ['id' => $override->id, 'revision' => $override->revision] : null]];
    }
}
