<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SeasonResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'organization_id' => $this->organization_id,
            'name' => $this->name, 'starts_on' => $this->starts_on, 'ends_on' => $this->ends_on,
            'timezone' => $this->timezone, 'week_starts_on' => $this->week_starts_on,
            'revision' => $this->revision,
            'phases' => $this->phases->map(fn ($phase) => $phase->only(['id', 'name', 'starts_on', 'ends_on']))->all(),
        ];
    }
}
