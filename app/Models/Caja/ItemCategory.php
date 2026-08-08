<?php

namespace App\Models\Caja;

use Illuminate\Database\Eloquent\Model;

/**
 * GRUPO_NOMENCLATURA_ATENCION_MH: categorias reales del catalogo de Caja
 * (Nomenclatura_caja_MH.grupo -> codigo_grupo aqui). Ej. LA=Laboratorio,
 * RX=Rayos X, EC=Ecografias, TM=Tomografias, CJ=Caja (consultas y
 * procedimientos generales), RM=Resonancia Magnetica.
 *
 * @property string $cod_grupo_nomen_aten
 * @property string $codigo_grupo
 * @property string $nombre_grupo_nomen
 */
class ItemCategory extends Model
{
    protected $connection = 'caja';

    protected $table = 'GRUPO_NOMENCLATURA_ATENCION_MH';

    protected $primaryKey = 'cod_grupo_nomen_aten';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];
}
