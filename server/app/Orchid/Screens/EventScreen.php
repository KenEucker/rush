<?php

namespace App\Orchid\Screens;

use App\Exceptions\RevisionConflict;
use App\Models\Event;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Orchid\ManagementYear;
use App\Services\Events\EventConfiguration;
use App\Services\Events\SaveEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Select;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class EventScreen extends ManagementScreen
{
    public array $rangerOptions = [];

    public function name(): ?string
    {
        return 'One-off events and attendance';
    }

    public function description(): ?string
    {
        return 'Online administration · Individual dated events, without recurrence.';
    }

    public function query(Organization $organization, string $season, string $event): iterable
    {
        $service = app(EventConfiguration::class);
        $calendar = $service->season(request()->user(), $organization, $season);
        $record = $event === 'new' ? null : $service->record(request()->user(), $organization, $season, $event);
        $description = $record ? $service->describe($record) : null;
        $members = OrganizationMembership::query()->where('organization_id', $organization->id)->with('user')->get();
        $this->rangerOptions = $members->filter(fn ($member) => $member->role->value === 'ranger' && $member->is_active)
            ->mapWithKeys(fn ($member) => [$member->id => $member->user->name])->all();
        foreach ($description['attendance'] ?? [] as $attendance) {
            $memberId = $attendance['organization_membership_id'];
            if (! isset($this->rangerOptions[$memberId])) {
                $this->rangerOptions[$memberId] = ($members->firstWhere('id', $memberId)?->user->name ?? 'Former member').' (inactive; remove to save)';
            }
        }

        return ['organization' => $organization, 'season' => $calendar, 'event' => $description,
            'members' => $members->mapWithKeys(fn ($member) => [$member->id => $member->user->name])->all(),
            'events' => Event::query()->where('season_id', $season)->orderBy('starts_at')->orderBy('id')->paginate(15),
            'configuration' => $record ? [
                ...$record->only(['id', 'name', 'paid', 'counts_scheduled', 'target_type', 'target_value']),
                'expected_revision' => $record->revision, 'expected_season_revision' => $calendar->revision,
                'starts_local' => $record->starts_at->setTimezone($calendar->timezone)->format('Y-m-d\TH:i:s'),
                'ends_local' => $record->ends_at->setTimezone($calendar->timezone)->format('Y-m-d\TH:i:s'),
                'starts_offset' => $record->starts_at->setTimezone($calendar->timezone)->format('P'),
                'ends_offset' => $record->ends_at->setTimezone($calendar->timezone)->format('P'),
                'attendance_membership_ids' => array_column($description['attendance'], 'organization_membership_id'),
            ] : ['id' => (string) Str::uuid(), 'expected_revision' => null, 'expected_season_revision' => $calendar->revision,
                'attendance_membership_ids' => [], 'target_type' => 'none', 'counts_scheduled' => 0, 'paid' => 1]];
    }

    public function commandBar(): iterable
    {
        if (app(ManagementYear::class)->context()['readOnly']) {
            return [];
        }

        return [Button::make('Save event and attendance')->method('save')->icon('bs.check-circle')];
    }

    public function layout(): iterable
    {
        if (app(ManagementYear::class)->context()['readOnly'] && request()->route('event') === 'new') {
            return [Layout::view('admin.events')];
        }

        return [Layout::view('admin.events'), Layout::rows([
            Input::make('configuration.id')->type('hidden'),
            Input::make('configuration.expected_revision')->type('hidden'),
            Input::make('configuration.expected_season_revision')->type('hidden'),
            Input::make('configuration.name')->title('Event name')->required()->maxlength(120),
            Input::make('configuration.starts_local')->title('Event starts (season time zone)')->type('datetime-local')->step(1)->required(),
            Input::make('configuration.ends_local')->title('Event ends (season time zone)')->type('datetime-local')->step(1)->required()->help('For an overnight event, choose the following date.'),
            Input::make('configuration.starts_offset')->title('Start UTC offset')->placeholder('-07:00')->help('Optional except when a daylight-saving time occurs twice. Clear or update when changing dates.'),
            Input::make('configuration.ends_offset')->title('End UTC offset')->placeholder('-07:00'),
            Select::make('configuration.paid')->title('Attendance hours')->options([1 => 'Paid attendance', 0 => 'Unpaid attendance'])->required(),
            Select::make('configuration.target_type')->title('Staffing target type')->options(['none' => 'No target', 'number' => 'Number of Rangers', 'percentage' => 'Percentage of active Rangers'])->required(),
            Input::make('configuration.target_value')->title('Staffing target value')->type('number')->min(0)->max(10000)->step(1)->help('Whole number; percentage is 0–100, rounded up to Rangers. Leave empty for no target.'),
            Select::make('configuration.counts_scheduled')->title('Count scheduled shifts')->options([0 => 'Only explicit attendance assignments', 1 => 'Count scheduled Rangers across all areas'])->required(),
            Select::make('configuration.attendance_membership_ids')->title('Assigned attendees')->options($this->rangerOptions)->multiple()
                ->help('Each selected Ranger is assigned the full event. Counting scheduled shifts alone does not create an extra paid attendance assignment.'),
            Input::make('configuration.reason')->title('Reason for event change')->required()->maxlength(500),
        ])];
    }

    public function save(Request $request, Organization $organization, string $season, string $event, SaveEvent $service): RedirectResponse
    {
        $input = $request->input('configuration', []);
        abort_unless(is_array($input), 422);
        foreach (['starts_local', 'ends_local'] as $field) {
            if (isset($input[$field]) && is_string($input[$field]) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/D', $input[$field])) {
                $input[$field] = str_replace('T', ' ', $input[$field]).(strlen($input[$field]) === 16 ? ':00' : '');
            }
        }
        try {
            $record = $service->handle($request->user(), $organization, $season, $event === 'new' ? ($input['id'] ?? '') : $event,
                [...$input, 'expected_revision' => $input['expected_revision'] ?? null, 'attendance_membership_ids' => $input['attendance_membership_ids'] ?? []]);
        } catch (RevisionConflict) {
            throw ValidationException::withMessages(['configuration' => 'Another manager changed this event or season. Your input is retained. Open this event in another tab to compare before reapplying.']);
        } catch (ValidationException $error) {
            throw ValidationException::withMessages(collect($error->errors())->mapWithKeys(fn ($messages, $field) => ['configuration.'.$field => $messages])->all());
        }
        Toast::success('Event and attendance saved. Area coverage is evaluated separately.');

        return redirect()->route('platform.events', [$organization->id, $season, $record->id]);
    }
}
