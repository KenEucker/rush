<?php

namespace App\Services\Availability;

use App\Exceptions\RevisionConflict;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Unavailability;
use App\Models\User;
use App\Services\Seasons\ConfigurationYear;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class ManageUnavailability
{
    public function reports(User $actor, Organization $organization)
    {
        Gate::forUser($actor)->authorize('manage', $organization);

        return Unavailability::query()->where('organization_id', $organization->id)->orderByDesc('updated_at')->get();
    }

    public function save(User $actor, Organization $organization, string $id, array $input): Unavailability
    {
        Gate::forUser($actor)->authorize('manage', $organization);
        $values = Validator::make($input, [
            'membership_id' => ['required', 'uuid'],
            'expected_revision' => ['present', 'nullable', 'integer', 'min:1', 'max:2147483646'],
        ])->validate();
        $ranger = OrganizationMembership::query()->where('organization_id', $organization->id)->findOrFail($values['membership_id']);

        return app(SaveUnavailability::class)->handle($actor, $ranger, $id,
            $values['expected_revision'] === null ? null : (int) $values['expected_revision'], $input);
    }

    public function acknowledge(User $actor, Organization $organization, string $id, array $input): Unavailability
    {
        return DB::transaction(function () use ($actor, $organization, $id, $input) {
            OrganizationMembership::query()->where('organization_id', $organization->id)
                ->where('user_id', $actor->id)->lockForUpdate()->first();
            Gate::forUser($actor)->authorize('manage', $organization);
            $values = Validator::make($input, ['expected_revision' => ['required', 'integer', 'min:1', 'max:2147483647']])->validate();
            $report = Unavailability::query()->where('organization_id', $organization->id)->findOrFail($id);
            // Same lock order as saves: actor, Ranger, report. Acknowledgment never edits the range.
            OrganizationMembership::query()->whereKey($report->organization_membership_id)->lockForUpdate()->firstOrFail();
            $report = Unavailability::query()->lockForUpdate()->findOrFail($id);
            app(ConfigurationYear::class)->assertDateEditable(
                $report->starts_at->setTimezone($organization->timezone)->toDateString(), $organization->timezone);
            if ($report->revision !== (int) $values['expected_revision']) {
                throw new RevisionConflict;
            }
            DB::table('unavailability_acknowledgments')->insertOrIgnore([
                'unavailability_id' => $id, 'revision' => $report->revision,
                'actor_id' => $actor->id, 'acknowledged_at' => now('UTC'),
            ]);

            return $report;
        }, 3);
    }
}
