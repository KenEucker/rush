<?php

namespace App\Services\Identity;

use App\Exceptions\RevisionConflict;
use App\Models\MemberProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class UpdateMemberProfile
{
    public function handle(User $actor, MemberProfile $profile, array $attributes): MemberProfile
    {
        return DB::transaction(function () use ($actor, $profile, $attributes) {
            // Do not trust a previously loaded model or its revision/ownership.
            $current = MemberProfile::query()->lockForUpdate()->findOrFail($profile->id);
            Gate::forUser($actor)->authorize('update', $current);
            $validated = Validator::make($attributes, [
                'expected_revision' => ['required', 'integer', 'min:1', 'max:2147483646'],
                'display_name' => ['required', 'string', 'max:255', 'regex:/\S/u'],
                'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            ])->validate();

            if ((int) $validated['expected_revision'] !== $current->revision) {
                throw new RevisionConflict;
            }

            $before = $current->only(['display_name', 'phone']);
            $after = array_replace($before, array_intersect_key($validated, $before));
            if ($before === $after) {
                return $current;
            }

            $revision = $current->revision + 1;
            $occurredAt = now('UTC');
            $changed = MemberProfile::query()->whereKey($current->id)->where('revision', $current->revision)
                ->update([...$after, 'revision' => $revision, 'updated_at' => $occurredAt]);
            if ($changed !== 1) {
                throw new RevisionConflict;
            }

            // Append only, in the same transaction. Never log the entire request.
            DB::table('member_profile_changes')->insert([
                'id' => (string) Str::uuid(),
                'member_profile_id' => $current->id,
                'organization_id' => $current->organization_id,
                'actor_id' => $actor->id,
                'revision' => $revision,
                'before' => json_encode($before, JSON_THROW_ON_ERROR),
                'after' => json_encode($after, JSON_THROW_ON_ERROR),
                'occurred_at' => $occurredAt,
            ]);

            return $current->refresh();
        });
    }
}
