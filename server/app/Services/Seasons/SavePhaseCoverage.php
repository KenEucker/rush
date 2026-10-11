<?php

namespace App\Services\Seasons;

use App\Exceptions\RevisionConflict;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Season;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SavePhaseCoverage
{
    public function __construct(private PhaseCoverage $coverage) {}

    public function handle(User $actor, Organization $organization, string $id, array $input): array
    {
        return DB::transaction(function () use ($actor, $organization, $id, $input) {
            OrganizationMembership::query()->where('organization_id', $organization->id)->where('user_id', $actor->id)->lockForUpdate()->first();
            $phase = $this->coverage->phase($actor, $organization, $id);
            Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $season = Season::query()->lockForUpdate()->findOrFail($phase->season_id);
            $values = Validator::make($input, [
                'expected_revision' => ['present', 'nullable', 'integer', 'between:1,2147483646'],
                'expected_season_revision' => ['required', 'integer', 'min:1'],
                'model' => ['required', Rule::in(['dedicated', 'shared'])],
                'shifts' => ['required', 'array', 'max:100'],
                'shifts.*' => ['array:id,name,start_time,duration_minutes'],
                'shifts.*.id' => ['nullable', 'uuid', 'distinct'],
                'shifts.*.name' => ['required', 'string', 'max:120', 'distinct:ignore_case'],
                'shifts.*.start_time' => ['required', 'date_format:H:i'],
                'shifts.*.duration_minutes' => ['required', 'integer', 'between:1,2147483647'],
                'areas' => ['required', 'array', 'max:100'],
                'areas.*' => ['array:id,name'],
                'areas.*.id' => ['nullable', 'uuid', 'distinct'],
                'areas.*.name' => ['required', 'string', 'max:120', 'distinct:ignore_case', 'not_regex:/,/'],
                'groups' => ['present', 'array', 'max:100'],
                'groups.*' => ['array:id,name,area_names'],
                'groups.*.id' => ['nullable', 'uuid', 'distinct'],
                'groups.*.name' => ['required', 'string', 'max:120', 'distinct:ignore_case'],
                'groups.*.area_names' => ['required', 'array', 'max:100'],
                'groups.*.area_names.*' => ['required', 'string', 'max:120'],
                'staffing' => ['present', 'array', 'max:10000'],
                'staffing.*' => ['array:shift_name,area_name,group_name,positions'],
                'staffing.*.shift_name' => ['required', 'string'],
                'staffing.*.area_name' => ['nullable', 'string'],
                'staffing.*.group_name' => ['nullable', 'string'],
                'staffing.*.positions' => ['required', 'integer', 'between:0,2147483647'],
                'reason' => ['required', 'string', 'max:500'],
            ])->validate();
            $before = $this->coverage->snapshot($phase);
            $revision = $values['expected_revision'] === null ? null : (int) $values['expected_revision'];
            if ($before['revision'] !== $revision || $season->revision !== (int) $values['expected_season_revision']) {
                throw new RevisionConflict;
            }
            if ($values['model'] === 'dedicated' && $values['groups'] !== []) {
                throw ValidationException::withMessages(['groups' => 'Dedicated coverage cannot contain shared groups.']);
            }
            if ($values['model'] === 'shared' && $values['groups'] === []) {
                throw ValidationException::withMessages(['groups' => 'Shared coverage needs at least one area group.']);
            }
            // Remove dependent configuration only, never assignment snapshots or history.
            DB::table('coverage_staffing')->where('phase_id', $id)->delete();
            DB::table('coverage_group_areas')->where('phase_id', $id)->delete();
            $maps = [];
            foreach (['shifts' => 'shift_definitions', 'areas' => 'coverage_areas', 'groups' => 'coverage_groups'] as $key => $table) {
                $existing = DB::table($table)->where('phase_id', $id)->pluck('id');
                $retained = [];
                foreach ($values[$key] as $index => $row) {
                    $rowId = $row['id'] ?? (string) Str::uuid();
                    if (! $existing->contains($rowId) && DB::table($table)->where('id', $rowId)->exists()) {
                        throw ValidationException::withMessages(["$key.$index.id" => 'This identifier belongs to another phase.']);
                    }
                    $fields = collect($row)->except(['id', 'area_names'])->all();
                    DB::table($table)->updateOrInsert(['id' => $rowId], [...$fields, 'phase_id' => $id]);
                    $maps[$key][$row['name']] = $rowId;
                    $retained[] = $rowId;
                }
                DB::table($table)->where('phase_id', $id)->whereNotIn('id', $retained)->delete();
            }
            foreach ($values['groups'] as $index => $group) {
                if (count(array_unique($group['area_names'])) !== count($group['area_names'])) {
                    throw ValidationException::withMessages(["groups.$index.area_names" => 'Choose each area only once.']);
                }
                foreach ($group['area_names'] as $name) {
                    $area = $maps['areas'][$name] ?? null;
                    if (! $area) {
                        throw ValidationException::withMessages(["groups.$index.area_names" => 'Choose areas defined in this phase.']);
                    }
                    DB::table('coverage_group_areas')->insert(['phase_id' => $id, 'group_id' => $maps['groups'][$group['name']], 'area_id' => $area]);
                }
            }
            DB::table('phase_coverages')->updateOrInsert(['phase_id' => $id], ['model' => $values['model'], 'revision' => ($revision ?? 0) + 1]);
            $seen = [];
            foreach ($values['staffing'] as $index => $row) {
                $dedicated = $values['model'] === 'dedicated';
                $shift = $maps['shifts'][$row['shift_name']] ?? null;
                $target = $maps[$dedicated ? 'areas' : 'groups'][$row[$dedicated ? 'area_name' : 'group_name'] ?? ''] ?? null;
                if (! $shift || ! $target || filled($row[$dedicated ? 'group_name' : 'area_name'] ?? null)) {
                    throw ValidationException::withMessages(["staffing.$index" => 'Use a shift and '.($dedicated ? 'dedicated area' : 'shared group').' from this phase only; coverage models cannot be mixed.']);
                }
                $pair = $shift.':'.$target;
                if (isset($seen[$pair])) {
                    throw ValidationException::withMessages(["staffing.$index" => 'Set one staffing count per shift and coverage target.']);
                }
                $seen[$pair] = true;
                DB::table('coverage_staffing')->insert(['id' => (string) Str::uuid(), 'phase_id' => $id, 'model' => $values['model'],
                    'shift_id' => $shift, 'area_id' => $dedicated ? $target : null, 'group_id' => $dedicated ? null : $target, 'positions' => (int) $row['positions']]);
            }
            $after = $this->coverage->snapshot($phase);
            DB::table('phase_coverage_changes')->insert(['id' => (string) Str::uuid(), 'phase_id' => $id, 'actor_id' => $actor->id,
                'revision' => $after['revision'], 'reason' => $values['reason'], 'before' => $revision === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
                'after' => json_encode($after, JSON_THROW_ON_ERROR), 'occurred_at' => now('UTC')]);

            return $after;
        }, 3);
    }
}
