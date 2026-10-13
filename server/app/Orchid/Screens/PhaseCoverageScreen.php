<?php

namespace App\Orchid\Screens;

use App\Exceptions\RevisionConflict;
use App\Models\Organization;
use App\Models\Season;
use App\Orchid\ManagementYear;
use App\Services\Seasons\PhaseCoverage;
use App\Services\Seasons\SavePhaseCoverage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Matrix;
use Orchid\Screen\Fields\Select;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class PhaseCoverageScreen extends ManagementScreen
{
    public function name(): ?string
    {
        return 'Shifts, areas and phase coverage';
    }

    public function description(): ?string
    {
        return 'Online administration · Dedicated areas and shared groups can be combined in this phase.';
    }

    public function query(Organization $organization, string $phase, PhaseCoverage $coverage): iterable
    {
        $record = $coverage->phase(request()->user(), $organization, $phase);
        $snapshot = $coverage->snapshot($record);

        return ['organization' => $organization, 'phase' => $record, 'season' => Season::findOrFail($record->season_id),
            'configuration' => [...$snapshot, 'expected_revision' => $snapshot['revision'], 'expected_season_revision' => $snapshot['season_revision']]];
    }

    public function commandBar(): iterable
    {
        if (app(ManagementYear::class)->context()['readOnly']) {
            return [];
        }

        return [Button::make('Save phase coverage')->method('save')->icon('bs.check-circle')];
    }

    public function layout(): iterable
    {
        return [Layout::view('admin.phase-coverage'), Layout::rows([
            Input::make('configuration.expected_revision')->type('hidden'),
            Input::make('configuration.expected_season_revision')->type('hidden'),
            Matrix::make('configuration.shifts')->title('Shift definitions')->columns(['Shift name' => 'name', 'Local start' => 'start_time', 'Duration (minutes)' => 'duration_minutes', '' => 'id'])
                ->fields(['name' => Input::make()->set('aria-label', 'Shift name')->required()->maxlength(120),
                    'start_time' => Input::make()->type('time')->set('aria-label', 'Shift local start')->required(),
                    'duration_minutes' => Input::make()->type('number')->min(1)->step(1)->set('aria-label', 'Shift duration in minutes')->required(),
                    'id' => Input::make()->type('hidden')])->maxRows(100)->addRowLabel('Add shift')
                ->help('Starts use the season time zone; duration is elapsed minutes. Overnight shifts continue into the following date. No fixed shift count or eight-hour duration is required.'),
            Matrix::make('configuration.areas')->title('Coverage areas')->columns(['Area name' => 'name', '' => 'id'])
                ->fields(['name' => Input::make()->set('aria-label', 'Area name')->required()->maxlength(120), 'id' => Input::make()->type('hidden')])
                ->maxRows(100)->addRowLabel('Add area')->help('Names must be unique within this phase and cannot contain commas.'),
            Matrix::make('configuration.groups')->title('Shared area groups')->columns(['Group name' => 'name', 'Area names' => 'area_names', '' => 'id'])
                ->fields(['name' => Input::make()->set('aria-label', 'Group name')->required()->maxlength(120),
                    'area_names' => $this->referenceSelect('Group area names')->multiple()->placeholder('Choose areas')->required(), 'id' => Input::make()->type('hidden')])
                ->maxRows(100)->addRowLabel('Add shared group')->help('Choose coverage areas defined above; remove a selected area with its × button. Shared groups can coexist with dedicated staffing requirements.'),
            Matrix::make('configuration.staffing')->title('Staffing requirements')->columns(['Shift name' => 'shift_name', 'Dedicated area' => 'area_name', 'Shared group' => 'group_name', 'Positions' => 'positions'])
                ->fields(['shift_name' => $this->referenceSelect('Staffing shift name')->empty('Choose a shift')->required(),
                    'area_name' => $this->referenceSelect('Staffing dedicated area')->empty('No dedicated area'),
                    'group_name' => $this->referenceSelect('Staffing shared group')->empty('No shared group'),
                    'positions' => Input::make()->type('number')->min(0)->step(1)->set('aria-label', 'Required positions')->required()])
                ->maxRows(10000)->addRowLabel('Add staffing requirement')->help('For each row choose a shift and exactly one dedicated area or shared group from the definitions above. Leave the other target empty. Both types can be used in this phase. Zero means no required positions; an omitted pair is unconfigured, not inferred coverage. Selections marked “not defined” must be replaced or removed after renaming or removing a definition.'),
            Input::make('configuration.reason')->title('Reason for coverage change')->required()->maxlength(500),
        ])];
    }

    private function referenceSelect(string $label): Select
    {
        return Select::make()->set('aria-label', $label)->addBeforeRender(function () {
            // Preserve saved and rejected selections; the form adds current unsaved definitions.
            $values = array_filter((array) $this->get('value'), fn ($value) => is_string($value) && $value !== '');
            $this->options(array_combine($values, $values));
        });
    }

    public function save(Request $request, Organization $organization, string $phase, SavePhaseCoverage $service): RedirectResponse
    {
        $input = $request->input('configuration', []);
        abort_unless(is_array($input), 422);
        foreach (['shifts', 'areas', 'groups', 'staffing'] as $key) {
            $input[$key] ??= [];
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
