<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MemberProfileResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'display_name' => $this->display_name,
            'phone' => $this->phone,
            'revision' => $this->revision,
            'updated_at' => $this->updated_at->utc()->toISOString(),
        ];
    }
}
