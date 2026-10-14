<?php

namespace App\Services\Scheduling;

use App\Services\Seasons\SeasonCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

/** Pure hypothetical evaluation. It cannot assign, approve, publish or change a snapshot. */
class PlanningConstraints
{
    public function evaluate(array $inputs, array $input): array
    {
        $v = Validator::make($input, [
            'candidates' => ['present', 'array', 'list', 'max:1000'],
            'candidates.*' => ['required', 'array:membership_id,opportunity_id,override_reason,starts_at,ends_at'],
            'candidates.*.membership_id' => ['required', 'uuid'],
            'candidates.*.opportunity_id' => ['required', 'string'],
            'candidates.*.override_reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            'candidates.*.starts_at' => ['required_with:candidates.*.ends_at', 'date_format:Y-m-d\TH:i:s\Z'],
            'candidates.*.ends_at' => ['required_with:candidates.*.starts_at', 'date_format:Y-m-d\TH:i:s\Z'],
        ])->validate();
        $proposals = [];
        $results = [];
        $seen = [];
        foreach ($v['candidates'] as $index => $candidate) {
            $person = collect($inputs['roster'])->firstWhere('id', $candidate['membership_id']);
            $opportunity = collect($inputs['opportunities'])->firstWhere('id', $candidate['opportunity_id']);
            $hard = [];
            if (! $person || ! $person['is_active'] || $person['role'] !== 'ranger') {
                $hard[] = 'ineligible_member';
            }
            if (! $opportunity) {
                $hard[] = 'unknown_opportunity';
            }
            if ($opportunity && isset($candidate['starts_at'], $candidate['ends_at'])) {
                if ($candidate['starts_at'] < $opportunity['starts_at'] || $candidate['ends_at'] > $opportunity['ends_at']
                    || $candidate['ends_at'] <= $candidate['starts_at']
                    || ($opportunity['kind'] === 'event' && ($candidate['starts_at'] !== $opportunity['starts_at'] || $candidate['ends_at'] !== $opportunity['ends_at']))) {
                    $hard[] = 'invalid_partial_interval';
                }
                $opportunity = [...$opportunity, 'starts_at' => $candidate['starts_at'], 'ends_at' => $candidate['ends_at']];
            }
            $key = $candidate['membership_id'].':'.$candidate['opportunity_id'];
            if (isset($seen[$key])) {
                $hard[] = 'duplicate_candidate';
            }
            $seen[$key] = true;
            if ($opportunity && $opportunity['kind'] === 'event' && collect($inputs['commitments'])->contains(fn ($c) => $c['membership_id'] === $candidate['membership_id'] && ($c['event_id'] ?? null) === $opportunity['id'])) {
                $hard[] = 'already_committed';
            }
            if ($inputs['issues'] !== []) {
                $hard[] = 'incomplete_configuration';
            }
            if ($opportunity && $opportunity['kind'] === 'shift' && $opportunity['ends_at'] >
                app(SeasonCalendar::class)->dayBoundary(CarbonImmutable::parse($inputs['season']['ends_on'])->addDay()->toDateString(), $inputs['season']['timezone'])->toIso8601ZuluString()) {
                $hard[] = 'outside_season';
            }
            $results[$index] = ['candidate' => $candidate, 'hard' => $hard, 'overridable' => [], 'warnings' => [], 'preferences' => []];
            if ($hard === []) {
                $proposals[$index] = [...$opportunity, 'membership_id' => $candidate['membership_id']];
            }
        }
        foreach ($proposals as $index => $candidate) {
            $overridable = [];
            $start = CarbonImmutable::parse($candidate['starts_at'])->timestamp;
            $end = CarbonImmutable::parse($candidate['ends_at'])->timestamp;
            foreach ($inputs['unavailability'] as $range) {
                if ($range['membership_id'] === $candidate['membership_id'] && $this->overlaps($candidate, $range)) {
                    $overridable[] = ['code' => 'unavailable', 'source_id' => $range['id']];
                }
            }
            $others = $inputs['commitments'];
            foreach ($proposals as $otherIndex => $other) {
                if ($otherIndex !== $index) {
                    $others[] = $other;
                }
            }
            foreach ($others as $other) {
                if ($other['membership_id'] !== $candidate['membership_id']) {
                    continue;
                }
                $from = CarbonImmutable::parse($other['starts_at'])->timestamp;
                $to = CarbonImmutable::parse($other['ends_at'])->timestamp;
                if ($start < $to && $end > $from) {
                    $overridable[] = ['code' => 'overlapping_work', 'source_id' => $other['id']];
                } elseif (max($start - $to, $from - $end) < $inputs['parameters']['minimum_rest_minutes'] * 60) {
                    $overridable[] = ['code' => 'minimum_rest', 'source_id' => $other['id']];
                }
            }
            $work = array_values(array_filter([...$inputs['commitments'], ...array_values($proposals)], fn ($c) => $c['membership_id'] === $candidate['membership_id']));
            foreach ($inputs['weeks'] as $weekDate => $week) {
                if (! $this->overlaps($candidate, $week)) {
                    continue;
                }
                $seconds = app(PlanningHours::class)->seconds($work, $week['starts_at'], $week['ends_at']);
                $cap = $week['policy_minute_sevenths']['planned_cap_minutes'];
                if ($cap === null) {
                    $results[$index]['hard'][] = 'unknown_weekly_cap';
                } elseif ($seconds * 7 > $cap * 60) {
                    $overridable[] = ['code' => 'weekly_cap', 'week' => $weekDate, 'seconds' => $seconds, 'cap_minute_sevenths' => $cap];
                }
                $warning = $week['policy_minute_sevenths']['planned_warning_minutes'];
                if ($warning !== null && $seconds * 7 > $warning * 60) {
                    $results[$index]['warnings'][] = ['code' => 'planned_hours_warning', 'week' => $weekDate, 'seconds' => $seconds];
                }
                $preference = $inputs['preferences'][$candidate['membership_id']][$weekDate];
                $results[$index]['preferences'][$weekDate] = [...$preference,
                    'positive_shift_match' => in_array($candidate['shift_id'] ?? '', $preference['preferred_shift_ids'], true),
                    'classification' => $candidate['classification'] ?? null];
            }
            $results[$index]['overridable'] = $overridable;
        }
        foreach ($results as &$result) {
            $result['automatic_eligible'] = $result['hard'] === [] && $result['overridable'] === [];
            $result['explicit_override_eligible'] = $result['hard'] === [] && trim($result['candidate']['override_reason'] ?? '') !== '';
        }

        return ['hypothetical' => true, 'candidates' => array_values($results),
            'precedence' => ['hard', 'overridable', 'coverage_and_event_targets', 'weekly_fairness', 'season_fairness', 'positive_preferences']];
    }

    private function overlaps(array $a, array $b): bool
    {
        return $a['starts_at'] < $b['ends_at'] && $a['ends_at'] > $b['starts_at'];
    }
}
