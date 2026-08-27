<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Servicio que forma parte de un combo (base propia, HSJ_Caja).
 *
 * @property int $bundle_id
 * @property string $cod_nomen_caja
 * @property int $quantity
 */
class ServiceBundleItem extends Model
{
    protected $table = 'caja_service_bundle_items';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'quantity' => 'integer',
        'created_at' => 'datetime',
    ];

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(ServiceBundle::class, 'bundle_id');
    }
}
