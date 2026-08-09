<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Registro de cada impresion de una boleta, para poder marcar las copias como
 * "REIMPRESION" y saber quien y cuando la reimprimio.
 */
class ReceiptPrint extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_reprint' => 'boolean',
        'printed_at' => 'datetime',
    ];

    public function scopeForDocument($query, string $documentId)
    {
        return $query->where('document_id', $documentId);
    }
}
