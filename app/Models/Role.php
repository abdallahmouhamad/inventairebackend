<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use HasUuids;

    public const OPERATOR = 'OPERATOR';

    public const MOBILE_MANAGER = 'MOBILE_MANAGER';

    public const SUPER_ADMIN = 'SUPER_ADMIN';

    public const INVENTORY_MANAGER = 'INVENTORY_MANAGER';

    public const READONLY = 'READONLY';

    public const WEB_CODES = [self::SUPER_ADMIN, self::INVENTORY_MANAGER, self::READONLY];

    public const MOBILE_CODES = [self::OPERATOR, self::MOBILE_MANAGER];

    protected $fillable = [
        'code',
        'libelle',
    ];

    /**
     * @return HasMany<Utilisateur, $this>
     */
    public function utilisateurs(): HasMany
    {
        return $this->hasMany(Utilisateur::class);
    }
}
