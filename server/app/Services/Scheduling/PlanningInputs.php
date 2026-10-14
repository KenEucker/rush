<?php

namespace App\Services\Scheduling;

use App\Models\Event;
use App\Models\OfficialAssignment;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Season;
use App\Models\Unavailability;
use App\Models\User;
use App\Services\Events\EventConfiguration;
use App\Services\Preferences\EffectivePreferences;
use App\Services\Seasons\ConfigurationYear;
use App\Services\Seasons\PhaseSchedulingPolicy;
use App\Services\Seasons\SeasonCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlanningInputs
{
    public function capture(User $actor, Organization $organization, string $seasonId, array $input): array
    {
        return $this->coherent(function () use ($actor, $organization, $seasonId, $input) {
            Gate::forUser($actor)->authorize('manage', $organization);
            $season = Season::where('organization_id', $organization->id)->findOrFail($seasonId);
            app(ConfigurationYear::class)->assertSeasonEditable($season);
            $v = Validator::make($input, [
                'starts_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$season->starts_on, 'before_or_equal:'.$season->ends_on],
                'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on', 'before_or_equal:'.$season->ends_on],
                'minimum_rest_minutes' => ['sometimes', 'required', 'integer', 'between:0,10080'],
                'offsets' => ['sometimes', 'array'], 'offsets.*' => ['required', 'regex:/^[+-]\d{2}:\d{2}$/D'],
                'reason' => ['required', 'string', 'max:500'],
            ])->validate();
            $parameters = ['starts_on' => $v['starts_on'], 'ends_on' => $v['ends_on'],
                'minimum_rest_minutes' => (int) ($v['minimum_rest_minutes'] ?? 480), 'offsets' => $v['offsets'] ?? []];
            ksort($parameters['offsets']);
            $inputs = $this->build($season, $parameters);
            $id = (string) Str::uuid();
            $fingerprint = $this->fingerprint($inputs);
            DB::table('planning_inputs')->insert(['id' => $id, 'organization_id' => $organization->id,
                'season_id' => $season->id, 'actor_id' => $actor->id, 'reason' => $v['reason'],
                'fingerprint' => $fingerprint, 'inputs' => json_encode($inputs, JSON_THROW_ON_ERROR), 'created_at' => now('UTC')]);

            return ['id' => $id, 'fingerprint' => $fingerprint, 'inputs' => $inputs];
        });
    }

    public function read(User $actor, Organization $organization, string $id): array
    {
        return $this->coherent(function () use ($actor, $organization, $id) {
            Gate::forUser($actor)->authorize('manage', $organization);
            $row = DB::table('planning_inputs')->where('organization_id', $organization->id)->where('id', $id)->first();
            abort_unless($row, 404);
            $inputs = json_decode($row->inputs, true, flags: JSON_THROW_ON_ERROR);
            $season = Season::findOrFail($row->season_id);

            return ['id' => $row->id, 'actor_id' => $row->actor_id, 'reason' => $row->reason,
                'created_at' => $row->created_at, 'fingerprint' => $row->fingerprint, 'inputs' => $inputs,
                'stale' => $row->fingerprint !== $this->fingerprint($this->build($season, $inputs['parameters']))];
        });
    }

    private function coherent(callable $callback): mixed
    {
        return DB::transaction(function () use ($callback) {
            // Configuration, availability and membership writers use different locks.
            // MVCC gives all sources one committed database snapshot, including phantoms.
            if (DB::getDriverName() === 'pgsql' && DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }

            return $callback();
        }, 3);
    }

    private function fingerprint(array $inputs): string
    {
        return hash('sha256', json_encode($inputs, JSON_THROW_ON_ERROR));
    }

    private function build(Season $season, array $parameters): array
    {
        $calendar = app(SeasonCalendar::class);
        $policy = app(PhaseSchedulingPolicy::class);
        $season->load('phases');
        $members = OrganizationMembership::where('organization_id', $season->organization_id)->with('user')->orderBy('id')->get();
        $roster = $members->map(fn ($m) => ['id' => $m->id, 'name' => $m->user->name, 'role' => $m->role->value, 'is_active' => $m->is_active])->all();
        $rangers = $members->filter(fn ($m) => $m->is_active && $m->role->value === 'ranger');
        $phases = [];
        foreach ($season->phases as $phase) {
            $phases[$phase->id] = ['id' => $phase->id, 'starts_on' => $phase->starts_on, 'ends_on' => $phase->ends_on,
                'policy' => $policy->snapshot($phase)];
            foreach (['shift_definitions', 'coverage_areas', 'coverage_groups', 'coverage_group_areas', 'coverage_staffing'] as $table) {
                $order = $table === 'coverage_group_areas' ? 'area_id' : 'id';
                $query = DB::table($table)->where('phase_id', $phase->id);
                if ($table === 'coverage_group_areas') {
                    $query->orderBy('group_id');
                }
                $phases[$phase->id][$table] = $query->orderBy($order)->get()->map(fn ($r) => (array) $r)->all();
            }
        }
        $issues = [];
        $opportunities = [];
        $seasonEnd = $calendar->dayBoundary(CarbonImmutable::parse($season->ends_on)->addDay()->toDateString(), $season->timezone);
        $lastEnd = $calendar->dayBoundary(CarbonImmutable::parse($parameters['ends_on'])->addDay()->toDateString(), $season->timezone);
        for ($date = $parameters['starts_on']; $date <= $parameters['ends_on']; $date = CarbonImmutable::parse($date)->addDay()->toDateString()) {
            $phase = collect($phases)->first(fn ($p) => $date >= $p['starts_on'] && $date <= $p['ends_on']);
            if (! $phase) {
                $issues[] = ['code' => 'unconfigured_date', 'date' => $date];

                continue;
            }
            if ($phase['policy']['requires_review']) {
                $issues[] = ['code' => 'policy_requires_review', 'date' => $date, 'phase_id' => $phase['id']];
            }
            foreach ($phase['coverage_staffing'] as $row) {
                $shift = collect($phase['shift_definitions'])->firstWhere('id', $row['shift_id']);
                $key = $shift['id'].':'.$date;
                try {
                    $start = $calendar->resolveLocal($date.' '.$shift['start_time'].':00', $season->timezone, $parameters['offsets'][$key] ?? null);
                    $end = $start->addMinutes($shift['duration_minutes']);
                    if ($end > $seasonEnd) {
                        $issues[] = ['code' => 'shift_outside_season', 'key' => $key];
                    }
                    $lastEnd = max($lastEnd, min($end, $seasonEnd));
                    $classification = $phase['policy']['requires_review'] ? null : $policy->classification($phase['policy'], $shift['id'], $date);
                    $target = collect($phase[$row['model'] === 'dedicated' ? 'coverage_areas' : 'coverage_groups'])
                        ->firstWhere('id', $row['area_id'] ?? $row['group_id']);
                    $opportunities[] = ['id' => $row['id'].':'.$date, 'kind' => 'shift', 'date' => $date, 'phase_id' => $phase['id'],
                        'shift_id' => $shift['id'], 'name' => $shift['name'], 'target_name' => $target['name'], 'coverage' => $row, 'positions' => $row['positions'],
                        'starts_at' => $start->toIso8601ZuluString(), 'ends_at' => $end->toIso8601ZuluString(),
                        'paid' => true, 'classification' => $classification];
                } catch (ValidationException $error) {
                    $issues[] = ['code' => 'invalid_shift_time', 'key' => $key, 'details' => $error->errors()];
                }
            }
        }
        // Keep all organization commitments: cross-season work and rest still constrain a Ranger.
        $commitments = OfficialAssignment::where('organization_id', $season->organization_id)->orderBy('id')->get()
            ->map(fn ($a) => ['id' => $a->id, 'kind' => 'shift', 'membership_id' => $a->organization_membership_id,
                'revision' => $a->revision, 'starts_at' => $a->starts_at->toIso8601ZuluString(), 'ends_at' => $a->ends_at->toIso8601ZuluString(),
                'shift_context' => $a->shift_context, 'paid' => true])->all();
        $events = [];
        foreach (Event::where('organization_id', $season->organization_id)->orderBy('id')->get() as $event) {
            $snapshot = app(EventConfiguration::class)->snapshot($event);
            $snapshot['requires_review'] = $event->season_revision !== $event->season->revision;
            $events[] = $snapshot;
            foreach ($snapshot['attendance'] as $attendance) {
                $commitments[] = ['id' => $attendance['id'], 'kind' => 'event', 'event_id' => $event->id,
                    'membership_id' => $attendance['organization_membership_id'], 'revision' => $event->revision,
                    'starts_at' => $snapshot['starts_at'], 'ends_at' => $snapshot['ends_at'], 'paid' => $event->paid];
            }
            $rangeStart = $calendar->dayBoundary($parameters['starts_on'], $season->timezone);
            $rangeEnd = $calendar->dayBoundary(CarbonImmutable::parse($parameters['ends_on'])->addDay()->toDateString(), $season->timezone);
            if ($event->season_id === $season->id && $event->starts_at < $rangeEnd && $event->ends_at > $rangeStart) {
                $lastEnd = max($lastEnd, $event->ends_at);
                $opportunities[] = ['id' => $event->id, 'event_id' => $event->id, 'kind' => 'event', 'name' => $event->name,
                    'starts_at' => $snapshot['starts_at'], 'ends_at' => $snapshot['ends_at'], 'paid' => $event->paid,
                    'positions' => match ($event->target_type) {
                        'number' => $event->target_value, 'percentage' => (int) ceil($rangers->count() * $event->target_value / 100), default => 0,
                    }, 'counts_scheduled' => $event->counts_scheduled];
                if ($snapshot['requires_review']) {
                    $issues[] = ['code' => 'event_requires_review', 'event_id' => $event->id];
                }
            }
        }
        $weeks = [];
        $preferences = [];
        $firstStart = $calendar->dayBoundary($parameters['starts_on'], $season->timezone);
        foreach ($opportunities as $opportunity) {
            $firstStart = min($firstStart, CarbonImmutable::parse($opportunity['starts_at']));
        }
        $weekDate = $calendar->week($season, $firstStart)['starts_on'];
        while ($calendar->dayBoundary($weekDate, $season->timezone) < $lastEnd) {
            $week = $calendar->week($season, $calendar->dayBoundary($weekDate, $season->timezone));
            $totals = array_fill_keys(PhaseSchedulingPolicy::HOUR_FIELDS, 0);
            $days = [];
            for ($d = 0; $d < 7; $d++) {
                $day = CarbonImmutable::parse($weekDate)->addDays($d)->toDateString();
                $phase = collect($phases)->first(fn ($p) => $day >= $p['starts_on'] && $day <= $p['ends_on']);
                $days[] = ['date' => $day, 'phase_id' => $phase['id'] ?? null];
                foreach ($totals as $field => $total) {
                    // Unknown in-season policy is unknown, never zero or unlimited.
                    if ($day >= $season->starts_on && $day <= $season->ends_on) {
                        $value = $phase['policy'][$field] ?? null;
                        $totals[$field] = $total === null || $value === null || $phase['policy']['requires_review'] ? null : $total + $value;
                    }
                }
            }
            $weeks[$weekDate] = ['starts_at' => $week['starts_at']->toIso8601ZuluString(), 'ends_at' => $week['ends_at']->toIso8601ZuluString(),
                'phase_days' => $days, 'policy_minute_sevenths' => $totals];
            foreach ($rangers as $ranger) {
                $preferences[$ranger->id][$weekDate] = app(EffectivePreferences::class)->forInstant($ranger, $season, $week['starts_at']);
                $weeks[$weekDate]['committed_seconds'][$ranger->id] = app(PlanningHours::class)->seconds(
                    array_values(array_filter($commitments, fn ($c) => $c['membership_id'] === $ranger->id)),
                    $week['starts_at']->toIso8601ZuluString(), $week['ends_at']->toIso8601ZuluString());
            }
            $weekDate = CarbonImmutable::parse($weekDate)->addDays(7)->toDateString();
        }
        $availability = Unavailability::where('organization_id', $season->organization_id)->orderBy('id')->get()
            ->map(fn ($a) => ['id' => $a->id, 'revision' => $a->revision, 'membership_id' => $a->organization_membership_id,
                'starts_at' => $a->starts_at->toIso8601ZuluString(), 'ends_at' => $a->ends_at->toIso8601ZuluString()])->all();

        return ['schema_version' => 1, 'parameters' => $parameters,
            'season' => $season->only(['id', 'organization_id', 'revision', 'starts_on', 'ends_on', 'timezone', 'week_starts_on']),
            'roster' => $roster, 'phases' => array_values($phases), 'weeks' => $weeks, 'preferences' => $preferences,
            'events' => $events, 'commitments' => $commitments, 'unavailability' => $availability,
            'opportunities' => $opportunities, 'issues' => $issues];
    }
}
