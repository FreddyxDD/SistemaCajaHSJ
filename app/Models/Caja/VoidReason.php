<?php

namespace App\Models\Caja;

use Illuminate\Database\Eloquent\Model;

/**
 * Motivo_Anulacion_MH: catalogo de motivos de anulacion. MA001 "SIN ANULACION" es el
 * valor por defecto de un documento vigente, no un motivo real de anulacion.
 *
 * @property string $cod_motiv_anu
 * @property string $descripcion_anulacion
 */
class VoidReason extends Model
{
    protected $connection = 'caja';

    protected $table = 'Motivo_Anulacion_MH';

    protected $primaryKey = 'cod_motiv_anu';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    public function scopeSelectable($query)
    {
        return $query->where('cod_motiv_anu', '!=', 'MA001')->orderBy('descripcion_anulacion');
    }
}
