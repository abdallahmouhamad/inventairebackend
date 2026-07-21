<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entree du journal d'audit (doc fonctionnel §5.1/§6.8) : trace l'acteur,
 * l'horodatage, la cible et des metadonnees libres pour chaque action
 * metier significative. Immuable : jamais modifiee apres creation (pas de
 * updated_at), toujours creee via App\Services\AuditService::log(), jamais
 * directement -- voir la recommandation d'implementation du doc (§6.8).
 */
class EntreeAudit extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'entrees_audit';

    protected $fillable = [
        'acteur_id',
        'action',
        'cible_type',
        'cible_id',
        'metadonnees',
    ];

    protected function casts(): array
    {
        return [
            'metadonnees' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Utilisateur, $this>
     */
    public function acteur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'acteur_id');
    }
}
