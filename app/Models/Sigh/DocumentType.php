<?php

namespace App\Models\Sigh;

use Illuminate\Database\Eloquent\Model;

/**
 * TiposDocIdentidad (SIGH, solo lectura): DNI, Carnet de Extranjeria, Pasaporte, etc.
 *
 * @property int $IdDocIdentidad
 * @property string $Descripcion
 */
class DocumentType extends Model
{
    protected $connection = 'sigh';

    protected $table = 'TiposDocIdentidad';

    protected $primaryKey = 'IdDocIdentidad';

    public $timestamps = false;

    protected $guarded = [];
}
