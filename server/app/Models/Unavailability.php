<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Unavailability extends Model
{
    use HasUuids;

    protected $fillable = ['id', 'organization_id', 'organization_membership_id', 'starts_at', 'ends_at', 'revision'];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'revision' => 'integer'];
    }
}
