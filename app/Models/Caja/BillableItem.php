<?php

namespace App\Models\Caja;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Nomenclatura_caja_MH: catalogo de conceptos facturables (consultas, procedimientos, etc.).
 *
 * @property string $cod_nomen_caja
 * @property string $descripcion_nomen_tipo
 * @property string $nomen_caja
 * @property bool|null $estado_nomenclatura
 */
class BillableItem extends Model
{
    protected $connection = 'caja';

    protected $table = 'Nomenclatura_caja_MH';

    protected $primaryKey = 'cod_nomen_caja';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'estado_nomenclatura' => 'boolean',
    ];

    public function prices(): HasMany
    {
        return $this->hasMany(Price::class, 'cod_nomen_caja', 'cod_nomen_caja');
    }

    public function scopeSearch($query, ?string $term)
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where('descripcion_nomen_tipo', 'like', "%{$term}%")
            ->orWhere('nomen_caja', 'like', "%{$term}%")
            ->orWhere('cod_nomen_caja', 'like', "%{$term}%");
    }
}
