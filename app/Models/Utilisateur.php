<?php

namespace App\Models;

use Database\Factories\UtilisateurFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
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
     * @return BelongsToMany<Site, $this>
     */
    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class, 'site_utilisateur');
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
            'site_ids' => $this->sites()->pluck('sites.id'),
        ];
    }
}
