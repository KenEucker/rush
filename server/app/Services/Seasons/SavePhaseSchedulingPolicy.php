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

class SavePhaseSchedulingPolicy
{
    public function __construct(private PhaseCoverage $coverage, private PhaseSchedulingPolicy $policies) {}

    public function handle(User $actor, Organization $organization, string $id, array $input): array
    {
        return DB::transaction(function () use ($actor, $organization, $id, $input) {
            OrganizationMembership::query()->where('organization_id', $organization->id)->where('user_id', $actor->id)->lockForUpdate()->first();
            $phase = $this->coverage->phase($actor, $organization, $id);
            Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $season = Season::query()->whereKey($phase->season_id)->lockForUpdate()->firstOrFail();
            app(ConfigurationYear::class)->assertSeasonEditable($season);
            $phase->refresh();
            $rules = [
                'expected_revision' => ['present', 'nullable', 'integer', 'between:1,2147483646'],
                'expected_season_revision' => ['required', 'integer', 'min:1'],
                'expected_coverage_revision' => ['required', 'integer', 'min:1'],
                'reason' => ['required', 'string', 'max:500'],
                'baselines' => ['required', 'array', 'max:100'],
                'baselines.*' => ['array:shift_id,shift_name,classification'],
                'baselines.*.shift_id' => ['required', 'uuid', 'distinct'],
                'baselines.*.classification' => ['required', Rule::in(PhaseSchedulingPolicy::CLASSIFICATIONS)],
                'weekday_overrides' => ['present', 'array', 'max:700'],
                'weekday_overrides.*' => ['array:shift_id,weekday,classification'],
                'weekday_overrides.*.shift_id' => ['required', 'uuid'],
                'weekday_overrides.*.weekday' => ['required', 'integer', 'between:1,7'],
                'weekday_overrides.*.classification' => ['required', Rule::in(PhaseSchedulingPolicy::CLASSIFICATIONS)],
                'date_overrides' => ['present', 'array', 'max:10000'],
                'date_overrides.*' => ['array:shift_id,date,classification'],
                'date_overrides.*.shift_id' => ['required', 'uuid'],
                'date_overrides.*.date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$phase->starts_on, 'before_or_equal:'.$phase->ends_on],
                'date_overrides.*.classification' => ['required', Rule::in(PhaseSchedulingPolicy::CLASSIFICATIONS)],
            ];
            foreach (PhaseSchedulingPolicy::HOUR_FIELDS as $field) {
                $rules[$field] = ['required', 'integer', 'between:0,2147483647'];
            }
            $rules['planned_cap_minutes'][] = 'gte:weekly_target_minutes';
            $values = Validator::make($input, $rules)->validate();
            $before = $this->policies->snapshot($phase);
            $revision = $values['expected_revision'] === null ? null : (int) $values['expected_revision'];
            if ($revision !== $before['revision'] || (int) $values['expected_season_revision'] !== $before['season_revision'] || (int) $values['expected_coverage_revision'] !== $before['coverage_revision']) {
                throw new RevisionConflict;
            }
            $shiftIds = array_column($before['baselines'], 'shift_id');
            if (count($values['baselines']) !== count($shiftIds) || array_diff(array_column($values['baselines'], 'shift_id'), $shiftIds)) {
                throw ValidationException::withMessages(['baselines' => 'Classify every current shift in this phase exactly once.']);
            }
            $rows = [];
            $seen = [];
            foreach (['baselines' => 'baseline', 'weekday_overrides' => 'weekday', 'date_overrides' => 'date'] as $field => $scope) {
                foreach ($values[$field] as $index => $row) {
                    $key = $scope === 'baseline' ? '*' : (string) ($scope === 'weekday' ? (int) $row['weekday'] : $row['date']);
                    $identity = $row['shift_id'].':'.$scope.':'.$key;
                    if (! in_array($row['shift_id'], $shiftIds, true) || isset($seen[$identity])) {
                        throw ValidationException::withMessages(["$field.$index" => 'Use a current phase shift and only one classification for each shift and weekday or date.']);
                    }
                    $seen[$identity] = true;
                    $rows[] = ['id' => (string) Str::uuid(), 'phase_id' => $id, 'shift_id' => $row['shift_id'], 'scope' => $scope, 'scope_key' => $key, 'classification' => $row['classification']];
                }
            }
            DB::table('phase_scheduling_policies')->updateOrInsert(['phase_id' => $id], [
                ...collect(PhaseSchedulingPolicy::HOUR_FIELDS)->mapWithKeys(fn ($field) => [$field => (int) $values[$field]])->all(),
                'revision' => ($revision ?? 0) + 1, 'season_revision' => $before['season_revision'], 'coverage_revision' => $before['coverage_revision'],
            ]);
            DB::table('shift_desirabilities')->where('phase_id', $id)->delete();
            foreach (array_chunk($rows, 100) as $chunk) {
                DB::table('shift_desirabilities')->insert($chunk);
            }
            $after = $this->policies->snapshot($phase);
            DB::table('phase_policy_changes')->insert(['id' => (string) Str::uuid(), 'phase_id' => $id, 'actor_id' => $actor->id,
                'revision' => $after['revision'], 'reason' => $values['reason'], 'before' => $revision === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
                'after' => json_encode($after, JSON_THROW_ON_ERROR), 'occurred_at' => now('UTC')]);

            return $after;
        }, 3);
    }
}
