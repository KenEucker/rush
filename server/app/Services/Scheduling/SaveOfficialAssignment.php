<?php

namespace App\Services\Scheduling;

use App\Enums\OrganizationRole;
use App\Exceptions\RevisionConflict;
use App\Models\OfficialAssignment;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Manual interval with optional whole-shift context; publication remains a later slice. */
class SaveOfficialAssignment
{
    public function handle(User $actor, Organization $organization, string $id, array $input): OfficialAssignment
    {
        return DB::transaction(function () use ($actor, $organization, $id, $input) {
            $manager = OrganizationMembership::query()->where('organization_id', $organization->id)
                ->where('user_id', $actor->id)->lockForUpdate()->first();
            abort_unless($manager?->is_active && $manager->role === OrganizationRole::Management, 403);
            Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $values = Validator::make([...$input, 'id' => $id], [
                'id' => ['required', 'uuid'], 'membership_id' => ['required', 'uuid'],
                'expected_revision' => ['present', 'nullable', 'integer', 'min:1', 'max:2147483646'],
                'starts_at' => ['required', 'date_format:Y-m-d\TH:i:s\Z'],
                'ends_at' => ['required', 'date_format:Y-m-d\TH:i:s\Z', 'after:starts_at'],
                'reason' => ['required', 'string', 'max:500'],
                'shift' => ['sometimes', 'array:phase_id,shift_id,target_id,date,offset,expected_coverage_revision,expected_season_revision'],
            ])->validate();
            $ranger = OrganizationMembership::query()->whereKey($values['membership_id'])
                ->where('organization_id', $organization->id)->lockForUpdate()->first();
            abort_unless($ranger?->is_active && $ranger->role === OrganizationRole::Ranger, 403);
            $current = OfficialAssignment::query()->lockForUpdate()->find($id);
            abort_if($current && $current->organization_id !== $organization->id, 403);
            $revision = $values['expected_revision'] === null ? null : (int) $values['expected_revision'];
            if ($current?->revision !== $revision) {
                throw new RevisionConflict;
            }
            $fields = ['organization_membership_id', 'starts_at', 'ends_at', 'shift_context'];
            $before = $current?->only($fields);
            $context = isset($values['shift'])
                ? app(AssignmentShiftContext::class)->make($actor, $organization, $values['shift'], $values['starts_at'], $values['ends_at'])
                : ($current?->shift_context ? app(AssignmentShiftContext::class)->interval($current->shift_context, $values['starts_at'], $values['ends_at']) : null);
            $record = $current ?? new OfficialAssignment(['id' => $id, 'organization_id' => $organization->id]);
            $record->fill(['organization_membership_id' => $ranger->id,
                'starts_at' => $values['starts_at'], 'ends_at' => $values['ends_at'],
                'shift_context' => $context,
                'revision' => ($revision ?? 0) + 1])->save();
            DB::table('official_assignment_changes')->insert([
                'id' => (string) Str::uuid(), 'official_assignment_id' => $record->id,
                'actor_id' => $actor->id, 'revision' => $record->revision, 'reason' => $values['reason'],
                'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
                'after' => json_encode($record->only($fields), JSON_THROW_ON_ERROR), 'occurred_at' => now('UTC'),
            ]);

            return $record;
        }, 3);
    }
}
