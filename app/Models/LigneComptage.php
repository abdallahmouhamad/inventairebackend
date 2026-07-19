<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne de comptage (CountLine, doc fonctionnel §5.1). L'ecart et la
 * criticite ne sont jamais stockes -- calcules a la volee par
 * App\Services\CalculEcart, pour qu'un changement des seuils configures se
 * repercute immediatement sans script de migration (meme principe que
 * VerrouEmplacement::estObsolete()).
 */
class LigneComptage extends Model
{
    use HasUuids;

    public const REVIEW_PENDING = 'PENDING';

    public const REVIEW_APPROVED = 'APPROVED';

    public const REVIEW_REJECTED = 'REJECTED';

    protected $table = 'lignes_comptage';

    protected $fillable = [
        'fiche_comptage_id',
        'code_article',
        'nom_article',
        'code_emplacement',
        'numero_lot',
        'numero_lot_parent',
        'date_peremption',
        'est_correction_lot',
        'est_hors_liste',
        'qte_theorique_itu',
        'qte_theorique_stu',
        'qte_comptee_itu',
        'qte_comptee_stu',
        'statut_review',
        'commentaire_rejet',
    ];

    protected function casts(): array
    {
        return [
            'date_peremption' => 'date',
            'est_correction_lot' => 'boolean',
            'est_hors_liste' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<FicheComptage, $this>
     */
    public function ficheComptage(): BelongsTo
    {
        return $this->belongsTo(FicheComptage::class, 'fiche_comptage_id');
    }
}
