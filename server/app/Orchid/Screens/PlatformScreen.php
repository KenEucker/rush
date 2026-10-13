<?php

namespace App\Orchid\Screens;

use App\Models\Organization;
use App\Models\Unavailability;
use App\Orchid\ManagementYear;
use Carbon\CarbonImmutable;
use Orchid\Screen\Actions\Button;
use Orchid\Support\Facades\Layout;

class PlatformScreen extends ManagementScreen
{
    public function name(): ?string
    {
        return 'RUSH Management';
    }

    public function query(): iterable
    {
        $year = app(ManagementYear::class);

        return ['organizations' => Organization::query()->whereHas('memberships', fn ($query) => $query
            ->where('user_id', auth()->id())->where('is_active', true)->where('role', 'management'))
            ->with(['seasons' => fn ($query) => $year->scopeSeasons($query)->orderByDesc('starts_on')->orderBy('name')])->orderBy('name')->get()
            ->each(function ($organization) use ($year) {
                $start = CarbonImmutable::create($year->context()['year'], 1, 1, 0, 0, 0, $organization->timezone);
                $organization->unavailability_notice_count = Unavailability::query()->where('organization_id', $organization->id)
                    ->where('starts_at', '>=', $start->utc())->where('starts_at', '<', $start->addYear()->utc())
                    ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('unavailability_acknowledgments')
                        ->whereColumn('unavailability_id', 'unavailabilities.id')->whereColumn('unavailability_acknowledgments.revision', 'unavailabilities.revision'))->count();
            })];
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
