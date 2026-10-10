<?php

namespace App\Orchid\Screens;

use App\Exceptions\RevisionConflict;
use App\Models\Organization;
use App\Models\Season;
use App\Services\Seasons\SaveSeason;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Matrix;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class SeasonScreen extends Screen
{
    public function name(): ?string
    {
        return 'Seasons and phases';
    }

    public function description(): ?string
    {
        return 'Online administration · Dates and weeks use the season time zone.';
    }

    public function query(Organization $organization, string $season): iterable
    {
        Gate::authorize('manage', $organization);
        $record = $season === 'new' ? null : Season::query()->where('organization_id', $organization->id)->with('phases')->findOrFail($season);

        return [
            'organization' => $organization,
            'seasons' => Season::query()->where('organization_id', $organization->id)->with('phases')->orderBy('starts_on')->paginate(15),
            'configuration' => $record ? [...$record->only(['id', 'name', 'starts_on', 'ends_on', 'timezone', 'week_starts_on']),
                'expected_revision' => $record->revision, 'phases' => $record->phases->map(fn ($phase) => $phase->only(['id', 'name', 'starts_on', 'ends_on']))->all()]
                : ['id' => (string) Str::uuid(), 'expected_revision' => null, 'phases' => []],
        ];
    }

    public function commandBar(): iterable
    {
        return [Button::make('Save season and phases')->method('save')->icon('bs.check-circle')];
    }

    public function layout(): iterable
    {
        return [
            Layout::view('admin.seasons'),
            Layout::rows([
                Input::make('configuration.id')->type('hidden'),
                Input::make('configuration.expected_revision')->type('hidden'),
                Input::make('configuration.name')->title('Season name')->required()->maxlength(120),
                Input::make('configuration.starts_on')->title('Season starts on')->type('date')->required(),
                Input::make('configuration.ends_on')->title('Season ends on (inclusive)')->type('date')->required()
                    ->help('Choose at least the day after the season starts.'),
                Select::make('configuration.timezone')->title('Season time zone')
                    ->options(array_combine(\DateTimeZone::listIdentifiers(), \DateTimeZone::listIdentifiers()))
                    ->empty('Choose a time zone')->required(),
                Select::make('configuration.week_starts_on')->title('Week starts on')
                    ->options([1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'])
                    ->empty('Choose a weekday')->required()->help('Weeks begin at local midnight. Overnight intervals are split across weeks and phases using elapsed time.'),
                Matrix::make('configuration.phases')->title('Dated phases')->columns(['Phase name' => 'name', 'Starts on' => 'starts_on', 'Ends on (inclusive)' => 'ends_on', '' => 'id'])
                    ->fields([
                        'name' => Input::make()->set('aria-label', 'Phase name')->required()->maxlength(120),
                        'starts_on' => Input::make()->type('date')->set('aria-label', 'Phase starts on')->required(),
                        'ends_on' => Input::make()->type('date')->set('aria-label', 'Phase ends on')->required(),
                        'id' => Input::make()->type('hidden'),
                    ])->maxRows(100)->addRowLabel('Add phase')
                    ->help('New phases start on the season start date or the day after the preceding phase ends. You can change these dates. Each phase must end at least the day after it starts. Phases cannot overlap or extend outside the season. Dates without a phase remain unconfigured. Saved phases must be retained; you can edit their dates together.'),
                Input::make('configuration.reason')->title('Reason for change')->required()->maxlength(500),
            ]),
        ];
    }

    public function save(Request $request, Organization $organization, string $season, SaveSeason $service): RedirectResponse
    {
        $input = $request->input('configuration', []);
        abort_unless(is_array($input), 422);
        $id = $season === 'new' ? ($input['id'] ?? '') : $season;
        try {
            $record = $service->handle($request->user(), $organization, $id, [
                ...$input, 'expected_revision' => $input['expected_revision'] ?? null, 'phases' => $input['phases'] ?? [],
            ]);
        } catch (RevisionConflict) {
            throw ValidationException::withMessages(['configuration' => 'Another manager saved this season. Your input is retained here. Open the season in another tab to compare before reapplying your changes.']);
        } catch (ValidationException $error) {
            throw ValidationException::withMessages(collect($error->errors())->mapWithKeys(fn ($messages, $field) => ['configuration.'.$field => $messages])->all());
        }
        Toast::success('Season and phases saved.');

        return redirect()->route('platform.seasons', [$organization->id, $record->id]);
    }
}
