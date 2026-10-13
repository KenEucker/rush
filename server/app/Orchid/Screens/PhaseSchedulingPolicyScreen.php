<?php

namespace App\Orchid\Screens;

use App\Exceptions\RevisionConflict;
use App\Models\Organization;
use App\Models\Season;
use App\Orchid\ManagementYear;
use App\Services\Seasons\PhaseCoverage;
use App\Services\Seasons\PhaseSchedulingPolicy;
use App\Services\Seasons\SavePhaseSchedulingPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Matrix;
use Orchid\Screen\Fields\Select;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class PhaseSchedulingPolicyScreen extends ManagementScreen
{
    public array $shiftOptions = [];

    public function name(): ?string
    {
        return 'Phase scheduling policies';
    }

    public function description(): ?string
    {
        return 'Online administration · Weekly hour inputs and shift desirability.';
    }

    public function query(Organization $organization, string $phase, PhaseCoverage $coverage, PhaseSchedulingPolicy $policies): iterable
    {
        $record = $coverage->phase(request()->user(), $organization, $phase);
        $snapshot = $policies->snapshot($record);
        $this->shiftOptions = array_column($snapshot['baselines'], 'shift_name', 'shift_id');

        return ['organization' => $organization, 'phase' => $record, 'season' => Season::findOrFail($record->season_id),
            'configuration' => [...$snapshot, 'expected_revision' => $snapshot['revision'],
                'expected_season_revision' => $snapshot['season_revision'], 'expected_coverage_revision' => $snapshot['coverage_revision']]];
    }

    public function commandBar(): iterable
    {
        if (app(ManagementYear::class)->context()['readOnly']) {
            return [];
        }

        return [Button::make('Save scheduling policies')->method('save')->icon('bs.check-circle')];
    }

    public function layout(): iterable
    {
        $classes = array_combine(PhaseSchedulingPolicy::CLASSIFICATIONS, array_map('ucfirst', PhaseSchedulingPolicy::CLASSIFICATIONS));
        $fields = [
            Input::make('configuration.expected_revision')->type('hidden'),
            Input::make('configuration.expected_season_revision')->type('hidden'),
            Input::make('configuration.expected_coverage_revision')->type('hidden'),
        ];
        foreach (['weekly_target_minutes' => 'Weekly scheduling target (minutes)', 'planned_cap_minutes' => 'Weekly planned cap (minutes)',
            'planned_warning_minutes' => 'Planned hours warning threshold (minutes)', 'actual_warning_minutes' => 'Actual hours warning threshold (minutes)'] as $key => $label) {
            $fields[] = Input::make('configuration.'.$key)->type('number')->title($label)->min(0)->max(2147483647)->step(1)->required()
                ->help('Enter whole minutes: 24 hours = 1440, 32 hours = 1920. Zero is an explicit value; no legal default is assumed.');
        }
        $fields[] = Matrix::make('configuration.baselines')->title('Shift baselines')->columns(['Shift' => 'shift_id', 'Classification' => 'classification'])
            ->fields(['shift_id' => Select::make()->options($this->shiftOptions)->set('aria-label', 'Baseline shift')->required(),
                'classification' => Select::make()->options($classes)->empty('Choose classification')->set('aria-label', 'Baseline classification')->required()])
            ->maxRows(100)->addRowLabel('Add baseline')->help('Classify every configured shift exactly once. These are Management baselines, separate from Ranger preferences.');
        $fields[] = Matrix::make('configuration.weekday_overrides')->title('Weekday overrides')->columns(['Shift' => 'shift_id', 'Weekday' => 'weekday', 'Classification' => 'classification'])
            ->fields(['shift_id' => Select::make()->options($this->shiftOptions)->set('aria-label', 'Weekday shift')->required(),
                'weekday' => Select::make()->options([1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'])->set('aria-label', 'Override weekday')->required(),
                'classification' => Select::make()->options($classes)->set('aria-label', 'Weekday classification')->required()])
            ->maxRows(700)->addRowLabel('Add weekday override');
        $fields[] = Matrix::make('configuration.date_overrides')->title('Dated overrides')->columns(['Shift' => 'shift_id', 'Date' => 'date', 'Classification' => 'classification'])
            ->fields(['shift_id' => Select::make()->options($this->shiftOptions)->set('aria-label', 'Dated shift')->required(),
                'date' => Input::make()->type('date')->set('aria-label', 'Override date')->required(),
                'classification' => Select::make()->options($classes)->set('aria-label', 'Dated classification')->required()])
            ->maxRows(10000)->addRowLabel('Add dated override')->help('Use the shift start date in the season time zone. Date overrides beat weekday overrides, then the baseline. Remove an override to restore the less specific rule.');
        $fields[] = Input::make('configuration.reason')->title('Reason for policy change')->required()->maxlength(500);

        return [Layout::view('admin.phase-policies'), Layout::rows($fields)];
    }

    public function save(Request $request, Organization $organization, string $phase, SavePhaseSchedulingPolicy $service): RedirectResponse
    {
        $input = $request->input('configuration', []);
        abort_unless(is_array($input), 422);
        foreach (['baselines', 'weekday_overrides', 'date_overrides'] as $field) {
            $input[$field] ??= [];
        }
        try {
            $service->handle($request->user(), $organization, $phase, [...$input, 'expected_revision' => $input['expected_revision'] ?? null]);
        } catch (RevisionConflict) {
            throw ValidationException::withMessages(['configuration' => 'Another manager changed these policies, coverage or season. Your input is retained. Open the phase in another tab to compare before reapplying changes.']);
        } catch (ValidationException $error) {
            throw ValidationException::withMessages(collect($error->errors())->mapWithKeys(fn ($messages, $field) => ['configuration.'.$field => $messages])->all());
        }
        Toast::success('Scheduling policies saved.');

        return redirect()->route('platform.phase-policies', [$organization->id, $phase]);
    }
}
