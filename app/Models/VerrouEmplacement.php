<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Verrou granulaire sur un emplacement precis, a l'interieur d'un perimetre,
 * pendant la saisie active (doc fonctionnel §5.1/§6.5). Comme pour Perimetre,
 * le depot/rayon/emplacement ne sont jamais stockes en local sous forme de
 * FK : uniquement leurs codes X3.
 */
class VerrouEmplacement extends Model
{
    use HasUuids;

    protected $table = 'verrous_emplacement';

    protected $fillable = [
        'session_id',
        'perimetre_id',
        'code_depot',
        'code_rayon',
        'code_emplacement',
        'agent_id',
        'verrouille_le',
        'derniere_activite_le',
        'libere_le',
        'libere_par_id',
        'force_libere',
        'motif_liberation_forcee',
    ];

    protected function casts(): array
    {
        return [
            'verrouille_le' => 'datetime',
            'derniere_activite_le' => 'datetime',
            'libere_le' => 'datetime',
            'force_libere' => 'boolean',
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
     * @return BelongsTo<Utilisateur, $this>
     */
    public function liberePar(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'libere_par_id');
    }

    /**
     * Calcule a la volee, jamais stocke (doc fonctionnel §9.2) : un
     * changement du seuil de timeout doit se repercuter immediatement sur
     * l'affichage, pas seulement sur les verrous crees apres le changement.
     */
    public function estObsolete(): bool
    {
        if ($this->libere_le !== null) {
            return false;
        }

        $seuilMinutes = (int) config('inventaire.timeout_verrou_minutes');

        return $this->derniere_activite_le->diffInMinutes(now()) > $seuilMinutes;
    }
}
