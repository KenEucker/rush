<?php

namespace App\Orchid\Screens;

use App\Models\Organization;
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
        return ['organizations' => Organization::query()->whereHas('memberships', fn ($query) => $query
            ->where('user_id', auth()->id())->where('is_active', true)->where('role', 'management'))->orderBy('name')->get()];
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
