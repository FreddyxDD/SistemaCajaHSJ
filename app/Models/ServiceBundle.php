<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Combo de servicios de un cajero (base propia, HSJ_Caja).
 *
 * Hay cobros que se repiten con la misma lista larga de examenes. En vez de buscarlos
 * uno por uno cada vez, el cajero guarda la combinacion y despues la carga entera,
 * quitando o agregando lo que cambie en ese paciente.
 *
 * @property int $user_id
 * @property string $name
 */
class ServiceBundle extends Model
{
    protected $table = 'caja_service_bundles';

    protected $guarded = [];

    public function items(): HasMany
    {
        return $this->hasMany(ServiceBundleItem::class, 'bundle_id');
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
