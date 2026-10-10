<?php

namespace App\Services\Sync;

use App\Enums\OrganizationRole;
use App\Exceptions\RevisionConflict;
use App\Exceptions\SyncProtocolConflict;
use App\Http\Resources\MemberProfileResource;
use App\Http\Resources\UnavailabilityResource;
use App\Models\MemberProfile;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Unavailability;
use App\Models\User;
use App\Services\Availability\SaveUnavailability;
use App\Services\Identity\UpdateMemberProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PushOperation
{
    public function handle(User $actor, Organization $organization, array $input, ?string $rangerMembership = null): array
    {
        return DB::transaction(function () use ($actor, $organization, $input, $rangerMembership) {
            $membership = OrganizationMembership::query()->where('user_id', $actor->id)
                ->where('organization_id', $organization->id)->lockForUpdate()->first();
            abort_unless($membership?->is_active, 403);
            abort_if($rangerMembership !== null && ($membership->id !== $rangerMembership || $membership->role !== OrganizationRole::Ranger), 403, 'Your Ranger access changed. Contact Management; saved intent is preserved.');
            $command = Validator::make($input, [
                'operation_id' => ['required', 'uuid'],
                'type' => ['required', 'in:member_profile.update,unavailability.save'],
                'record_id' => ['required', 'uuid'],
                'expected_revision' => ($input['type'] ?? null) === 'unavailability.save' ? ['present', 'nullable', 'integer', 'min:1', 'max:2147483646'] : ['required', 'integer', 'min:1', 'max:2147483646'],
                'payload' => ['required', ($input['type'] ?? null) === 'unavailability.save' ? 'array:membership_id,starts_at,ends_at' : 'array:display_name,phone'],
            ])->validate();
            $command['operation_id'] = strtolower($command['operation_id']);
            $command['record_id'] = strtolower($command['record_id']);
            $command['expected_revision'] = $command['expected_revision'] === null ? null : (int) $command['expected_revision'];
            ksort($command['payload']);
            ksort($command);
            $availability = $command['type'] === 'unavailability.save';
            if ($availability) {
                Gate::forUser($actor)->authorize('create', [Unavailability::class, $membership]);
                abort_unless(($command['payload']['membership_id'] ?? null) === $membership->id, 403);
                $existing = Unavailability::query()->find($command['record_id']);
                if ($existing) {
                    Gate::forUser($actor)->authorize('update', $existing);
                    abort_unless($existing->organization_id === $organization->id, 403);
                }
            } else {
                $profile = MemberProfile::query()->where('organization_id', $organization->id)
                    ->findOrFail($command['record_id']);
                Gate::forUser($actor)->authorize('update', $profile);
            }
            $fingerprint = hash('sha256', json_encode($command, JSON_THROW_ON_ERROR));
            $previous = DB::table('sync_operations')->where('membership_id', $membership->id)
                ->where('operation_id', $command['operation_id'])->first();
            if ($previous) {
                if (! hash_equals($previous->fingerprint, $fingerprint)) {
                    throw new SyncProtocolConflict('operation_id_reused', 'This operation ID already belongs to different intent. Use a new operation ID.');
                }

                $result = json_decode($previous->result, true, flags: JSON_THROW_ON_ERROR);
                if (isset($result['error'])) {
                    $result['error']['errors'] = (object) $result['error']['errors'];
                }

                return $result;
            }
            try {
                if ($availability) {
                    $updated = app(SaveUnavailability::class)->handle($actor, $membership, $command['record_id'], $command['expected_revision'], $command['payload']);
                    $result = ['operation_id' => $command['operation_id'], 'status' => 'accepted',
                        'unavailability' => (new UnavailabilityResource($updated))->resolve()];
                } else {
                    $updated = app(UpdateMemberProfile::class)->handle($actor, $profile, [
                        ...$command['payload'], 'expected_revision' => $command['expected_revision'],
                    ]);
                    $result = ['operation_id' => $command['operation_id'], 'status' => 'accepted',
                        'profile' => (new MemberProfileResource($updated))->resolve()];
                }
            } catch (RevisionConflict $exception) {
                $result = ['operation_id' => $command['operation_id'], 'status' => 'conflict',
                    'error' => ['code' => 'revision_conflict', 'message' => $exception->getMessage(), 'errors' => (object) []]];
            } catch (ValidationException $exception) {
                $result = ['operation_id' => $command['operation_id'], 'status' => 'rejected',
                    'error' => ['code' => 'validation_failed', 'message' => 'Check your details and submit a new operation.', 'errors' => (object) $exception->errors()]];
            }
            DB::table('sync_operations')->insert([
                'membership_id' => $membership->id, 'operation_id' => $command['operation_id'],
                'fingerprint' => $fingerprint, 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => now('UTC'),
            ]);

            return $result;
        }, 3);
    }
}
