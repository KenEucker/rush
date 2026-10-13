<?php

namespace App\Http\Resources;

use App\Services\Availability\AssignmentConflicts;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class UnavailabilityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $acknowledgment = DB::table('unavailability_acknowledgments')->where('unavailability_id', $this->id)
            ->where('revision', $this->revision)->first();

        return [
            'id' => $this->id, 'organization_id' => $this->organization_id,
            'membership_id' => $this->organization_membership_id,
            'starts_at' => $this->starts_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'ends_at' => $this->ends_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'revision' => $this->revision,
            'acknowledged_at' => $acknowledgment ? CarbonImmutable::parse($acknowledgment->acknowledged_at)->utc()->format('Y-m-d\TH:i:s\Z') : null,
            'assignment_conflicts' => app(AssignmentConflicts::class)->forReport($this->resource),
        ];
    }
}
