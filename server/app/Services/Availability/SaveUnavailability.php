<?php

namespace App\Services\Availability;

use App\Enums\OrganizationRole;
use App\Exceptions\RevisionConflict;
use App\Models\OrganizationMembership;
use App\Models\Unavailability;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class SaveUnavailability
{
    public function handle(User $actor, OrganizationMembership $membership, string $id, ?int $revision, array $input): Unavailability
    {
        return DB::transaction(function () use ($actor, $membership, $id, $revision, $input) {
            $actorMembership = OrganizationMembership::query()->where('organization_id', $membership->organization_id)
                ->where('user_id', $actor->id)->lockForUpdate()->first();
            Gate::forUser($actor)->authorize('view', $membership->organization);
            $currentMembership = OrganizationMembership::query()->lockForUpdate()->findOrFail($membership->id);
            $management = $actorMembership->role === OrganizationRole::Management;
            if ($management) {
                Gate::forUser($actor)->authorize('manage', $currentMembership->organization);
                abort_unless($currentMembership->is_active && $currentMembership->role === OrganizationRole::Ranger, 403);
            } else {
                Gate::forUser($actor)->authorize('create', [Unavailability::class, $currentMembership]);
            }
            $current = Unavailability::query()->lockForUpdate()->find($id);
            if ($current) {
                if (! $management) {
                    Gate::forUser($actor)->authorize('update', $current);
                }
                abort_unless($current->organization_membership_id === $currentMembership->id, 403);
            }
            $validated = Validator::make([...$input, 'id' => $id, 'expected_revision' => $revision], [
                'id' => ['required', 'uuid'],
                'expected_revision' => ['nullable', 'integer', 'min:1', 'max:2147483646'],
                'starts_at' => ['required', 'date_format:Y-m-d\TH:i:s\Z'],
                'ends_at' => ['required', 'date_format:Y-m-d\TH:i:s\Z', 'after:starts_at'],
                'reason' => $management ? ['required', 'string', 'max:500'] : ['sometimes', 'nullable', 'string', 'max:500'],
            ])->validate();
            if (($current?->revision) !== $revision) {
                throw new RevisionConflict;
            }
            $before = $current ? $current->only(['starts_at', 'ends_at']) : null;
            $record = $current ?? new Unavailability([
                'id' => $id, 'organization_id' => $currentMembership->organization_id,
                'organization_membership_id' => $currentMembership->id,
            ]);
            $record->fill(['starts_at' => $validated['starts_at'], 'ends_at' => $validated['ends_at'], 'revision' => ($revision ?? 0) + 1])->save();
            DB::table('unavailability_changes')->insert([
                'id' => (string) Str::uuid(), 'unavailability_id' => $record->id,
                'actor_id' => $actor->id, 'revision' => $record->revision,
                'reason' => $management ? $validated['reason'] : null,
                'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
                'after' => json_encode($record->only(['starts_at', 'ends_at']), JSON_THROW_ON_ERROR),
                'occurred_at' => now('UTC'),
            ]);

            return $record;
        }, 3);
    }
}
