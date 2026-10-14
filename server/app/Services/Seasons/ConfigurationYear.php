<?php

namespace App\Services\Seasons;

use App\Models\Season;
use Carbon\CarbonImmutable;

class ConfigurationYear
{
    public function isPast(int $year, string $timezone): bool
    {
        return $year < CarbonImmutable::now($timezone)->year;
    }

    public function seasonIsPast(Season $season): bool
    {
        return $this->isPast((int) substr($season->starts_on, 0, 4), $season->timezone);
    }

    public function assertSeasonEditable(Season $season): void
    {
        abort_if($this->seasonIsPast($season), 403, 'Past-year seasons are read-only.');
    }

    public function assertDateEditable(string $date, string $timezone): void
    {
        abort_if($this->isPast((int) substr($date, 0, 4), $timezone), 403, 'Past years are read-only.');
    }
}
