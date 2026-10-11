<?php

namespace App\Services\Seasons;

use App\Models\Organization;
use App\Models\Phase;
use App\Models\Season;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PhaseSchedulingPolicy
{
    public const HOUR_FIELDS = ['weekly_target_minutes', 'planned_cap_minutes', 'planned_warning_minutes', 'actual_warning_minutes'];

    public const CLASSIFICATIONS = ['desirable', 'neutral', 'undesirable'];

    public function snapshot(Phase $phase): array
    {
        return DB::transaction(function () use ($phase) {
            $season = Season::findOrFail($phase->season_id);
            Organization::query()->whereKey($season->organization_id)->sharedLock()->firstOrFail();
            $season->refresh();
            $phase->refresh();
            $policy = DB::table('phase_scheduling_policies')->where('phase_id', $phase->id)->first();
            $coverageRevision = DB::table('phase_coverages')->where('phase_id', $phase->id)->value('revision');
            $shifts = DB::table('shift_definitions')->where('phase_id', $phase->id)->orderBy('name')->get(['id', 'name']);
            $rules = DB::table('shift_desirabilities')->where('phase_id', $phase->id)->orderBy('shift_id')->orderBy('scope')->orderBy('scope_key')->get();

            return [
                'phase_id' => $phase->id, 'revision' => $policy?->revision,
                'starts_on' => $phase->starts_on, 'ends_on' => $phase->ends_on,
                'season_revision' => $season->revision, 'coverage_revision' => $coverageRevision,
                'requires_review' => ! $policy || $policy->season_revision !== $season->revision || $policy->coverage_revision !== $coverageRevision,
                ...collect(self::HOUR_FIELDS)->mapWithKeys(fn ($field) => [$field => $policy?->$field])->all(),
                'baselines' => $shifts->map(fn ($shift) => ['shift_id' => $shift->id, 'shift_name' => $shift->name,
                    'classification' => $rules->where('scope', 'baseline')->firstWhere('shift_id', $shift->id)?->classification])->all(),
                'weekday_overrides' => $rules->where('scope', 'weekday')->map(fn ($row) => ['shift_id' => $row->shift_id, 'weekday' => (int) $row->scope_key, 'classification' => $row->classification])->values()->all(),
                'date_overrides' => $rules->where('scope', 'date')->map(fn ($row) => ['shift_id' => $row->shift_id, 'date' => $row->scope_key, 'classification' => $row->classification])->values()->all(),
            ];
        });
    }

    /** Resolve a previously authorized, coherent snapshot for a local shift start date. */
    public function classification(array $snapshot, string $shiftId, string $date): array
    {
        Validator::make(['date' => $date], ['date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$snapshot['starts_on'], 'before_or_equal:'.$snapshot['ends_on']]])->validate();
        if ($snapshot['requires_review']) {
            throw ValidationException::withMessages(['policy' => 'Review and save current phase policies before using them.']);
        }
        $baseline = collect($snapshot['baselines'])->firstWhere('shift_id', $shiftId);
        if (! $baseline || ! $baseline['classification']) {
            throw ValidationException::withMessages(['shift_id' => 'Choose a configured shift in this phase.']);
        }
        $dated = collect($snapshot['date_overrides'])->where('shift_id', $shiftId)->firstWhere('date', $date);
        $weekday = collect($snapshot['weekday_overrides'])->where('shift_id', $shiftId)->firstWhere('weekday', CarbonImmutable::parse($date, 'UTC')->isoWeekday());

        return ['classification' => ($dated ?? $weekday ?? $baseline)['classification'], 'source' => $dated ? 'date' : ($weekday ? 'weekday' : 'baseline')];
    }
}
