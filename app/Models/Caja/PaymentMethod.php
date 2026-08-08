<?php

namespace App\Models\Caja;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Jerarquia_Forma_Pago_MH: formas de pago (CONTADO/PARTICULAR, SIS, SOAT, ESSALUD via
 * convenio, etc.). `relacion_forma_pago` es el codigo real del padre en el arbol
 * (`0` para las raices de nivel 0). `fp_padre` es una etiqueta de agrupacion plana
 * (ej. "SOAT", "CONVENIO", "CREDITO") que ya trae cada fila, util para reportes sin
 * necesidad de recorrer el arbol.
 *
 * @property string $cod_jerar_forma_pago
 * @property string $nom_forma_pago
 * @property string $descri_forma_pago
 * @property string $relacion_forma_pago
 * @property string|null $fp_padre
 * @property string $nivel_forma_pago
 */
class PaymentMethod extends Model
{
    protected $connection = 'caja';

    protected $table = 'Jerarquia_Forma_Pago_MH';

    protected $primaryKey = 'cod_jerar_forma_pago';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'relacion_forma_pago', 'cod_jerar_forma_pago');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'relacion_forma_pago', 'cod_jerar_forma_pago');
    }

    public function scopeTopLevel($query)
    {
        return $query->where('nivel_forma_pago', '0');
    }
}
