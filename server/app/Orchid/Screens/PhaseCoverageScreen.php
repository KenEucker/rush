<?php

namespace App\Orchid\Screens;

use App\Exceptions\RevisionConflict;
use App\Models\Organization;
use App\Models\Season;
use App\Services\Seasons\PhaseCoverage;
use App\Services\Seasons\SavePhaseCoverage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Matrix;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class PhaseCoverageScreen extends Screen
{
    public function name(): ?string
    {
        return 'Shifts, areas and phase coverage';
    }

    public function description(): ?string
    {
        return 'Online administration · One coverage model for this phase.';
    }

    public function query(Organization $organization, string $phase, PhaseCoverage $coverage): iterable
    {
        $record = $coverage->phase(request()->user(), $organization, $phase);
        $snapshot = $coverage->snapshot($record);

        return ['organization' => $organization, 'phase' => $record, 'season' => Season::findOrFail($record->season_id),
            'configuration' => [...$snapshot, 'expected_revision' => $snapshot['revision'], 'expected_season_revision' => $snapshot['season_revision'],
                'groups' => array_map(fn ($group) => [...$group, 'area_names' => implode(', ', $group['area_names'])], $snapshot['groups'])]];
    }

    public function commandBar(): iterable
    {
        return [Button::make('Save phase coverage')->method('save')->icon('bs.check-circle')];
    }

    public function layout(): iterable
    {
        return [Layout::view('admin.phase-coverage'), Layout::rows([
            Input::make('configuration.expected_revision')->type('hidden'),
            Input::make('configuration.expected_season_revision')->type('hidden'),
            Select::make('configuration.model')->title('Coverage model')->options(['dedicated' => 'Dedicated areas', 'shared' => 'Shared areas'])
                ->empty('Choose one model')->required()->help('Dedicated: a count for each area and shift. Shared: a count for the whole area group and shift. Switching models requires replacing the staffing rows.'),
            Matrix::make('configuration.shifts')->title('Shift definitions')->columns(['Shift name' => 'name', 'Local start' => 'start_time', 'Duration (minutes)' => 'duration_minutes', '' => 'id'])
                ->fields(['name' => Input::make()->set('aria-label', 'Shift name')->required()->maxlength(120),
                    'start_time' => Input::make()->type('time')->set('aria-label', 'Shift local start')->required(),
                    'duration_minutes' => Input::make()->type('number')->min(1)->step(1)->set('aria-label', 'Shift duration in minutes')->required(),
                    'id' => Input::make()->type('hidden')])->maxRows(100)->addRowLabel('Add shift')
                ->help('Starts use the season time zone; duration is elapsed minutes. Overnight shifts continue into the following date. No fixed shift count or eight-hour duration is required.'),
            Matrix::make('configuration.areas')->title('Coverage areas')->columns(['Area name' => 'name', '' => 'id'])
                ->fields(['name' => Input::make()->set('aria-label', 'Area name')->required()->maxlength(120), 'id' => Input::make()->type('hidden')])
                ->maxRows(100)->addRowLabel('Add area')->help('Names must be unique within this phase and cannot contain commas.'),
            Matrix::make('configuration.groups')->title('Shared area groups')->columns(['Group name' => 'name', 'Area names, separated by commas' => 'area_names', '' => 'id'])
                ->fields(['name' => Input::make()->set('aria-label', 'Group name')->required()->maxlength(120),
                    'area_names' => Input::make()->set('aria-label', 'Group area names')->required(), 'id' => Input::make()->type('hidden')])
                ->maxRows(100)->addRowLabel('Add shared group')->help('Shared model only. Enter the exact area names above, separated by commas. Remove groups when switching to dedicated coverage.'),
            Matrix::make('configuration.staffing')->title('Staffing requirements')->columns(['Shift name' => 'shift_name', 'Dedicated area' => 'area_name', 'Shared group' => 'group_name', 'Positions' => 'positions'])
                ->fields(['shift_name' => Input::make()->set('aria-label', 'Staffing shift name')->required(),
                    'area_name' => Input::make()->set('aria-label', 'Staffing dedicated area'),
                    'group_name' => Input::make()->set('aria-label', 'Staffing shared group'),
                    'positions' => Input::make()->type('number')->min(0)->step(1)->set('aria-label', 'Required positions')->required()])
                ->maxRows(10000)->addRowLabel('Add staffing requirement')->help('Use exact names above. Fill only the target for the chosen model. Zero means no required positions; an omitted pair is unconfigured, not inferred coverage. Update references if you rename a shift, area or group.'),
            Input::make('configuration.reason')->title('Reason for coverage change')->required()->maxlength(500),
        ])];
    }

    public function save(Request $request, Organization $organization, string $phase, SavePhaseCoverage $service): RedirectResponse
    {
        $input = $request->input('configuration', []);
        abort_unless(is_array($input), 422);
        foreach (['shifts', 'areas', 'groups', 'staffing'] as $key) {
            $input[$key] ??= [];
        }
        if (is_array($input['groups'])) {
            foreach ($input['groups'] as &$group) {
                if (is_array($group) && is_string($group['area_names'] ?? null)) {
                    $group['area_names'] = array_map('trim', explode(',', $group['area_names']));
                }
            }
            unset($group);
        }
        try {
            $service->handle($request->user(), $organization, $phase, [...$input, 'expected_revision' => $input['expected_revision'] ?? null]);
        } catch (RevisionConflict) {
            throw ValidationException::withMessages(['configuration' => 'Another manager changed this coverage or season. Your input is retained. Open the phase in another tab to compare before reapplying your changes.']);
        } catch (ValidationException $error) {
            throw ValidationException::withMessages(collect($error->errors())->mapWithKeys(fn ($messages, $field) => ['configuration.'.$field => $messages])->all());
        }
        Toast::success('Phase coverage saved.');

        return redirect()->route('platform.phase-coverage', [$organization->id, $phase]);
    }
}
