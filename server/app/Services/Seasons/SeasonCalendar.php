<?php

namespace App\Services\Seasons;

use App\Models\Season;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;

/** Calendar projections only: never changes assignments, activity or payable hours. */
class SeasonCalendar
{
    public function resolveLocal(string $local, string $timezone, ?string $offset = null): CarbonImmutable
    {
        $candidates = $this->candidates($local, $timezone);
        if ($candidates === []) {
            throw ValidationException::withMessages(['local_time' => 'This local time does not exist in the season time zone.']);
        }
        if ($offset === null && count($candidates) > 1) {
            throw ValidationException::withMessages(['offset' => 'This local time occurs twice. Choose its UTC offset.']);
        }
        foreach ($candidates as $candidate) {
            if ($offset === null || $candidate->setTimezone($timezone)->format('P') === $offset) {
                return $candidate;
            }
        }
        throw ValidationException::withMessages(['offset' => 'The UTC offset does not match this local time and time zone.']);
    }

    /** The first instant of the local date; a skipped date has zero length. */
    public function dayBoundary(string $date, string $timezone): CarbonImmutable
    {
        $local = $date.' 00:00:00';
        $candidates = $this->candidates($local, $timezone);
        if ($candidates !== []) {
            return $candidates[0];
        }
        $wall = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $local, 'UTC')->getTimestamp();
        $transitions = (new DateTimeZone($timezone))->getTransitions($wall - 172800, $wall + 172800);
        $previous = $transitions[0]['offset'];
        foreach ($transitions as $transition) {
            if ($transition['offset'] > $previous && $wall >= $transition['ts'] + $previous && $wall < $transition['ts'] + $transition['offset']) {
                return CarbonImmutable::createFromTimestampUTC($transition['ts']);
            }
            $previous = $transition['offset'];
        }
        throw ValidationException::withMessages(['date' => 'Unable to resolve the calendar boundary.']);
    }

    /** @return array{starts_on: string, starts_at: CarbonImmutable, ends_at: CarbonImmutable} */
    public function week(Season $season, CarbonImmutable $instant): array
    {
        $date = CarbonImmutable::parse($instant->setTimezone($season->timezone)->format('Y-m-d'), 'UTC');
        $date = $date->subDays(($date->dayOfWeekIso - $season->week_starts_on + 7) % 7);

        return ['starts_on' => $date->format('Y-m-d'),
            'starts_at' => $this->dayBoundary($date->format('Y-m-d'), $season->timezone),
            'ends_at' => $this->dayBoundary($date->addDays(7)->format('Y-m-d'), $season->timezone)];
    }

    /**
     * Split [start, end) at season, phase and local week boundaries.
     * Null phase IDs explicitly represent unconfigured dates, including outside the season.
     *
     * @return list<array{starts_at: string, ends_at: string, week_starts_on: string, phase_id: ?string, elapsed_seconds: int}>
     */
    public function allocate(Season $season, CarbonImmutable $start, CarbonImmutable $end): array
    {
        if ($end <= $start) {
            throw ValidationException::withMessages(['ends_at' => 'The end must be after the start.']);
        }
        $phases = $season->phases;
        $cuts = [$start->getTimestamp(), $end->getTimestamp()];
        foreach ([$season, ...$phases] as $range) {
            $cuts[] = $this->dayBoundary($range->starts_on, $season->timezone)->getTimestamp();
            $nextDate = CarbonImmutable::parse($range->ends_on, 'UTC')->addDay()->format('Y-m-d');
            $cuts[] = $this->dayBoundary($nextDate, $season->timezone)->getTimestamp();
        }
        $weekDate = CarbonImmutable::parse($this->week($season, $start)['starts_on'], 'UTC')->addDays(7);
        while (($boundary = $this->dayBoundary($weekDate->format('Y-m-d'), $season->timezone)) < $end) {
            $cuts[] = $boundary->getTimestamp();
            $weekDate = $weekDate->addDays(7);
        }
        $cuts = array_values(array_unique(array_filter($cuts, fn ($cut) => $cut >= $start->getTimestamp() && $cut <= $end->getTimestamp())));
        sort($cuts);
        $result = [];
        foreach (array_slice($cuts, 0, -1) as $index => $cut) {
            $from = CarbonImmutable::createFromTimestampUTC($cut);
            $date = $from->setTimezone($season->timezone)->format('Y-m-d');
            $phase = $phases->first(fn ($phase) => $date >= $season->starts_on && $date <= $season->ends_on && $date >= $phase->starts_on && $date <= $phase->ends_on);
            $result[] = [
                'starts_at' => $from->format('Y-m-d\TH:i:s\Z'),
                'ends_at' => CarbonImmutable::createFromTimestampUTC($cuts[$index + 1])->format('Y-m-d\TH:i:s\Z'),
                'week_starts_on' => $this->week($season, $from)['starts_on'], 'phase_id' => $phase?->id,
                'elapsed_seconds' => $cuts[$index + 1] - $cut,
            ];
        }

        return $result;
    }

    /** @return list<CarbonImmutable> */
    private function candidates(string $local, string $timezone): array
    {
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true) ||
            ! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $local)) {
            throw ValidationException::withMessages(['local_time' => 'Use a valid IANA time zone and YYYY-MM-DD HH:MM:SS.']);
        }
        try {
            $wall = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $local, 'UTC');
        } catch (\Throwable) {
            throw ValidationException::withMessages(['local_time' => 'Invalid local date or time.']);
        }
        if ($wall->format('Y-m-d H:i:s') !== $local) {
            throw ValidationException::withMessages(['local_time' => 'Invalid local date or time.']);
        }
        $zone = new DateTimeZone($timezone);
        $transitions = $zone->getTransitions($wall->getTimestamp() - 172800, $wall->getTimestamp() + 172800);
        $candidates = [];
        foreach (array_unique(array_column($transitions, 'offset')) as $seconds) {
            $candidate = $wall->subSeconds($seconds);
            if ($candidate->setTimezone($zone)->format('Y-m-d H:i:s') === $local) {
                $candidates[] = $candidate;
            }
        }
        usort($candidates, fn ($a, $b) => $a->getTimestamp() <=> $b->getTimestamp());

        return $candidates;
    }
}
