<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Event extends Model
{
    use HasUuids;

    protected $fillable = ['id', 'organization_id', 'season_id', 'name', 'starts_at', 'ends_at', 'timezone', 'season_revision', 'paid', 'target_type', 'target_value', 'counts_scheduled', 'revision'];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'paid' => 'boolean',
            'counts_scheduled' => 'boolean', 'revision' => 'integer', 'season_revision' => 'integer', 'target_value' => 'integer'];
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }
}
