<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Zone d'inventaire (un depot + un ou plusieurs rayons) declaree par un agent
 * mobile (doc fonctionnel §3.2/§5.1/§5.3). Comme pour SessionInventaire, le
 * depot et les rayons ne sont jamais stockes en local sous forme de FK :
 * uniquement leurs codes X3 (cf. RererentielX3, aucune table referentiel
 * dupliquee en Postgres).
 *
 * Perimetre "Option 1" : declaration, disponibilite des rayons, liberation
 * (volontaire ou forcee). Le recomptage/l'arbitrage/la relance (statuts
 * RECOUNT_REQUESTED -> VALIDATED/RELAUNCHED) ne sont pas encore implementes
 * -- prochaine etape une fois les Submissions posees.
 */
class Perimetre extends Model
{
    use HasUuids;

    public const STATUT_DECLARED = 'DECLARED';

    public const STATUT_AWAITING_REVIEW = 'AWAITING_REVIEW';

    public const STATUT_IN_REVIEW = 'IN_REVIEW';

    public const STATUT_RECOUNT_REQUESTED = 'RECOUNT_REQUESTED';

    public const STATUT_RECOUNTING = 'RECOUNTING';

    public const STATUT_AWAITING_ARBITRATION = 'AWAITING_ARBITRATION';

    public const STATUT_VALIDATED = 'VALIDATED';

    public const STATUT_RELAUNCHED = 'RELAUNCHED';

    public const STATUT_RELEASED_BY_AGENT = 'RELEASED_BY_AGENT';

    public const STATUT_FORCE_RELEASED = 'FORCE_RELEASED';

    /**
     * Statuts "actifs" : un rayon couvert par un perimetre dans l'un de ces
     * statuts est indisponible pour toute autre declaration (doc fonctionnel
     * §5.3, "REGLE ABSOLUE").
     */
    public const STATUTS_ACTIFS = [
        self::STATUT_DECLARED,
        self::STATUT_AWAITING_REVIEW,
        self::STATUT_IN_REVIEW,
        self::STATUT_RECOUNT_REQUESTED,
        self::STATUT_RECOUNTING,
        self::STATUT_AWAITING_ARBITRATION,
    ];

    protected $table = 'perimetres';

    protected $fillable = [
        'session_id',
        'code_depot',
        'statut',
        'agent_declarant_id',
        'declare_le',
        'libere_le',
        'libere_par_id',
        'motif_liberation_forcee',
    ];

    protected function casts(): array
    {
        return [
            'declare_le' => 'datetime',
            'libere_le' => 'datetime',
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
     * @return BelongsTo<Utilisateur, $this>
     */
    public function agentDeclarant(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'agent_declarant_id');
    }

    /**
     * @return BelongsTo<Utilisateur, $this>
     */
    public function liberePar(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'libere_par_id');
    }

    /**
     * @return HasMany<TentativeAccesPerimetre, $this>
     */
    public function tentativesAcces(): HasMany
    {
        return $this->hasMany(TentativeAccesPerimetre::class, 'perimetre_conflit_id');
    }

    /**
     * Codes des rayons couverts par ce perimetre (table perimetre_rayon,
     * meme approche que Utilisateur::codesSites() : pas de modele Eloquent
     * pour une simple liste de codes X3).
     *
     * @return array<int, string>
     */
    public function codesRayons(): array
    {
        return DB::table('perimetre_rayon')
            ->where('perimetre_id', $this->id)
            ->pluck('code_rayon')
            ->all();
    }

    /**
     * @param array<int, string> $codesRayons
     */
    public function attacherRayons(array $codesRayons): void
    {
        $lignes = array_map(
            fn (string $codeRayon) => ['perimetre_id' => $this->id, 'code_rayon' => $codeRayon],
            $codesRayons,
        );

        DB::table('perimetre_rayon')->insertOrIgnore($lignes);
    }
}
