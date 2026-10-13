<?php

namespace App\Orchid;

use Orchid\Platform\OrchidServiceProvider;
use Orchid\Screen\Actions\Menu;

class PlatformProvider extends OrchidServiceProvider
{
    public function menu(): array
    {
        return [
            Menu::make('RUSH Management')->route('platform.main')->icon('bs.house'),
            Menu::make('Events and attendance')->url(route('platform.main').'#events')->icon('bs.calendar-event'),
            Menu::make('Return to RUSH')->url('/')->icon('bs.arrow-left'),
        ];
    }

    public function permissions(): array
    {
        return [];
    }
}
