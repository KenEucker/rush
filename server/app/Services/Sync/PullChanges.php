<?php

namespace App\Services\Sync;

use App\Enums\OrganizationRole;
use App\Exceptions\SyncProtocolConflict;
use App\Http\Resources\MemberProfileResource;
use App\Http\Resources\UnavailabilityResource;
use App\Models\MemberProfile;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Unavailability;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use JsonException;

class PullChanges
{
    public function handle(User $actor, Organization $organization, array $input): array
    {
        return DB::transaction(function () use ($actor, $organization, $input) {
            $membership = OrganizationMembership::query()->where('user_id', $actor->id)
                ->where('organization_id', $organization->id)->lockForUpdate()->first();
            abort_unless($membership?->is_active, 403);
            $validated = Validator::make($input, [
                'checkpoint' => ['sometimes', 'nullable', 'string', 'max:4096'],
                'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            ])->validate();
            $streams = DB::table('sync_streams')->where('membership_id', $membership->id);
            $stream = (clone $streams)->first();
            $profiles = MemberProfile::query()->where('organization_id', $organization->id)
                ->when($membership->role !== OrganizationRole::Management,
                    fn ($query) => $query->where('organization_membership_id', $membership->id))
                ->orderBy('id')->get()->filter(fn ($profile) => Gate::forUser($actor)->allows('view', $profile));

            // A role change invalidates the old stream before any historic data is returned.
            if ($stream && $stream->role !== $membership->role->value) {
                $streams->delete();
                $stream = null;
            }
            if (! $stream) {
                $streams->insert(['membership_id' => $membership->id, 'generation' => (string) Str::uuid(),
                    'role' => $membership->role->value, 'sequence' => 0]);
                $stream = (clone $streams)->first();
            }
            $availability = Unavailability::query()->where('organization_id', $organization->id)
                ->where('organization_membership_id', $membership->id)->orderBy('id')->get()
                ->filter(fn ($record) => Gate::forUser($actor)->allows('view', $record));
            $visible = [
                'member_profile' => $profiles->mapWithKeys(fn ($record) => [$record->id => (new MemberProfileResource($record))->resolve()]),
                'unavailability' => $availability->mapWithKeys(fn ($record) => [$record->id => (new UnavailabilityResource($record))->resolve()]),
            ];
            $sequence = (int) $stream->sequence;
            foreach (['member_profile' => 'sync_profiles', 'unavailability' => 'sync_unavailabilities'] as $type => $table) {
                $stored = DB::table($table)->where('membership_id', $membership->id)->get()->keyBy('record_id');
                foreach ($visible[$type] as $id => $value) {
                    $previous = $stored->pull($id);
                    if ($previous && json_decode($previous->value, true, flags: JSON_THROW_ON_ERROR) === $value) {
                        continue;
                    }
                    $json = json_encode($value, JSON_THROW_ON_ERROR);
                    DB::table($table)->updateOrInsert(['membership_id' => $membership->id, 'record_id' => $id], ['value' => $json]);
                    DB::table('sync_changes')->insert(['membership_id' => $membership->id, 'sequence' => ++$sequence, 'record_type' => $type, 'record_id' => $id, 'value' => $json]);
                }
                foreach ($stored as $removed) {
                    DB::table($table)->where('membership_id', $membership->id)->where('record_id', $removed->record_id)->delete();
                    DB::table('sync_changes')->insert(['membership_id' => $membership->id, 'sequence' => ++$sequence, 'record_type' => $type, 'record_id' => $removed->record_id, 'value' => null]);
                }
            }
            $streams->update(['sequence' => $sequence]);

            $cursor = 0;
            $upper = $sequence;
            if ($validated['checkpoint'] ?? null) {
                try {
                    $token = json_decode(Crypt::decryptString($validated['checkpoint']), true, flags: JSON_THROW_ON_ERROR);
                } catch (DecryptException|JsonException) {
                    $token = null;
                }
                if (! is_array($token) || ($token['version'] ?? null) !== 1
                    || ($token['membership'] ?? null) !== $membership->id
                    || ($token['generation'] ?? null) !== $stream->generation
                    || ! is_int($token['cursor'] ?? null) || ! is_int($token['upper'] ?? null)
                    || $token['cursor'] < 0 || $token['cursor'] > $token['upper'] || $token['upper'] > $sequence) {
                    throw new SyncProtocolConflict('checkpoint_invalid', 'Clear confirmed sync data and restart pull without a checkpoint. Preserve pending intent.');
                }
                $cursor = $token['cursor'];
                $upper = $cursor === $token['upper'] ? $sequence : $token['upper'];
            }
            $rows = DB::table('sync_changes')->where('membership_id', $membership->id)
                ->where('sequence', '>', $cursor)->where('sequence', '<=', $upper)
                ->orderBy('sequence')->limit((int) ($validated['limit'] ?? 100))->get();
            $cursor = $rows->isEmpty() ? $upper : (int) $rows->last()->sequence;
            $changes = $rows->map(function ($row) use ($visible) {
                // Recheck current visibility even when resuming a historic page.
                $value = $row->value !== null && isset($visible[$row->record_type][$row->record_id])
                    ? json_decode($row->value, true, flags: JSON_THROW_ON_ERROR) : null;

                return ['sequence' => (int) $row->sequence, 'record_type' => $row->record_type,
                    'record_id' => $row->record_id, 'value' => $value];
            })->all();

            return ['changes' => $changes, 'checkpoint' => Crypt::encryptString(json_encode([
                'version' => 1, 'membership' => $membership->id, 'generation' => $stream->generation,
                'cursor' => $cursor, 'upper' => $upper,
            ], JSON_THROW_ON_ERROR)), 'has_more' => $cursor < $upper];
        }, 3);
    }
}
