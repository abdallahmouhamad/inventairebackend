<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Depot extends Model
{
    use HasUuids;

    public const TYPE_PHARMA = 'PHARMA';

    public const TYPE_CONSUMABLE = 'CONSUMABLE';

    public const TYPE_EQUIPMENT = 'EQUIPMENT';

    protected $fillable = [
        'site_id',
        'code',
        'nom',
        'type',
        'est_actif',
    ];

    protected function casts(): array
    {
        return [
            'est_actif' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return HasMany<Rayon, $this>
     */
    public function rayons(): HasMany
    {
        return $this->hasMany(Rayon::class);
    }
}
