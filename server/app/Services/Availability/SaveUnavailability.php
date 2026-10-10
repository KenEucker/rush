<?php

namespace App\Services\Availability;

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
            $currentMembership = OrganizationMembership::query()->lockForUpdate()->findOrFail($membership->id);
            Gate::forUser($actor)->authorize('create', [Unavailability::class, $currentMembership]);
            $current = Unavailability::query()->lockForUpdate()->find($id);
            if ($current) {
                Gate::forUser($actor)->authorize('update', $current);
                abort_unless($current->organization_membership_id === $currentMembership->id, 403);
            }
            $validated = Validator::make($input, [
                'starts_at' => ['required', 'date_format:Y-m-d\TH:i:s\Z'],
                'ends_at' => ['required', 'date_format:Y-m-d\TH:i:s\Z', 'after:starts_at'],
            ])->validate();
            if (($current?->revision) !== $revision) {
                throw new RevisionConflict;
            }
            $before = $current ? $current->only(['starts_at', 'ends_at']) : null;
            $record = $current ?? new Unavailability([
                'id' => $id, 'organization_id' => $currentMembership->organization_id,
                'organization_membership_id' => $currentMembership->id,
            ]);
            $record->fill([...$validated, 'revision' => ($revision ?? 0) + 1])->save();
            DB::table('unavailability_changes')->insert([
                'id' => (string) Str::uuid(), 'unavailability_id' => $record->id,
                'actor_id' => $actor->id, 'revision' => $record->revision,
                'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
                'after' => json_encode($record->only(['starts_at', 'ends_at']), JSON_THROW_ON_ERROR),
                'occurred_at' => now('UTC'),
            ]);

            return $record;
        });
    }
}
