<?php

use App\Models\Phase;
use App\Models\Season;
use App\Services\Seasons\SeasonCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->calendar = new SeasonCalendar;
    $this->season = new Season(['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'timezone' => 'America/Los_Angeles', 'week_starts_on' => 1]);
    $this->season->setRelation('phases', collect([
        new Phase(['id' => 'early', 'starts_on' => '2026-01-01', 'ends_on' => '2026-05-31']),
        new Phase(['id' => 'peak', 'starts_on' => '2026-06-01', 'ends_on' => '2026-12-31']),
    ]));
});

it('requires an offset for repeated wall times and rejects skipped or malformed times', function () {
    foreach (['2026-03-08 02:30:00', '2026-11-01 01:30:00', '2026-02-30 12:00:00', '2026-06-01 25:00:00', 'tomorrow'] as $local) {
        expect(fn () => $this->calendar->resolveLocal($local, $this->season->timezone))->toThrow(ValidationException::class);
    }
    expect($this->calendar->resolveLocal('2026-11-01 01:30:00', $this->season->timezone, '-07:00')->format('H:i'))->toBe('08:30')
        ->and($this->calendar->resolveLocal('2026-11-01 01:30:00', $this->season->timezone, '-08:00')->format('H:i'))->toBe('09:30');
    expect(fn () => $this->calendar->resolveLocal('2026-11-01 01:30:00', $this->season->timezone, '-06:00'))->toThrow(ValidationException::class);
    expect(fn () => $this->calendar->resolveLocal('2026-06-01 12:00:00', 'PST'))->toThrow(ValidationException::class);
});

it('uses elapsed time across spring and autumn DST transitions', function (string $date, int $seconds) {
    $start = $this->calendar->resolveLocal($date.' 00:00:00', $this->season->timezone);
    $end = $this->calendar->resolveLocal($date.' 08:00:00', $this->season->timezone);
    $parts = $this->calendar->allocate($this->season, $start, $end);
    expect(array_sum(array_column($parts, 'elapsed_seconds')))->toBe($seconds);
})->with([['2026-03-08', 7 * 3600], ['2026-11-01', 9 * 3600]]);

it('splits an overnight range exactly once at a shared week and phase boundary', function () {
    $start = $this->calendar->resolveLocal('2026-05-31 22:00:00', $this->season->timezone);
    $end = $this->calendar->resolveLocal('2026-06-01 06:00:00', $this->season->timezone);
    $parts = $this->calendar->allocate($this->season, $start, $end);
    expect(array_column($parts, 'elapsed_seconds'))->toBe([7200, 21600])
        ->and(array_column($parts, 'phase_id'))->toBe(['early', 'peak'])
        ->and(array_column($parts, 'week_starts_on'))->toBe(['2026-05-25', '2026-06-01'])
        ->and($parts[0]['ends_at'])->toBe($parts[1]['starts_at'])
        ->and($parts[0]['starts_at'])->toBe('2026-06-01T05:00:00Z');
});

it('keeps complete local calendar weeks through DST and year boundaries', function () {
    foreach ([['2026-03-08T12:00:00Z', 167], ['2026-11-01T12:00:00Z', 169]] as [$instant, $hours]) {
        $week = $this->calendar->week($this->season, CarbonImmutable::parse($instant));
        expect((int) $week['starts_at']->diffInSeconds($week['ends_at']))->toBe($hours * 3600);
    }
    $this->season->week_starts_on = 7;
    expect($this->calendar->week($this->season, CarbonImmutable::parse('2026-01-01T12:00:00Z'))['starts_on'])->toBe('2025-12-28');
});

it('handles half-hour transitions and midnight gaps or folds without assuming a one-hour change', function () {
    expect(fn () => $this->calendar->resolveLocal('2026-10-04 02:15:00', 'Australia/Lord_Howe'))->toThrow(ValidationException::class);
    $first = $this->calendar->resolveLocal('2026-04-05 01:45:00', 'Australia/Lord_Howe', '+11:00');
    $second = $this->calendar->resolveLocal('2026-04-05 01:45:00', 'Australia/Lord_Howe', '+10:30');
    expect((int) $first->diffInSeconds($second))->toBe(1800);
    expect($this->calendar->dayBoundary('2011-12-30', 'Pacific/Apia')->equalTo($this->calendar->dayBoundary('2011-12-31', 'Pacific/Apia')))->toBeTrue();
    expect($this->calendar->dayBoundary('2026-11-01', 'America/Havana')->format('H:i'))->toBe('04:00');
});

it('retains unconfigured and outside-season intervals without losing or duplicating seconds', function () {
    $this->season->phases[0]->ends_on = '2026-05-30';
    $parts = $this->calendar->allocate($this->season, CarbonImmutable::parse('2026-05-31T05:00:00Z'), CarbonImmutable::parse('2026-06-01T09:00:00Z'));
    expect(array_column($parts, 'phase_id'))->toBe(['early', null, 'peak'])
        ->and(array_sum(array_column($parts, 'elapsed_seconds')))->toBe(28 * 3600);
    $outside = $this->calendar->allocate($this->season, CarbonImmutable::parse('2027-01-01T07:00:00Z'), CarbonImmutable::parse('2027-01-01T09:00:00Z'));
    expect(array_column($outside, 'phase_id'))->toBe(['peak', null]);
    expect(fn () => $this->calendar->allocate($this->season, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-01-01')))->toThrow(ValidationException::class);
});

it('does not depend on the server or device default time zone', function () {
    $original = date_default_timezone_get();
    try {
        date_default_timezone_set('Asia/Tokyo');
        expect($this->calendar->resolveLocal('2026-06-01 00:00:00', 'America/Los_Angeles')->format('Y-m-d H:i:s'))->toBe('2026-06-01 07:00:00');
    } finally {
        date_default_timezone_set($original);
    }
});
