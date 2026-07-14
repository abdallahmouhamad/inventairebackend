<?php

namespace App\Models;

use Database\Factories\UtilisateurFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Contracts\JWTSubject;

class Utilisateur extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<UtilisateurFactory> */
    use HasFactory, HasUuids, Notifiable;

    protected $table = 'utilisateurs';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'prenom',
        'nom',
        'email',
        'mot_de_passe',
        'role_id',
        'est_actif',
        'derniere_connexion_le',
        'x3_utilisateur_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'mot_de_passe',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'derniere_connexion_le' => 'datetime',
            'mot_de_passe' => 'hashed',
            'est_actif' => 'boolean',
        ];
    }

    public function isWebRole(): bool
    {
        return in_array($this->role->code, Role::WEB_CODES, true);
    }

    public function isMobileRole(): bool
    {
        return in_array($this->role->code, Role::MOBILE_CODES, true);
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Codes des sites (X3) auxquels cet utilisateur est scope. Le referentiel
     * site n'est pas persiste localement (cf. RererentielX3) : on stocke donc
     * uniquement le code_site, pas de relation Eloquent vers un modele Site.
     *
     * @return array<int, string>
     */
    public function codesSites(): array
    {
        return DB::table('utilisateur_site')
            ->where('utilisateur_id', $this->id)
            ->pluck('code_site')
            ->all();
    }

    /**
     * @param array<int, string> $codesSites
     */
    public function attacherSites(array $codesSites): void
    {
        $lignes = array_map(
            fn (string $codeSite) => ['utilisateur_id' => $this->id, 'code_site' => $codeSite],
            $codesSites,
        );

        DB::table('utilisateur_site')->insertOrIgnore($lignes);
    }

    public function getAuthPassword(): string
    {
        return $this->mot_de_passe;
    }

    public function getAuthPasswordName(): string
    {
        return 'mot_de_passe';
    }

    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    /**
     * @return array<string, mixed>
     */
    public function getJWTCustomClaims(): array
    {
        return [
            'role' => $this->role->code,
            'site_ids' => $this->codesSites(),
        ];
    }
}
