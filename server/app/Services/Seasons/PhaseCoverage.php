<?php

namespace App\Services\Seasons;

use App\Models\Organization;
use App\Models\Phase;
use App\Models\Season;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PhaseCoverage
{
    public function phase(User $actor, Organization $organization, string $id): Phase
    {
        Gate::forUser($actor)->authorize('manage', $organization);

        return Phase::query()->whereIn('season_id', Season::query()->where('organization_id', $organization->id)->select('id'))->findOrFail($id);
    }

    public function snapshot(Phase $phase): array
    {
        return DB::transaction(function () use ($phase) {
            $season = Season::findOrFail($phase->season_id);
            // All configuration writers lock this organization. Keep multi-query reads coherent.
            Organization::query()->whereKey($season->organization_id)->sharedLock()->firstOrFail();

            return $this->readSnapshot($phase);
        });
    }

    private function readSnapshot(Phase $phase): array
    {
        $configuration = DB::table('phase_coverages')->where('phase_id', $phase->id)->first();
        $shifts = DB::table('shift_definitions')->where('phase_id', $phase->id)->orderBy('name')->get();
        $areas = DB::table('coverage_areas')->where('phase_id', $phase->id)->orderBy('name')->get();
        $groups = DB::table('coverage_groups')->where('phase_id', $phase->id)->orderBy('name')->get();
        $members = DB::table('coverage_group_areas')->where('phase_id', $phase->id)->get();

        return [
            'phase_id' => $phase->id, 'season_revision' => Season::findOrFail($phase->season_id)->revision,
            'revision' => $configuration?->revision, 'model' => $configuration?->model,
            'shifts' => $shifts->map(fn ($row) => collect($row)->only(['id', 'name', 'start_time', 'duration_minutes'])->all())->all(),
            'areas' => $areas->map(fn ($row) => ['id' => $row->id, 'name' => $row->name])->all(),
            'groups' => $groups->map(fn ($row) => ['id' => $row->id, 'name' => $row->name,
                'area_names' => $areas->whereIn('id', $members->where('group_id', $row->id)->pluck('area_id'))->pluck('name')->all()])->all(),
            'staffing' => DB::table('coverage_staffing')->where('phase_id', $phase->id)->orderBy('id')->get()->map(fn ($row) => [
                'shift_name' => $shifts->firstWhere('id', $row->shift_id)->name,
                'area_name' => $areas->firstWhere('id', $row->area_id)?->name,
                'group_name' => $groups->firstWhere('id', $row->group_id)?->name,
                'positions' => $row->positions,
            ])->all(),
        ];
    }
}
