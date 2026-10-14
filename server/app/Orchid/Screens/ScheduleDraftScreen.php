<?php

namespace App\Orchid\Screens;

use App\Models\Organization;
use App\Orchid\ManagementYear;
use App\Services\Scheduling\ScheduleDrafts;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Select;
use Orchid\Support\Facades\Layout;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ScheduleDraftScreen extends ManagementScreen
{
    public array $rangers = [];

    public array $opportunities = [];

    public bool $editing = false;

    public function name(): ?string
    {
        return 'Editable schedule draft';
    }

    public function description(): ?string
    {
        return 'Online Management proposals · Not released or published';
    }

    public function query(Organization $organization, string $season, string $draft): iterable
    {
        $record = app(ScheduleDrafts::class)->read(request()->user(), $organization, $draft);
        abort_unless($record['snapshot']['inputs']['season']['id'] === $season, 404);
        $inputs = $record['snapshot']['inputs'];
        $this->rangers = collect($inputs['roster'])->filter(fn ($m) => $m['is_active'] && $m['role'] === 'ranger')->pluck('name', 'id')->all();
        $this->opportunities = collect($inputs['opportunities'])->mapWithKeys(fn ($o) => [$o['id'] => $o['name'].' · '.($o['target_name'] ?? 'Event').' · '.CarbonImmutable::parse($o['starts_at'])->setTimezone($inputs['season']['timezone'])->format('D, M j g:i A')])->all();
        $assignment = request()->query('assignment') ? collect($record['assignments'])->firstWhere('id', request()->query('assignment')) : null;
        abort_if(request()->query('assignment') && ! $assignment, 404);
        $this->editing = $assignment !== null;
        $form = ['expected_revision' => $record['revision'], 'assignment_id' => $assignment['id'] ?? null,
            'membership_id' => $assignment['membership_id'] ?? null, 'opportunity_id' => $assignment['opportunity_id'] ?? null,
            'override_reason' => $assignment['override_reason'] ?? null];
        if ($assignment) {
            foreach (['starts', 'ends'] as $boundary) {
                $instant = CarbonImmutable::parse($assignment[$boundary.'_at'])->setTimezone($inputs['season']['timezone']);
                $form[$boundary.'_local'] = $instant->format('Y-m-d\TH:i:s');
                $form[$boundary.'_offset'] = $instant->format('P');
            }
        }

        return ['draft' => $record, 'organization' => $organization, 'seasonId' => $season, 'configuration' => $form];
    }

    public function commandBar(): iterable
    {
        if (app(ManagementYear::class)->context()['readOnly']) {
            return [];
        }

        return [Button::make('Save assignment')->method('save'),
            Button::make('Remove assignment')->method('remove')->canSee($this->editing)];
    }

    public function layout(): iterable
    {
        return [Layout::view('admin.schedule-draft'), Layout::rows([
            Input::make('configuration.expected_revision')->type('hidden'),
            Input::make('configuration.assignment_id')->type('hidden'),
            Select::make('configuration.opportunity_id')->title('Shift or event')->options($this->opportunities)->required(),
            Select::make('configuration.membership_id')->title('Assigned Ranger')->options($this->rangers)->required(),
            Input::make('configuration.starts_local')->type('datetime-local')->step(1)->title('Assignment starts (season time zone)')->help('Leave both dates empty for the whole shift or event. Partial shifts must remain inside their original interval.'),
            Input::make('configuration.ends_local')->type('datetime-local')->step(1)->title('Assignment ends (season time zone)')->help('Use the following date for overnight work. Events retain their whole interval.'),
            Input::make('configuration.starts_offset')->title('Start UTC offset')->help('Required for repeated DST times; clear or update when changing dates.'),
            Input::make('configuration.ends_offset')->title('End UTC offset'),
            Input::make('configuration.override_reason')->title('Explicit conflict override reason')->maxlength(500)->help('Required on each assignment with availability, overlap, rest or cap conflicts. Conflicts remain visible.'),
            Input::make('configuration.reason')->title('Reason for draft change')->required()->maxlength(500),
        ])];
    }

    public function save(Request $request, Organization $organization, string $season, string $draft): RedirectResponse
    {
        return $this->change($request, $organization, $season, $draft, false);
    }

    public function remove(Request $request, Organization $organization, string $season, string $draft): RedirectResponse
    {
        return $this->change($request, $organization, $season, $draft, true);
    }

    private function change(Request $request, Organization $organization, string $season, string $draft, bool $remove): RedirectResponse
    {
        $service = app(ScheduleDrafts::class);
        $record = $service->read($request->user(), $organization, $draft);
        abort_unless($record['snapshot']['inputs']['season']['id'] === $season, 404);
        $input = $request->input('configuration', []);
        abort_unless(is_array($input), 422);
        foreach (['starts_local', 'ends_local'] as $field) {
            if (isset($input[$field]) && is_string($input[$field]) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/D', $input[$field])) {
                $input[$field] = str_replace('T', ' ', $input[$field]).(strlen($input[$field]) === 16 ? ':00' : '');
            }
        }
        try {
            $service->edit($request->user(), $organization, $draft, [...$input, 'remove' => $remove ? 1 : 0], $remove);
        } catch (ValidationException $error) {
            throw ValidationException::withMessages(collect($error->errors())->mapWithKeys(fn ($messages, $field) => ['configuration.'.$field => $messages])->all());
        } catch (HttpException $error) {
            if ($error->getStatusCode() !== 409) {
                throw $error;
            }
            throw ValidationException::withMessages(['configuration' => $error->getMessage().' Your input is retained. Open the saved draft in another tab to compare.']);
        }

        return redirect()->route('platform.drafts', [$organization->id, $season, $draft]);
    }
}
