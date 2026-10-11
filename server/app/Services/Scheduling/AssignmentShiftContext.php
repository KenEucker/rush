<?php

namespace App\Services\Scheduling;

use App\Exceptions\RevisionConflict;
use App\Models\Organization;
use App\Models\Season;
use App\Models\User;
use App\Services\Seasons\PhaseCoverage;
use App\Services\Seasons\SeasonCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Immutable source context; a partial assignment never changes the whole shift. */
class AssignmentShiftContext
{
    public function make(User $actor, Organization $organization, array $input, string $start, string $end): array
    {
        $values = Validator::make($input, [
            'phase_id' => ['required', 'uuid'], 'shift_id' => ['required', 'uuid'],
            'target_id' => ['required', 'uuid'], 'date' => ['required', 'date_format:Y-m-d'],
            'expected_coverage_revision' => ['required', 'integer', 'min:1'],
            'expected_season_revision' => ['required', 'integer', 'min:1'],
            'offset' => ['nullable', 'string', 'regex:/^[+-]\d{2}:\d{2}$/D'],
        ])->validate();
        $phase = app(PhaseCoverage::class)->phase($actor, $organization, $values['phase_id']);
        $season = Season::findOrFail($phase->season_id);
        $configuration = DB::table('phase_coverages')->where('phase_id', $phase->id)->first();
        if (! $configuration || $configuration->revision !== (int) $values['expected_coverage_revision'] || $season->revision !== (int) $values['expected_season_revision']) {
            throw new RevisionConflict;
        }
        $shift = DB::table('shift_definitions')->where('phase_id', $phase->id)->where('id', $values['shift_id'])->first();
        $table = $configuration->model === 'dedicated' ? 'coverage_areas' : 'coverage_groups';
        $target = DB::table($table)->where('phase_id', $phase->id)->where('id', $values['target_id'])->first();
        if (! $shift || ! $target || $values['date'] < $phase->starts_on || $values['date'] > $phase->ends_on) {
            throw ValidationException::withMessages(['shift' => 'Choose a shift, coverage target and start date in this phase.']);
        }
        $wholeStart = app(SeasonCalendar::class)->resolveLocal($values['date'].' '.$shift->start_time.':00', $season->timezone, $values['offset'] ?? null);
        $wholeEnd = $wholeStart->addMinutes($shift->duration_minutes);
        $areas = $configuration->model === 'dedicated' ? [$target->name] : DB::table('coverage_areas')
            ->whereIn('id', DB::table('coverage_group_areas')->where('group_id', $target->id)->select('area_id'))->orderBy('name')->pluck('name')->all();
        $context = ['phase_id' => $phase->id, 'season_id' => $season->id, 'season_revision' => $season->revision,
            'coverage_revision' => $configuration->revision, 'model' => $configuration->model, 'timezone' => $season->timezone,
            'shift_id' => $shift->id, 'shift_name' => $shift->name, 'target_id' => $target->id, 'target_name' => $target->name,
            'area_names' => $areas, 'starts_at' => $wholeStart->format('Y-m-d\TH:i:s\Z'), 'ends_at' => $wholeEnd->format('Y-m-d\TH:i:s\Z')];

        return $this->interval($context, $start, $end);
    }

    public function interval(array $context, string $start, string $end): array
    {
        $from = CarbonImmutable::parse($start);
        $to = CarbonImmutable::parse($end);
        $wholeStart = CarbonImmutable::parse($context['starts_at']);
        $wholeEnd = CarbonImmutable::parse($context['ends_at']);
        if ($from < $wholeStart || $to > $wholeEnd || $to <= $from) {
            throw ValidationException::withMessages(['shift' => 'A partial assignment must be a positive interval inside its whole shift.']);
        }

        return [...$context, 'is_partial' => $from != $wholeStart || $to != $wholeEnd];
    }
}
