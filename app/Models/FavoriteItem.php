<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Servicio marcado como favorito por un cajero (base propia, HSJ_Caja).
 *
 * Guarda el servicio, no el precio: la tarifa depende de la forma de pago y de lo
 * que Costos tenga cargado hoy.
 *
 * @property int $user_id
 * @property string $cod_nomen_caja
 */
class FavoriteItem extends Model
{
    protected $table = 'caja_favorite_items';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
