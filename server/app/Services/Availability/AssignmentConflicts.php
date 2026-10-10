<?php

namespace App\Services\Availability;

use App\Models\OfficialAssignment;
use App\Models\Unavailability;

class AssignmentConflicts
{
    public function forReport(Unavailability $report): array
    {
        return OfficialAssignment::query()->where('organization_id', $report->organization_id)
            ->where('organization_membership_id', $report->organization_membership_id)
            ->where('starts_at', '<', $report->ends_at)->where('ends_at', '>', $report->starts_at)
            ->orderBy('id')->get()->map(fn ($assignment) => [
                'id' => $assignment->id, 'revision' => $assignment->revision,
                'starts_at' => $assignment->starts_at->utc()->format('Y-m-d\TH:i:s\Z'),
                'ends_at' => $assignment->ends_at->utc()->format('Y-m-d\TH:i:s\Z'),
            ])->all();
    }
}
