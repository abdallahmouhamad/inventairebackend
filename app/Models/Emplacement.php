<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Emplacement extends Model
{
    use HasUuids;

    protected $fillable = [
        'rayon_id',
        'code',
        'libelle',
    ];

    /**
     * @return BelongsTo<Rayon, $this>
     */
    public function rayon(): BelongsTo
    {
        return $this->belongsTo(Rayon::class);
    }
}
