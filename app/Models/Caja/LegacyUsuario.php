<?php

namespace App\Models\Caja;

use Illuminate\Database\Eloquent\Model;

/**
 * Usuario: catalogo de usuarios del sistema legado. CAJA_APERTURA_CIERRE.cod_usu y
 * Cabecera_documento_MH.cod_usu tienen FK hacia aqui, por lo que cada operacion debe
 * asociarse a una fila real de esta tabla.
 *
 * @property string $cod_usu
 * @property string $cod_tipo
 */
class LegacyUsuario extends Model
{
    protected $connection = 'caja';

    protected $table = 'Usuario';

    protected $primaryKey = 'cod_usu';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];
}
