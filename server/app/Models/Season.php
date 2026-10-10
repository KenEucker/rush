<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Orchid\Screen\AsSource;

class Season extends Model
{
    use AsSource, HasUuids;

    protected $fillable = ['id', 'organization_id', 'name', 'starts_on', 'ends_on', 'timezone', 'week_starts_on', 'revision'];

    protected function casts(): array
    {
        return ['week_starts_on' => 'integer', 'revision' => 'integer'];
    }

    public function phases(): HasMany
    {
        return $this->hasMany(Phase::class)->orderBy('starts_on')->orderBy('id');
    }
}
