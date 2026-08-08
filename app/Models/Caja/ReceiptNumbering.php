<?php

namespace App\Models\Caja;

use Illuminate\Database\Eloquent\Model;

/**
 * NUMERACION_BOLETA: correlativo de series de comprobante por punto de cobro (PC).
 *
 * @property int $id_numeracion_boleta
 * @property string $nombre_pc
 * @property string $serie
 * @property string $numero_documento
 */
class ReceiptNumbering extends Model
{
    protected $connection = 'caja';

    protected $table = 'NUMERACION_BOLETA';

    protected $primaryKey = 'id_numeracion_boleta';

    public $timestamps = false;

    protected $guarded = [];
}
