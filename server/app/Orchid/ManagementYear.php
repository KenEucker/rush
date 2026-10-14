<?php

namespace App\Orchid;

use App\Models\Organization;
use App\Models\Phase;
use App\Models\Season;
use App\Models\Unavailability;
use App\Services\Seasons\ConfigurationYear;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class ManagementYear
{
    public function organizations(): Builder
    {
        return Organization::query()->whereHas('memberships', fn ($query) => $query
            ->where('user_id', auth()->id())->where('is_active', true)->where('role', 'management'))->orderBy('name');
    }

    public function context(): array
    {
        if ($context = request()->attributes->get('management_year')) {
            return $context;
        }
        $values = Validator::make(request()->query(), ['year' => ['sometimes', 'integer', 'between:1900,9998']])->validate();
        $organization = request()->route('organization');
        if (is_string($organization)) {
            $organization = Organization::findOrFail($organization);
        }
        if ($organization) {
            Gate::authorize('manage', $organization);
        }
        $timezone = $organization?->timezone ?? $this->organizations()->first()?->timezone ?? config('app.timezone');
        $year = (int) ($values['year'] ?? CarbonImmutable::now($timezone)->year);
        $seasonId = request()->route('season');
        if ($phaseId = request()->route('phase')) {
            $seasonId = Phase::findOrFail($phaseId)->season_id;
        }
        if ($organization && $seasonId && $seasonId !== 'new') {
            $season = Season::where('organization_id', $organization->id)->findOrFail($seasonId);
            $year = (int) substr($season->starts_on, 0, 4);
            $timezone = $season->timezone;
        } elseif ($organization && ($report = request()->route('report')) && $report !== 'new') {
            $record = Unavailability::where('organization_id', $organization->id)->findOrFail($report);
            $year = $record->starts_at->setTimezone($timezone)->year;
        }

        $context = ['year' => $year, 'timezone' => $timezone,
            'readOnly' => app(ConfigurationYear::class)->isPast($year, $timezone)];
        request()->attributes->set('management_year', $context);

        return $context;
    }

    public function scopeSeasons(Builder|Relation $query): Builder|Relation
    {
        $year = $this->context()['year'];

        return $query->whereBetween('starts_on', ["$year-01-01", "$year-12-31"]);
    }

    public function years(): array
    {
        $organizations = $this->organizations()->get()->keyBy('id');
        $organizationIds = $organizations->keys();
        $current = CarbonImmutable::now($this->context()['timezone'])->year;
        $noticeYears = Unavailability::whereIn('organization_id', $organizationIds)->get(['organization_id', 'starts_at'])
            ->map(fn ($notice) => $notice->starts_at->setTimezone($organizations[$notice->organization_id]->timezone)->year);

        return Season::whereIn('organization_id', $organizationIds)->pluck('starts_on')
            ->map(fn ($date) => (int) substr($date, 0, 4))
            ->merge($noticeYears)->merge([$current, min(9998, $current + 1), $this->context()['year']])->unique()->sortDesc()->values()->all();
    }

    public function route(string $name, array $parameters = []): string
    {
        // Record URLs carry their own year. Lists and create forms carry it explicitly,
        // so separate browser tabs never change each other's selected year.
        if ($name === 'platform.main'
            || ($name === 'platform.unavailability' && ($parameters[1] ?? null) === 'new')
            || ($name === 'platform.seasons' && ($parameters[1] ?? null) === 'new')) {
            $parameters['year'] = $this->context()['year'];
        }

        return route($name, $parameters);
    }
}
