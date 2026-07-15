<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Enregistrement d'une tentative de declaration refusee sur un rayon deja
 * couvert par un perimetre actif (doc fonctionnel §3.2/§6.3 : "Toute
 * tentative de declarer un rayon occupe doit ... donner lieu a un
 * enregistrement d'alerte consultable cote Web Admin").
 */
class TentativeAccesPerimetre extends Model
{
    use HasUuids;

    protected $table = 'tentatives_acces_perimetre';

    protected $fillable = [
        'session_id',
        'code_depot',
        'code_rayon',
        'agent_id',
        'perimetre_conflit_id',
        'tentee_le',
        'resolue_le',
        'resolue_par_id',
    ];

    protected function casts(): array
    {
        return [
            'tentee_le' => 'datetime',
            'resolue_le' => 'datetime',
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
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'agent_id');
    }

    /**
     * @return BelongsTo<Perimetre, $this>
     */
    public function perimetreConflit(): BelongsTo
    {
        return $this->belongsTo(Perimetre::class, 'perimetre_conflit_id');
    }

    /**
     * @return BelongsTo<Utilisateur, $this>
     */
    public function resoluePar(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'resolue_par_id');
    }
}
