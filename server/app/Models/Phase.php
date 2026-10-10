<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Phase extends Model
{
    use HasUuids;

    protected $fillable = ['id', 'season_id', 'name', 'starts_on', 'ends_on'];
}
