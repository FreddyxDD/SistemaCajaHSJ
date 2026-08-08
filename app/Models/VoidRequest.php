<?php

namespace App\Models;

use App\Models\Caja\ChargeDocument;
use Illuminate\Database\Eloquent\Model;

/**
 * Solicitud de anulacion de un cobro. Vive en la base propia del aplicativo
 * (HSJ_Caja) porque el esquema legado no guarda el flujo de aprobacion, solo el
 * resultado final. Ver docs/mapeo-base-datos-caja.md.
 */
class VoidRequest extends Model
{
    protected $guarded = [];

    protected $casts = [
        'document_total' => 'decimal:4',
        'requested_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * El documento vive en otra base (SISGESH_BD), por lo que no es una relacion
     * Eloquent estandar sino una consulta explicita en la conexion `caja`.
     */
    public function document(): ?ChargeDocument
    {
        return ChargeDocument::query()->find($this->document_id);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_APPROVED => 'Aprobada',
            self::STATUS_REJECTED => 'Rechazada',
            default => 'Pendiente',
        };
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_APPROVED => 'green',
            self::STATUS_REJECTED => 'red',
            default => 'amber',
        };
    }
}
