<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SessionInventaire extends Model
{
    use HasUuids;

    public const STATUT_IMPORTED_FROM_X3 = 'IMPORTED_FROM_X3';

    public const STATUT_OPEN = 'OPEN';

    public const STATUT_IN_PROGRESS = 'IN_PROGRESS';

    public const STATUT_SIGNED = 'SIGNED';

    public const STATUT_PENDING_SYNC = 'PENDING_SYNC';

    public const STATUT_SYNCING = 'SYNCING';

    public const STATUT_SYNCED_TO_X3 = 'SYNCED_TO_X3';

    public const STATUT_SYNC_FAILED = 'SYNC_FAILED';

    protected $table = 'sessions_inventaire';

    protected $fillable = [
        'code',
        'nom',
        'code_site',
        'statut',
        'date_debut',
        'date_fin',
        'x3_session_id',
        'importee_de_x3_le',
        'ouverte_aux_agents_le',
        'ouverte_par',
        'total_lignes',
        'lignes_soumises',
        'lignes_validees',
    ];

    protected function casts(): array
    {
        return [
            'date_debut' => 'date',
            'date_fin' => 'date',
            'importee_de_x3_le' => 'datetime',
            'ouverte_aux_agents_le' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Utilisateur, $this>
     */
    public function ouvertePar(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'ouverte_par');
    }

    /**
     * @return BelongsToMany<Utilisateur, $this>
     */
    public function utilisateursAutorises(): BelongsToMany
    {
        return $this->belongsToMany(Utilisateur::class, 'session_inventaire_utilisateur');
    }
}
