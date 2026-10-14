<?php

namespace App\Services\Scheduling;

/** Interval demand for the proposal editor; official gap accounting belongs to RUSH-025. */
class DraftDemand
{
    public function summarize(array $inputs, array $proposals): array
    {
        $active = array_column(array_filter($inputs['roster'], fn ($m) => $m['is_active'] && $m['role'] === 'ranger'), 'id');
        $result = [];
        foreach ($inputs['opportunities'] as $opportunity) {
            $work = array_values(array_filter([...$inputs['commitments'], ...$proposals], function ($work) use ($opportunity) {
                if ($opportunity['kind'] === 'event') {
                    return ($work['event_id'] ?? null) === $opportunity['id'] || ($opportunity['counts_scheduled'] && $work['kind'] === 'shift');
                }
                if (($work['opportunity_id'] ?? null) === $opportunity['id']) {
                    return true;
                }
                $context = $work['shift_context'] ?? null;

                return $context && $context['shift_id'] === $opportunity['shift_id']
                    && $context['model'] === $opportunity['coverage']['model']
                    && $context['target_id'] === ($opportunity['coverage']['area_id'] ?? $opportunity['coverage']['group_id']);
            }));
            $cuts = [$opportunity['starts_at'], $opportunity['ends_at']];
            foreach ([...$work, ...$inputs['unavailability']] as $interval) {
                if ($interval['starts_at'] < $opportunity['ends_at'] && $interval['ends_at'] > $opportunity['starts_at']) {
                    $cuts[] = max($interval['starts_at'], $opportunity['starts_at']);
                    $cuts[] = min($interval['ends_at'], $opportunity['ends_at']);
                }
            }
            $cuts = array_values(array_unique($cuts));
            sort($cuts);
            $segments = [];
            foreach (array_slice($cuts, 0, -1) as $index => $from) {
                $to = $cuts[$index + 1];
                $counted = [];
                foreach ($work as $assignment) {
                    if ($assignment['starts_at'] > $from || $assignment['ends_at'] < $to || ! in_array($assignment['membership_id'], $active, true)) {
                        continue;
                    }
                    $blocked = collect($inputs['unavailability'])->contains(fn ($u) => $u['membership_id'] === $assignment['membership_id'] && $u['starts_at'] < $to && $u['ends_at'] > $from);
                    if (! $blocked) {
                        $counted[] = $assignment['membership_id'];
                    }
                }
                $segments[] = ['starts_at' => $from, 'ends_at' => $to, 'counted' => count(array_unique($counted)),
                    'shortfall' => max(0, $opportunity['positions'] - count(array_unique($counted)))];
            }
            $result[$opportunity['id']] = ['opportunity' => $opportunity, 'segments' => $segments,
                'maximum_shortfall' => max(array_column($segments, 'shortfall'))];
        }

        return $result;
    }
}
