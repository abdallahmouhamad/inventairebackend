<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Rayon extends Model
{
    use HasUuids;

    protected $fillable = [
        'depot_id',
        'code',
        'nom',
    ];

    /**
     * @return BelongsTo<Depot, $this>
     */
    public function depot(): BelongsTo
    {
        return $this->belongsTo(Depot::class);
    }

    /**
     * @return HasMany<Emplacement, $this>
     */
    public function emplacements(): HasMany
    {
        return $this->hasMany(Emplacement::class);
    }
}
