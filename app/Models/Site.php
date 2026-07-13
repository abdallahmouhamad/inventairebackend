<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Site extends Model
{
    use HasUuids;

    protected $fillable = [
        'code',
        'nom',
        'ville',
        'est_actif',
    ];

    protected function casts(): array
    {
        return [
            'est_actif' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Depot, $this>
     */
    public function depots(): HasMany
    {
        return $this->hasMany(Depot::class);
    }

    /**
     * @return HasMany<SessionInventaire, $this>
     */
    public function sessionsInventaire(): HasMany
    {
        return $this->hasMany(SessionInventaire::class);
    }

    /**
     * @return BelongsToMany<Utilisateur, $this>
     */
    public function utilisateurs(): BelongsToMany
    {
        return $this->belongsToMany(Utilisateur::class, 'site_utilisateur');
    }
}
