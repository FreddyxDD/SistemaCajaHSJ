<?php

namespace App\Models\Caja;

use Illuminate\Database\Eloquent\Model;

/**
 * TIPO_DOCUMENTO_MH: tipos de comprobante (boleta, factura, recibo...).
 *
 * @property string $cod_tipo_documento
 * @property string $tipo_documento
 * @property string $incluir_igv
 */
class DocumentType extends Model
{
    protected $connection = 'caja';

    protected $table = 'TIPO_DOCUMENTO_MH';

    protected $primaryKey = 'cod_tipo_documento';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];
}
