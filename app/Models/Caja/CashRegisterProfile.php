<?php

namespace App\Models\Caja;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PERFIL_CAJA_MH: perfil de cada punto de cobro (que tipo de documento emite, serie,
 * dependencia).
 *
 * @property string $cod_perfil_caja
 * @property string $nom_pc_mh
 * @property string $cod_tipo_documento
 * @property string $cod_jerar_forma_pago
 * @property string|null $serie_caja
 */
class CashRegisterProfile extends Model
{
    protected $connection = 'caja';

    protected $table = 'PERFIL_CAJA_MH';

    protected $primaryKey = 'cod_perfil_caja';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'cod_tipo_documento', 'cod_tipo_documento');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'cod_jerar_forma_pago', 'cod_jerar_forma_pago');
    }
}
