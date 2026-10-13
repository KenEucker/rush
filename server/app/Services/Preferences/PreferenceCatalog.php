<?php

namespace App\Services\Preferences;

use App\Enums\OrganizationRole;
use App\Models\OrganizationMembership;
use App\Models\Season;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PreferenceCatalog
{
    /** Only public configuration needed to select positive preferences. */
    public function forMembership(OrganizationMembership $membership): Collection
    {
        if (! $membership->is_active || $membership->role !== OrganizationRole::Ranger) {
            return collect();
        }

        return Season::query()->where('organization_id', $membership->organization_id)->orderBy('id')->get()
            ->mapWithKeys(function (Season $season) {
                $shifts = DB::table('shift_definitions')->join('phases', 'phases.id', '=', 'shift_definitions.phase_id')
                    ->where('phases.season_id', $season->id)->orderBy('shift_definitions.id')
                    ->get(['shift_definitions.id', 'shift_definitions.name', 'shift_definitions.start_time',
                        'shift_definitions.duration_minutes', 'phases.name as phase_name', 'phases.starts_on', 'phases.ends_on'])
                    ->map(fn ($shift) => (array) $shift)->all();

                return [$season->id => [
                    'id' => $season->id, 'organization_id' => $season->organization_id,
                    'name' => $season->name, 'starts_on' => $season->starts_on, 'ends_on' => $season->ends_on,
                    'timezone' => $season->timezone, 'week_starts_on' => $season->week_starts_on, 'shifts' => $shifts,
                ]];
            });
    }
}
