<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PreferenceProfile extends Model
{
    use HasUuids;

    protected $fillable = ['id', 'organization_id', 'organization_membership_id', 'profile_key', 'season_id', 'week_starts_on', 'preferred_shift_ids', 'hours_mode', 'desired_weekly_minutes', 'days_off', 'revision'];

    protected function casts(): array
    {
        return ['preferred_shift_ids' => 'array', 'desired_weekly_minutes' => 'integer', 'revision' => 'integer'];
    }
}
