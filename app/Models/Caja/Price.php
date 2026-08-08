<?php

namespace App\Models\Caja;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Precio_MH: precio real de un concepto facturable, que varia segun la forma de pago
 * (ej. el mismo servicio tiene un precio distinto para PARTICULAR que para SIS).
 *
 * @property string $cod_precio
 * @property string $cod_jerar_forma_pago
 * @property string $cod_nomen_caja
 * @property float $precio
 */
class Price extends Model
{
    protected $connection = 'caja';

    protected $table = 'Precio_MH';

    protected $primaryKey = 'cod_precio';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    public function billableItem(): BelongsTo
    {
        return $this->belongsTo(BillableItem::class, 'cod_nomen_caja', 'cod_nomen_caja');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'cod_jerar_forma_pago', 'cod_jerar_forma_pago');
    }
}
