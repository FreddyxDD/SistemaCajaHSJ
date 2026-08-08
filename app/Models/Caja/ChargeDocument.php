<?php

namespace App\Models\Caja;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cabecera_documento_MH: cabecera de cada boleta/factura emitida en caja.
 *
 * @property string $id_documento
 * @property string $serie_documento
 * @property string $num_documento
 * @property string $cod_tipo_documento
 * @property string $cliente
 * @property string $cod_usu
 * @property string $cod_jerar_forma_pago
 * @property string|null $id_hc numero de historia clinica (SIGH), referencia logica cruzada
 * @property float $sub_total_doc
 * @property float $igv_doc
 * @property float $total_doc
 * @property string $estado_doc
 * @property string $cod_aper_cierre_caja
 */
class ChargeDocument extends Model
{
    protected $connection = 'caja';

    protected $table = 'Cabecera_documento_MH';

    protected $primaryKey = 'id_documento';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    public const ESTADO_EMITIDO = 'S';

    public const ESTADO_ANULADO = 'N';

    public function items(): HasMany
    {
        return $this->hasMany(ChargeDocumentItem::class, 'id_documento', 'id_documento');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CashSession::class, 'cod_aper_cierre_caja', 'cod_aper_cierre_caja');
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'cod_tipo_documento', 'cod_tipo_documento');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'cod_jerar_forma_pago', 'cod_jerar_forma_pago');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(LegacyUsuario::class, 'cod_usu', 'cod_usu');
    }

    /**
     * OJO: id_hc es el identificador interno de Historia_clinica (HC + correlativo),
     * NO el numero de historia clinica que conoce el paciente (ese es cod_hc). Para
     * mostrar la HC en pantalla o en la boleta hay que pasar por esta relacion.
     */
    public function historiaClinica(): BelongsTo
    {
        return $this->belongsTo(LegacyHistoriaClinica::class, 'id_hc', 'id_hc');
    }

    public function isVoided(): bool
    {
        return $this->estado_doc === self::ESTADO_ANULADO;
    }
}
