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
            Menu::make('Return to RUSH')->url('/')->icon('bs.arrow-left'),
        ];
    }

    public function permissions(): array
    {
        return [];
    }
}
