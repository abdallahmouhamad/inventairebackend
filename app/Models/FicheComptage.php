<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fiche de comptage (Submission, doc fonctionnel §5.1/§5.4/§6.4). Chemin
 * "normal" : SUBMITTED -> IN_REVIEW -> VALIDATED, ou -> REVISION.
 *
 * Chemin recomptage (est_recomptage = true, fiche_initiale_id renseigne) :
 * l'arbitrage remplace le workflow approve/reject ligne a ligne habituel --
 * une fois l'arbitrage termine (Perimetre::completeArbitration), la fiche
 * initiale passe ARCHIVED et la fiche de recomptage devient la fiche de
 * reference : VALIDATED. Le statut de la fiche n'indique donc pas "qui a
 * raison" par ligne, seule LigneComptage::resultat_arbitrage le fait.
 */
class FicheComptage extends Model
{
    use HasUuids;

    public const STATUT_SUBMITTED = 'SUBMITTED';

    public const STATUT_IN_REVIEW = 'IN_REVIEW';

    public const STATUT_VALIDATED = 'VALIDATED';

    public const STATUT_REVISION = 'REVISION';

    public const STATUT_ARCHIVED = 'ARCHIVED';

    protected $table = 'fiches_comptage';

    protected $fillable = [
        'session_id',
        'perimetre_id',
        'agent_id',
        'statut',
        'soumise_le',
        'commentaire_revision',
        'est_recomptage',
        'fiche_initiale_id',
    ];

    protected function casts(): array
    {
        return [
            'soumise_le' => 'datetime',
            'est_recomptage' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<SessionInventaire, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(SessionInventaire::class, 'session_id');
    }

    /**
     * @return BelongsTo<Perimetre, $this>
     */
    public function perimetre(): BelongsTo
    {
        return $this->belongsTo(Perimetre::class, 'perimetre_id');
    }

    /**
     * @return BelongsTo<Utilisateur, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'agent_id');
    }

    /**
     * @return HasMany<LigneComptage, $this>
     */
    public function lignes(): HasMany
    {
        return $this->hasMany(LigneComptage::class, 'fiche_comptage_id');
    }

    /**
     * @return BelongsTo<FicheComptage, $this>
     */
    public function ficheInitiale(): BelongsTo
    {
        return $this->belongsTo(FicheComptage::class, 'fiche_initiale_id');
    }

    /**
     * @return bool
     */
    public function aDesArticlesHorsListe(): bool
    {
        return $this->lignes()->where('est_hors_liste', true)->exists();
    }

    /**
     * @return bool
     */
    public function aDesCorrectionsDeLot(): bool
    {
        return $this->lignes()->where('est_correction_lot', true)->exists();
    }
}
