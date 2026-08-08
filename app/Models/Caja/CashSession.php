<?php

namespace App\Models\Caja;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * CAJA_APERTURA_CIERRE: apertura/cierre de turno de caja por usuario.
 *
 * @property string $cod_aper_cierre_caja
 * @property string $cod_usu
 * @property string $fecha_apertura
 * @property string $hora_apertura
 * @property string $fecha_cierre
 * @property string $hora_cierre
 * @property string $estado_aper_cierre_caja
 */
class CashSession extends Model
{
    protected $connection = 'caja';

    protected $table = 'CAJA_APERTURA_CIERRE';

    protected $primaryKey = 'cod_aper_cierre_caja';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    public const ESTADO_ABIERTO = 'P';

    public const ESTADO_CERRADO = 'C';

    public function documents(): HasMany
    {
        return $this->hasMany(ChargeDocument::class, 'cod_aper_cierre_caja', 'cod_aper_cierre_caja');
    }

    public function isOpen(): bool
    {
        return $this->estado_aper_cierre_caja === self::ESTADO_ABIERTO;
    }

    public function scopeOpen($query)
    {
        return $query->where('estado_aper_cierre_caja', self::ESTADO_ABIERTO);
    }
}
