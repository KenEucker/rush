<?php

namespace App\Orchid\Screens;

use App\Enums\OrganizationRole;
use App\Exceptions\RevisionConflict;
use App\Http\Resources\UnavailabilityResource;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Orchid\ManagementYear;
use App\Services\Availability\ManageUnavailability;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Select;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class UnavailabilityScreen extends ManagementScreen
{
    public array $rangers = [];

    public function name(): ?string
    {
        return 'Unavailability notices';
    }

    public function description(): ?string
    {
        return 'Online Management review · Acknowledgment means seen, not repaired.';
    }

    public function query(Organization $organization, string $report, ManageUnavailability $service): iterable
    {
        $records = $service->reports(request()->user(), $organization);
        $this->rangers = OrganizationMembership::query()->where('organization_id', $organization->id)
            ->where('role', OrganizationRole::Ranger)->where('is_active', true)->with('user')->get()
            ->mapWithKeys(fn ($membership) => [$membership->id => $membership->user->name])->all();
        $current = $report === 'new' ? null : $records->firstWhere('id', $report);
        abort_if($report !== 'new' && ! $current, 404);
        $year = app(ManagementYear::class)->context()['year'];
        $records = $records->filter(fn ($record) => $record->starts_at->setTimezone($organization->timezone)->year === $year);

        return ['organization' => $organization,
            'reports' => ($current ? collect([$current]) : $records)->map(fn ($record) => (new UnavailabilityResource($record))->resolve()),
            'rangerNames' => OrganizationMembership::query()->where('organization_id', $organization->id)->with('user')->get()
                ->mapWithKeys(fn ($membership) => [$membership->id => $membership->user->name])->all(),
            'reportId' => $current?->id,
            'entry' => ['id' => $current?->id ?? (string) Str::uuid(), 'membership_id' => $current?->organization_membership_id,
                'expected_revision' => $current?->revision,
                'starts_at' => $current?->starts_at->utc()->format('Y-m-d\TH:i:s\Z'),
                'ends_at' => $current?->ends_at->utc()->format('Y-m-d\TH:i:s\Z')]];
    }

    public function commandBar(): iterable
    {
        if (app(ManagementYear::class)->context()['readOnly']) {
            return [];
        }

        return [Button::make('Save on behalf of Ranger')->method('save')->icon('bs.check-circle')];
    }

    public function layout(): iterable
    {
        if (app(ManagementYear::class)->context()['readOnly']) {
            return [Layout::view('admin.unavailability')];
        }

        return [Layout::view('admin.unavailability'), Layout::rows([
            Input::make('entry.id')->type('hidden'),
            Input::make('entry.expected_revision')->type('hidden'),
            Select::make('entry.membership_id')->title('Ranger')->options($this->rangers)->empty('Choose Ranger')->required(),
            Input::make('entry.starts_at')->title('Start (UTC)')->placeholder('2026-11-01T07:00:00Z')->required()
                ->help('Exact UTC instant, including seconds and Z. For example, 2026-11-01T07:00:00Z.'),
            Input::make('entry.ends_at')->title('End (UTC)')->placeholder('2026-11-01T12:00:00Z')->required(),
            Input::make('entry.reason')->title('Reason for Management change')->required()->maxlength(500),
        ])];
    }

    public function save(Request $request, Organization $organization, string $report, ManageUnavailability $service)
    {
        $input = $request->input('entry', []);
        abort_unless(is_array($input), 422);
        try {
            $record = $service->save($request->user(), $organization, $report === 'new' ? (string) ($input['id'] ?? '') : $report,
                [...$input, 'expected_revision' => $input['expected_revision'] ?? null]);
        } catch (RevisionConflict) {
            throw ValidationException::withMessages(['entry' => 'This range changed. Your input is retained. Open the report in another tab to compare before reapplying.']);
        } catch (ValidationException $error) {
            throw ValidationException::withMessages(collect($error->errors())->mapWithKeys(fn ($messages, $field) => ['entry.'.$field => $messages])->all());
        }
        Toast::success('Unavailability saved. Review and acknowledge the new revision separately.');

        return redirect()->route('platform.unavailability', [$organization->id, $record->id]);
    }

    public function acknowledge(Request $request, Organization $organization, string $report, ManageUnavailability $service)
    {
        try {
            $service->acknowledge($request->user(), $organization, (string) $request->input('notice_id'),
                ['expected_revision' => $request->input('notice_revision')]);
        } catch (RevisionConflict) {
            throw ValidationException::withMessages(['notice' => 'This notice changed. Reload and review the latest range before acknowledging.']);
        }
        Toast::success('Acknowledged as seen. Assignments and coverage still need separate review.');

        return redirect()->route('platform.unavailability', [$organization->id, $report]);
    }
}
