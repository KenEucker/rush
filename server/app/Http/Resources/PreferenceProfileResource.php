<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PreferenceProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'organization_id' => $this->organization_id,
            'membership_id' => $this->organization_membership_id, 'season_id' => $this->season_id,
            'week_starts_on' => $this->week_starts_on, 'preferred_shift_ids' => $this->preferred_shift_ids,
            'hours_mode' => $this->hours_mode, 'desired_weekly_minutes' => $this->desired_weekly_minutes,
            'days_off' => $this->days_off, 'revision' => $this->revision];
    }
}
