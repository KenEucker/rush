<?php

namespace App\Orchid\Screens;

use Orchid\Screen\Actions\Button;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;

class PlatformScreen extends Screen
{
    public function name(): ?string
    {
        return 'RUSH Management';
    }

    public function query(): iterable
    {
        return [];
    }

    public function layout(): iterable
    {
        return [Layout::view('admin.home')];
    }

    public function commandBar(): iterable
    {
        return [Button::make('Sign out')->route('platform.logout')->rawClick()];
    }
}
