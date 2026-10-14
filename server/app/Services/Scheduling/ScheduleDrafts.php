<?php

namespace App\Services\Scheduling;

use App\Exceptions\RevisionConflict;
use App\Models\Organization;
use App\Models\Season;
use App\Models\User;
use App\Services\Seasons\ConfigurationYear;
use App\Services\Seasons\SeasonCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ScheduleDrafts
{
    public function generate(User $actor, Organization $organization, string $snapshotId, array $input): array
    {
        return $this->transaction(function () use ($actor, $organization, $snapshotId, $input) {
            Gate::forUser($actor)->authorize('manage', $organization);
            $v = Validator::make($input, ['reason' => ['required', 'string', 'max:500']])->validate();
            $snapshot = app(PlanningInputs::class)->read($actor, $organization, $snapshotId);
            $this->editable($snapshot);
            $inputs = $snapshot['inputs'];
            $candidates = [];
            $proposals = [];
            $opportunities = $inputs['opportunities'];
            usort($opportunities, fn ($a, $b) => [$a['kind'] === 'event', $a['starts_at'], $a['id']] <=> [$b['kind'] === 'event', $b['starts_at'], $b['id']]);
            $rangers = array_filter($inputs['roster'], fn ($m) => $m['is_active'] && $m['role'] === 'ranger');
            usort($rangers, fn ($a, $b) => $a['id'] <=> $b['id']);
            foreach ($opportunities as $opportunity) {
                foreach ($rangers as $ranger) {
                    $demand = app(DraftDemand::class)->summarize([...$inputs, 'opportunities' => [$opportunity]], $proposals)[$opportunity['id']];
                    if ($demand['maximum_shortfall'] === 0) {
                        break;
                    }
                    if (count($candidates) === 1000) {
                        throw ValidationException::withMessages(['snapshot' => 'This draft exceeds 1,000 assignments. Capture a smaller planning range.']);
                    }
                    $candidate = ['membership_id' => $ranger['id'], 'opportunity_id' => $opportunity['id']];
                    $evaluation = app(PlanningConstraints::class)->evaluate($inputs, ['candidates' => [...$candidates, $candidate]])['candidates'];
                    if (collect($evaluation)->every(fn ($e) => $e['automatic_eligible'])) {
                        $candidates[] = $candidate;
                        $proposals[] = [...$opportunity, ...$candidate];
                    }
                }
            }
            $id = (string) Str::uuid();
            DB::table('schedule_drafts')->insert(['id' => $id, 'organization_id' => $organization->id,
                'planning_input_id' => $snapshotId, 'revision' => 1, 'state' => 'draft', 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            $this->persist($id, $inputs, $candidates);
            $this->audit($id, $actor, 1, $v['reason'], null);

            return $this->read($actor, $organization, $id);
        });
    }

    public function read(User $actor, Organization $organization, string $id): array
    {
        return $this->transaction(fn () => $this->describe($actor, $organization, $id));
    }

    private function describe(User $actor, Organization $organization, string $id): array
    {
        Gate::forUser($actor)->authorize('manage', $organization);
        $draft = DB::table('schedule_drafts')->where('organization_id', $organization->id)->where('id', $id)->first();
        abort_unless($draft, 404);
        $snapshot = app(PlanningInputs::class)->read($actor, $organization, $draft->planning_input_id);
        $assignments = $this->assignments($id);

        return ['id' => $id, 'revision' => $draft->revision, 'state' => $draft->state,
            'snapshot' => $snapshot, 'assignments' => $assignments,
            'demand' => array_values(app(DraftDemand::class)->summarize($snapshot['inputs'], array_map(fn ($a) => [...$a['context'], ...$a], $assignments)))];
    }

    public function save(User $actor, Organization $organization, string $id, array $input): array
    {
        return $this->transaction(function () use ($actor, $organization, $id, $input) {
            Gate::forUser($actor)->authorize('manage', $organization);
            $draft = DB::table('schedule_drafts')->where('organization_id', $organization->id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($draft, 404);
            $v = Validator::make($input, ['expected_revision' => ['required', 'integer', 'min:1', 'max:2147483646'],
                'reason' => ['required', 'string', 'max:500'], 'candidates' => ['present', 'array', 'list', 'max:1000']])->validate();
            if ($draft->revision !== (int) $v['expected_revision'] || $draft->state !== 'draft') {
                throw new RevisionConflict;
            }
            $snapshot = app(PlanningInputs::class)->read($actor, $organization, $draft->planning_input_id);
            $this->editable($snapshot);
            $before = $this->assignments($id);
            $this->persist($id, $snapshot['inputs'], $v['candidates']);
            DB::table('schedule_drafts')->where('id', $id)->update(['revision' => $draft->revision + 1, 'updated_at' => now('UTC')]);
            $this->audit($id, $actor, $draft->revision + 1, $v['reason'], $before);

            return $this->read($actor, $organization, $id);
        });
    }

    private function editable(array $snapshot): void
    {
        app(ConfigurationYear::class)->assertSeasonEditable(Season::findOrFail($snapshot['inputs']['season']['id']));
        abort_if($snapshot['stale'], 409, 'Planning sources have changed. Capture fresh inputs and generate a new draft; this draft is preserved.');
    }

    public function edit(User $actor, Organization $organization, string $id, array $input, bool $remove = false): array
    {
        $record = $this->read($actor, $organization, $id);
        $v = Validator::make($input, [
            'expected_revision' => ['required', 'integer', 'min:1', 'max:2147483646'],
            'reason' => ['required', 'string', 'max:500'],
            'assignment_id' => ['nullable', 'uuid'], 'membership_id' => [$remove ? 'nullable' : 'required', 'uuid'],
            'opportunity_id' => [$remove ? 'nullable' : 'required', 'string'],
            'starts_local' => ['nullable', 'required_with:ends_local', 'date_format:Y-m-d H:i:s'],
            'ends_local' => ['nullable', 'required_with:starts_local', 'date_format:Y-m-d H:i:s'],
            'starts_offset' => ['nullable', 'regex:/^[+-]\d{2}:\d{2}$/D'], 'ends_offset' => ['nullable', 'regex:/^[+-]\d{2}:\d{2}$/D'],
            'override_reason' => ['nullable', 'string', 'max:500'],
        ])->validate();
        if ($record['revision'] !== (int) $v['expected_revision']) {
            throw new RevisionConflict;
        }
        $selected = $v['assignment_id'] ?? null;
        abort_if(($selected && ! collect($record['assignments'])->contains('id', $selected)) || ($remove && ! $selected), 404);
        $candidates = [];
        foreach ($record['assignments'] as $assignment) {
            if ($assignment['id'] !== $selected) {
                $candidates[] = array_intersect_key($assignment, array_flip(['membership_id', 'opportunity_id', 'starts_at', 'ends_at', 'override_reason']));
            }
        }
        if (! $remove) {
            $candidate = ['membership_id' => $v['membership_id'], 'opportunity_id' => $v['opportunity_id'], 'override_reason' => $v['override_reason'] ?? null];
            if (! empty($v['starts_local']) && ! empty($v['ends_local'])) {
                foreach (['starts', 'ends'] as $boundary) {
                    $candidate[$boundary.'_at'] = app(SeasonCalendar::class)->resolveLocal($v[$boundary.'_local'], $record['snapshot']['inputs']['season']['timezone'], $v[$boundary.'_offset'] ?? null)->toIso8601ZuluString();
                }
            }
            $candidates[] = $candidate;
        }

        return $this->save($actor, $organization, $id, ['expected_revision' => $input['expected_revision'] ?? null,
            'reason' => $input['reason'] ?? null, 'candidates' => $candidates]);
    }

    private function persist(string $id, array $inputs, array $candidates): void
    {
        $evaluation = app(PlanningConstraints::class)->evaluate($inputs, ['candidates' => $candidates])['candidates'];
        foreach ($evaluation as $index => $result) {
            if (! $result['automatic_eligible'] && ! $result['explicit_override_eligible']) {
                $codes = [...$result['hard'], ...array_column($result['overridable'], 'code')];
                throw ValidationException::withMessages(['candidates.'.$index => 'Assignment requires review: '.implode(', ', array_unique($codes)).'. Overridable conflicts require an explicit reason.']);
            }
        }
        $existing = collect($this->assignments($id))->keyBy(fn ($a) => $a['membership_id'].':'.$a['opportunity_id']);
        DB::table('draft_assignments')->where('draft_id', $id)->delete();
        foreach ($evaluation as $result) {
            $candidate = $result['candidate'];
            $opportunity = collect($inputs['opportunities'])->firstWhere('id', $candidate['opportunity_id']);
            DB::table('draft_assignments')->insert(['id' => $existing->get($candidate['membership_id'].':'.$candidate['opportunity_id'])['id'] ?? (string) Str::uuid(),
                'draft_id' => $id, 'organization_id' => $inputs['season']['organization_id'], 'membership_id' => $candidate['membership_id'], 'opportunity_id' => $candidate['opportunity_id'],
                'starts_at' => $candidate['starts_at'] ?? $opportunity['starts_at'], 'ends_at' => $candidate['ends_at'] ?? $opportunity['ends_at'],
                'context' => json_encode($opportunity, JSON_THROW_ON_ERROR), 'override_reason' => $candidate['override_reason'] ?? null,
                'evaluation' => json_encode($result, JSON_THROW_ON_ERROR)]);
        }
    }

    private function assignments(string $id): array
    {
        return DB::table('draft_assignments')->where('draft_id', $id)->orderBy('starts_at')->orderBy('opportunity_id')->orderBy('membership_id')->get()
            ->map(fn ($a) => ['id' => $a->id, 'membership_id' => $a->membership_id, 'opportunity_id' => $a->opportunity_id,
                'starts_at' => CarbonImmutable::parse($a->starts_at)->toIso8601ZuluString(), 'ends_at' => CarbonImmutable::parse($a->ends_at)->toIso8601ZuluString(),
                'override_reason' => $a->override_reason, 'context' => json_decode($a->context, true, flags: JSON_THROW_ON_ERROR),
                'evaluation' => json_decode($a->evaluation, true, flags: JSON_THROW_ON_ERROR)])->all();
    }

    private function audit(string $id, User $actor, int $revision, string $reason, ?array $before): void
    {
        DB::table('draft_changes')->insert(['id' => (string) Str::uuid(), 'draft_id' => $id, 'actor_id' => $actor->id,
            'revision' => $revision, 'reason' => $reason, 'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after' => json_encode($this->assignments($id), JSON_THROW_ON_ERROR), 'occurred_at' => now('UTC')]);
    }

    private function transaction(callable $callback): mixed
    {
        return DB::transaction(function () use ($callback) {
            if (DB::getDriverName() === 'pgsql' && DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }

            return $callback();
        }, 3);
    }
}
