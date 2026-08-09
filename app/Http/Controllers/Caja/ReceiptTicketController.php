<?php

namespace App\Http\Controllers\Caja;

use App\Http\Controllers\Controller;
use App\Models\Caja\ChargeDocument;
use App\Models\ReceiptPrint;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;

class ReceiptTicketController extends Controller
{
    /**
     * Ticket de la boleta en formato de impresora termica. Se carga dentro de un
     * iframe oculto y se imprime solo.
     *
     * Cada carga queda registrada: la primera es el original, las siguientes se
     * marcan e imprimen como REIMPRESION.
     */
    public function __invoke(string $documentId): View
    {
        $document = ChargeDocument::query()
            ->with(['items.price.billableItem', 'paymentMethod', 'cashier', 'historiaClinica', 'documentType'])
            ->findOrFail($documentId);

        $previousPrints = ReceiptPrint::query()->forDocument($documentId)->count();
        $isReprint = $previousPrints > 0;

        $print = ReceiptPrint::query()->create([
            'document_id' => $document->id_documento,
            'document_number' => $document->num_documento,
            'is_reprint' => $isReprint,
            'printed_by_user_id' => Auth::id(),
            'printed_by_name' => Auth::user()?->name,
            'printed_at' => now(),
        ]);

        $logo = config('ticket.logo');
        $logoPath = $logo ? public_path($logo) : null;

        return view('caja.ticket', [
            'document' => $document,
            'isReprint' => $isReprint,
            'printNumber' => $previousPrints + 1,
            'printedAt' => $print->printed_at,
            'printedByName' => $print->printed_by_name,
            'hospital' => config('ticket.hospital'),
            'unidad' => config('ticket.unidad'),
            'direccion' => config('ticket.direccion'),
            'ruc' => config('ticket.ruc'),
            'pie' => config('ticket.pie'),
            'anchoMm' => config('ticket.ancho_mm'),
            // Si no hay archivo de logo cargado, el ticket sale solo con el nombre
            // del hospital en vez de una imagen rota.
            'logoUrl' => ($logoPath && is_file($logoPath)) ? asset($logo) : null,
        ]);
    }
}
