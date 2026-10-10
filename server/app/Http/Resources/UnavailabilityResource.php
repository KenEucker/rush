<?php

namespace App\Http\Resources;

use App\Services\Availability\AssignmentConflicts;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UnavailabilityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'organization_id' => $this->organization_id,
            'membership_id' => $this->organization_membership_id,
            'starts_at' => $this->starts_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'ends_at' => $this->ends_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'revision' => $this->revision,
            'assignment_conflicts' => app(AssignmentConflicts::class)->forReport($this->resource),
        ];
    }
}
