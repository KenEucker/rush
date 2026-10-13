<?php

namespace App\Orchid;

use Orchid\Platform\OrchidServiceProvider;
use Orchid\Screen\Actions\Menu;

class PlatformProvider extends OrchidServiceProvider
{
    public function menu(): array
    {
        // Rebuild the scoped menu when another request reuses the application.
        $this->orchid->menu = collect();
        $year = app(ManagementYear::class);
        $readOnly = $year->context()['readOnly'];
        $organizations = $year->organizations()
            ->with(['seasons' => fn ($query) => $year->scopeSeasons($query)->orderBy('starts_on')->orderBy('name'), 'seasons.phases'])->get();
        $sections = ['seasons' => [], 'coverage' => [], 'policies' => [], 'events' => [], 'unavailability' => []];

        foreach ($organizations as $organization) {
            if (! $readOnly) {
                $sections['seasons'][] = Menu::make('Create season — '.$organization->name)
                    ->url($year->route('platform.seasons', [$organization->id, 'new']));
            }
            $sections['unavailability'][] = Menu::make($organization->name)
                ->url($year->route('platform.unavailability', [$organization->id, 'new']));
            foreach ($organization->seasons as $season) {
                $label = $season->name.' · '.$organization->name;
                $sections['seasons'][] = Menu::make($label)->route('platform.seasons', [$organization->id, $season->id]);
                $sections['events'][] = Menu::make($label)->route('platform.events', [$organization->id, $season->id, 'new']);
                foreach ($season->phases as $phase) {
                    $phaseLabel = $phase->name.' · '.$label;
                    $sections['coverage'][] = Menu::make($phaseLabel)->route('platform.phase-coverage', [$organization->id, $phase->id]);
                    $sections['policies'][] = Menu::make($phaseLabel)->route('platform.phase-policies', [$organization->id, $phase->id]);
                }
                if (! $readOnly && $season->phases->isEmpty()) {
                    $sections['coverage'][] = Menu::make('Add phases — '.$label)->route('platform.seasons', [$organization->id, $season->id]);
                    $sections['policies'][] = Menu::make('Add phases — '.$label)->route('platform.seasons', [$organization->id, $season->id]);
                }
            }
            if (! $readOnly && $organization->seasons->isEmpty()) {
                foreach (['coverage', 'policies', 'events'] as $section) {
                    $sections[$section][] = Menu::make('Create season — '.$organization->name)
                        ->url($year->route('platform.seasons', [$organization->id, 'new']));
                }
            }
        }

        return [
            Menu::make('RUSH Management')->url($year->route('platform.main'))->icon('bs.house'),
            Menu::make('Seasons and phases')->icon('bs.calendar3')->list($sections['seasons']),
            Menu::make('Shifts and coverage')->icon('bs.people')->list($sections['coverage']),
            Menu::make('Scheduling policies')->icon('bs.sliders')->list($sections['policies']),
            Menu::make('Events and attendance')->icon('bs.calendar-event')->list($sections['events']),
            Menu::make('Unavailability notices')->icon('bs.calendar-x')->list($sections['unavailability']),
            Menu::make('Return to RUSH')->url('/')->icon('bs.arrow-left'),
        ];
    }

    public function permissions(): array
    {
        return [];
    }
}
