<?php

namespace App\Orchid\Screens;

use App\Models\Organization;
use App\Orchid\ManagementYear;
use App\Services\Events\EventConfiguration;
use App\Services\Scheduling\PlanningInputs;
use App\Services\Scheduling\ScheduleDrafts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Fields\Input;
use Orchid\Support\Facades\Layout;

class PlanningInputScreen extends ManagementScreen
{
    public array $offsetKeys = [];

    public function name(): ?string
    {
        return 'Planning inputs and constraints';
    }

    public function description(): ?string
    {
        return 'Online review · Snapshots are inputs, not draft or published assignments.';
    }

    public function query(Organization $organization, string $season): iterable
    {
        $calendar = app(EventConfiguration::class)->season(request()->user(), $organization, $season);
        $record = request()->query('snapshot') ? app(PlanningInputs::class)->read(request()->user(), $organization, request()->query('snapshot')) : null;
        abort_if($record && $record['inputs']['season']['id'] !== $season, 404);
        $this->offsetKeys = array_values(array_unique([...array_keys($record['inputs']['parameters']['offsets'] ?? []),
            ...array_column(array_filter($record['inputs']['issues'] ?? [], fn ($issue) => $issue['code'] === 'invalid_shift_time'), 'key')]));

        return ['planning' => $record, 'season' => $calendar, 'organization' => $organization,
            'drafts' => DB::table('schedule_drafts')->join('planning_inputs', 'planning_inputs.id', '=', 'schedule_drafts.planning_input_id')
                ->where('planning_inputs.season_id', $season)->orderByDesc('schedule_drafts.created_at')->select('schedule_drafts.*')->get(),
            'configuration' => $record ? $record['inputs']['parameters'] : ['starts_on' => $calendar->starts_on, 'ends_on' => $calendar->ends_on, 'minimum_rest_minutes' => 480]];
    }

    public function commandBar(): iterable
    {
        if (app(ManagementYear::class)->context()['readOnly']) {
            return [];
        }

        return [Button::make('Capture planning inputs')->method('capture')->icon('bs.camera')];
    }

    public function layout(): iterable
    {
        return [Layout::rows([
            Input::make('configuration.starts_on')->type('date')->title('First planning date (inclusive)')->required(),
            Input::make('configuration.ends_on')->type('date')->title('Last planning date (inclusive)')->required(),
            Input::make('configuration.minimum_rest_minutes')->type('number')->min(0)->max(10080)->step(1)->title('Minimum rest (minutes)')->required()->help('480 minutes = 8 hours. Recorded with this planning run; no legal default is implied.'),
            ...array_map(fn ($key) => Input::make('configuration.offsets.'.$key)->title('UTC offset for '.$key)->placeholder('-08:00')
                ->help('For a repeated wall time, choose its valid offset. A nonexistent time requires a shift configuration change.'), $this->offsetKeys),
            Input::make('configuration.reason')->title('Reason for snapshot')->required()->maxlength(500),
        ]), Layout::view('admin.planning-inputs')];
    }

    public function capture(Request $request, Organization $organization, string $season, PlanningInputs $service): RedirectResponse
    {
        $input = $request->input('configuration', []);
        abort_unless(is_array($input), 422);
        $record = $service->capture($request->user(), $organization, $season, $input);

        return redirect()->route('platform.planning-inputs', [$organization->id, $season, 'snapshot' => $record['id']]);
    }

    public function generate(Request $request, Organization $organization, string $season, ScheduleDrafts $service): RedirectResponse
    {
        $request->validate(['snapshot_id' => ['required', 'uuid']]);
        $snapshot = app(PlanningInputs::class)->read($request->user(), $organization, (string) $request->input('snapshot_id'));
        abort_unless($snapshot['inputs']['season']['id'] === $season, 404);
        $record = $service->generate($request->user(), $organization, $snapshot['id'], ['reason' => 'Generate proposals from planning snapshot: '.$snapshot['id']]);

        return redirect()->route('platform.drafts', [$organization->id, $season, $record['id']]);
    }
}
