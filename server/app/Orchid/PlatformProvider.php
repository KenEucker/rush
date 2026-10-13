<?php

namespace App\Orchid;

use App\Models\Organization;
use Orchid\Platform\OrchidServiceProvider;
use Orchid\Screen\Actions\Menu;

class PlatformProvider extends OrchidServiceProvider
{
    public function menu(): array
    {
        $organizations = Organization::query()
            ->whereHas('memberships', fn ($query) => $query->where('user_id', auth()->id())
                ->where('is_active', true)->where('role', 'management'))
            ->with(['seasons' => fn ($query) => $query->orderByDesc('starts_on')->orderBy('name'), 'seasons.phases'])
            ->orderBy('name')->get();
        $sections = ['seasons' => [], 'coverage' => [], 'policies' => [], 'events' => [], 'unavailability' => []];

        foreach ($organizations as $organization) {
            $sections['seasons'][] = Menu::make('Create season — '.$organization->name)
                ->route('platform.seasons', [$organization->id, 'new']);
            $sections['unavailability'][] = Menu::make($organization->name)
                ->route('platform.unavailability', [$organization->id, 'new']);
            foreach ($organization->seasons as $season) {
                $label = $season->name.' · '.$organization->name;
                $sections['seasons'][] = Menu::make($label)->route('platform.seasons', [$organization->id, $season->id]);
                $sections['events'][] = Menu::make($label)->route('platform.events', [$organization->id, $season->id, 'new']);
                foreach ($season->phases as $phase) {
                    $phaseLabel = $phase->name.' · '.$label;
                    $sections['coverage'][] = Menu::make($phaseLabel)->route('platform.phase-coverage', [$organization->id, $phase->id]);
                    $sections['policies'][] = Menu::make($phaseLabel)->route('platform.phase-policies', [$organization->id, $phase->id]);
                }
                if ($season->phases->isEmpty()) {
                    $sections['coverage'][] = Menu::make('Add phases — '.$label)->route('platform.seasons', [$organization->id, $season->id]);
                    $sections['policies'][] = Menu::make('Add phases — '.$label)->route('platform.seasons', [$organization->id, $season->id]);
                }
            }
            if ($organization->seasons->isEmpty()) {
                foreach (['coverage', 'policies', 'events'] as $section) {
                    $sections[$section][] = Menu::make('Create season — '.$organization->name)
                        ->route('platform.seasons', [$organization->id, 'new']);
                }
            }
        }

        return [
            Menu::make('RUSH Management')->route('platform.main')->icon('bs.house'),
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
