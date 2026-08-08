<?php

namespace App\Models\Caja;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Detalle_documento_MH: linea de un cobro (servicio, cantidad, precio).
 *
 * @property string $id_cod_det
 * @property string $id_documento
 * @property string $cod_precio referencia a Precio_MH.cod_precio (item + forma de pago)
 * @property float $cantidad_detalle
 * @property float $precio_detalle
 * @property float $total_detalle
 */
class ChargeDocumentItem extends Model
{
    protected $connection = 'caja';

    protected $table = 'Detalle_documento_MH';

    protected $primaryKey = 'id_cod_det';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    public function document(): BelongsTo
    {
        return $this->belongsTo(ChargeDocument::class, 'id_documento', 'id_documento');
    }

    public function price(): BelongsTo
    {
        return $this->belongsTo(Price::class, 'cod_precio', 'cod_precio');
    }
}
