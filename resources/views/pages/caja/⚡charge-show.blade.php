<?php

use App\Models\Caja\ChargeDocument;
use App\Models\Caja\VoidReason;
use App\Models\ReceiptPrint;
use App\Models\VoidRequest;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Detalle de cobro')] class extends Component {
    public string $documentId;

    public bool $showVoidForm = false;

    public string $voidReason = '';

    public string $voidNotes = '';

    public function mount(string $documentId): void
    {
        $this->documentId = $documentId;
    }

    #[Computed]
    public function document(): ChargeDocument
    {
        return ChargeDocument::query()
            ->with(['items.price.billableItem', 'paymentMethod', 'cashier', 'historiaClinica'])
            ->findOrFail($this->documentId);
    }

    #[Computed]
    public function voidReasons()
    {
        return VoidReason::query()->selectable()->get();
    }

    #[Computed]
    public function timesPrinted(): int
    {
        return ReceiptPrint::query()->forDocument($this->documentId)->count();
    }

    /** Solicitud de anulacion mas reciente para este comprobante, si existe. */
    #[Computed]
    public function voidRequest(): ?VoidRequest
    {
        return VoidRequest::query()
            ->where('document_id', $this->documentId)
            ->orderByDesc('id')
            ->first();
    }

    #[Computed]
    public function canRequestVoid(): bool
    {
        $user = Auth::user();

        return $user->hasPermission('caja.void.request') || $user->hasRole('administrador');
    }

    public function requestVoid(): void
    {
        abort_unless($this->canRequestVoid, 403);

        $this->validate([
            'voidReason' => ['required'],
            'voidNotes' => ['nullable', 'string', 'max:500'],
        ], [
            'voidReason.required' => 'Selecciona el motivo de anulación.',
        ]);

        $document = $this->document;

        abort_if($document->isVoided(), 409, 'Este comprobante ya está anulado.');

        if ($this->voidRequest?->isPending()) {
            $this->addError('voidReason', 'Ya existe una solicitud pendiente para este comprobante.');

            return;
        }

        $reason = VoidReason::query()->find($this->voidReason);

        VoidRequest::query()->create([
            'document_id' => $document->id_documento,
            'document_number' => $document->num_documento,
            'document_total' => $document->total_doc,
            'cash_session_code' => $document->cod_aper_cierre_caja,
            'void_reason_code' => $reason->cod_motiv_anu,
            'void_reason_label' => $reason->descripcion_anulacion,
            'request_notes' => $this->voidNotes ?: null,
            'requested_by_user_id' => Auth::id(),
            'requested_by_name' => Auth::user()->name,
            'requested_at' => now(),
            'status' => VoidRequest::STATUS_PENDING,
        ]);

        $this->showVoidForm = false;
        $this->voidReason = '';
        $this->voidNotes = '';
        unset($this->voidRequest);

        session()->flash('ok', 'Solicitud enviada. Queda pendiente de aprobación del Jefe de Economía o el Cajero central.');
    }
}; ?>

<section class="w-full space-y-6">
    {{--
        Impresion del ticket. Se hace en un iframe oculto que carga la ruta del
        ticket (formato ticketera): asi se imprime solo el comprobante y no esta
        pagina con el menu del aplicativo. Llega con ?print=1 al emitir el cobro; el
        boton "Imprimir boleta" hace lo mismo bajo demanda.
    --}}
    <iframe
        x-data="{
            imprimir() {
                const marco = $refs.marco;
                marco.src = @js(route('caja.charges.ticket', $documentId)) + '?t=' + Date.now();
            }
        }"
        x-init="@if (request()->boolean('print')) imprimir() @endif"
        x-ref="marco"
        @imprimir-ticket.window="imprimir()"
        title="Ticket"
        aria-hidden="true"
        style="position:absolute; width:0; height:0; border:0; visibility:hidden;"
    ></iframe>

    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <flux:heading size="xl">{{ $this->document->num_documento }}</flux:heading>
            <div class="mt-1 flex flex-wrap items-center gap-2">
                <flux:badge color="zinc" size="sm">HC {{ $this->document->historiaClinica?->historia_number ?? '—' }}</flux:badge>
                <flux:text class="font-medium">{{ $this->document->cliente }}</flux:text>
            </div>
        </div>
        <flux:badge :color="$this->document->isVoided() ? 'red' : 'green'" class="shrink-0">
            {{ $this->document->isVoided() ? 'Anulado' : 'Vigente' }}
        </flux:badge>
    </div>

    @if (session('ok'))
        <div class="flex items-start gap-3 rounded-xl border border-indigo-200 bg-indigo-50 p-4 print:hidden dark:border-indigo-500/30 dark:bg-indigo-400/10">
            <flux:icon.check-circle class="mt-0.5 size-5 shrink-0 text-indigo-600 dark:text-indigo-400" />
            <flux:text class="text-indigo-800 dark:text-indigo-300">{{ session('ok') }}</flux:text>
        </div>
    @endif

    {{-- Estado de la solicitud de anulacion --}}
    @if ($this->voidRequest && $this->voidRequest->isPending())
        <div class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 print:hidden dark:border-amber-500/30 dark:bg-amber-400/10">
            <flux:icon.clock class="mt-0.5 size-5 shrink-0 text-amber-600 dark:text-amber-400" />
            <div>
                <flux:text class="font-medium text-amber-900 dark:text-amber-300">Anulación pendiente de aprobación</flux:text>
                <flux:text class="block text-sm text-amber-800 dark:text-amber-400/80">
                    Solicitada por {{ $this->voidRequest->requested_by_name }} el {{ $this->voidRequest->requested_at?->format('d/m/Y H:i') }} —
                    motivo: {{ $this->voidRequest->void_reason_label }}. El comprobante sigue vigente hasta que el Jefe de Economía o el Cajero central la apruebe.
                </flux:text>
            </div>
        </div>
    @elseif ($this->voidRequest && $this->voidRequest->status === \App\Models\VoidRequest::STATUS_REJECTED)
        <div class="flex items-start gap-3 acrilico p-4 print:hidden">
            <flux:icon.x-circle class="mt-0.5 size-5 shrink-0 text-zinc-500" />
            <div>
                <flux:text class="font-medium">Solicitud de anulación rechazada</flux:text>
                <flux:text class="block text-sm text-zinc-500">
                    {{ $this->voidRequest->reviewed_by_name }} rechazó la solicitud: "{{ $this->voidRequest->review_notes }}"
                </flux:text>
            </div>
        </div>
    @endif

    <flux:card class="space-y-4 print:border-none print:shadow-none">
        <div class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-3">
            <div>
                <flux:subheading>Forma de pago</flux:subheading>
                <flux:text>{{ $this->document->paymentMethod?->nom_forma_pago }}</flux:text>
            </div>
            <div>
                <flux:subheading>Cajero</flux:subheading>
                <flux:text>{{ $this->document->cashier?->nom_usu ?? '—' }}</flux:text>
                <flux:text class="block text-xs text-zinc-400">{{ $this->document->cod_usu }}</flux:text>
            </div>
            <div>
                <flux:subheading>Turno de caja</flux:subheading>
                <flux:link href="{{ route('caja.sessions.show', $this->document->cod_aper_cierre_caja) }}" wire:navigate>
                    {{ $this->document->cod_aper_cierre_caja }}
                </flux:link>
            </div>
            <div>
                <flux:subheading>Fecha</flux:subheading>
                <flux:text>{{ $this->document->fecha_actu }}</flux:text>
            </div>
            <div>
                <flux:subheading>Hora</flux:subheading>
                <flux:text>{{ $this->document->hora_actu }}</flux:text>
            </div>
            @if ($this->document->isVoided())
                <div class="col-span-2 sm:col-span-3">
                    <flux:subheading>Motivo de anulación</flux:subheading>
                    <flux:text class="text-red-600 dark:text-red-400">
                        {{ \App\Models\Caja\VoidReason::find($this->document->cod_motiv_anu)?->descripcion_anulacion }}
                        @if ($this->voidRequest?->status === \App\Models\VoidRequest::STATUS_APPROVED)
                            <span class="text-xs text-zinc-500">— aprobada por {{ $this->voidRequest->reviewed_by_name }}</span>
                        @endif
                    </flux:text>
                </div>
            @endif
        </div>

        <div class="overflow-hidden rounded-lg border dark:border-zinc-700">
            <table class="w-full table-fixed text-sm">
                <colgroup>
                    <col class="w-auto">
                    <col class="w-16">
                    <col class="w-24">
                    <col class="w-24">
                </colgroup>
                <thead>
                    <tr class="border-b text-start font-medium text-zinc-800 dark:border-zinc-700 dark:text-white">
                        <th class="px-3 py-2 text-start">Servicio</th>
                        <th class="px-3 py-2 text-end">Cant.</th>
                        <th class="px-3 py-2 text-end">Precio</th>
                        <th class="px-3 py-2 text-end">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->document->items as $item)
                        <tr class="border-b last:border-0 dark:border-zinc-700">
                            <td class="px-3 py-2 break-words whitespace-normal text-zinc-700 dark:text-zinc-300">
                                {{ $item->price?->billableItem?->descripcion_nomen_tipo }}
                            </td>
                            <td class="px-3 py-2 text-end text-zinc-500">{{ (int) $item->cantidad_detalle }}</td>
                            <td class="px-3 py-2 text-end whitespace-nowrap text-zinc-500">S/ {{ number_format($item->precio_detalle, 2) }}</td>
                            <td class="px-3 py-2 text-end whitespace-nowrap text-zinc-500">S/ {{ number_format($item->total_detalle, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="flex justify-end">
            <flux:heading size="lg">Total: S/ {{ number_format($this->document->total_doc, 2) }}</flux:heading>
        </div>

        <div class="flex flex-wrap gap-2 print:hidden">
            <flux:button
                variant="ghost"
                icon="printer"
                x-on:click="$dispatch('imprimir-ticket')"
            >
                {{ $this->timesPrinted > 0 ? 'Reimprimir boleta' : 'Imprimir boleta' }}
            </flux:button>

            @if ($this->timesPrinted > 0)
                <flux:text class="self-center text-xs text-zinc-500">
                    Impresa {{ $this->timesPrinted }} {{ Str::plural('vez', $this->timesPrinted, 'veces') }}; las copias salen marcadas como reimpresión.
                </flux:text>
            @endif

            @if (! $this->document->isVoided() && ! $this->voidRequest?->isPending() && $this->canRequestVoid && ! $showVoidForm)
                <flux:button variant="danger" wire:click="$set('showVoidForm', true)" icon="x-circle">Solicitar anulación</flux:button>
            @endif
        </div>

        @if ($showVoidForm)
            <div class="space-y-3 rounded-lg border border-red-200 bg-red-50/50 p-4 print:hidden dark:border-red-900 dark:bg-red-500/5">
                <div class="flex items-start gap-2">
                    <flux:icon.information-circle class="mt-0.5 size-5 shrink-0 text-amber-500" />
                    <flux:text class="text-sm">
                        La anulación no es inmediata: queda registrada como solicitud y debe ser aprobada por el
                        <strong>Jefe de la Unidad de Economía</strong> o el <strong>Cajero central</strong>.
                    </flux:text>
                </div>

                <flux:select wire:model="voidReason" placeholder="Selecciona el motivo de anulación">
                    @foreach ($this->voidReasons as $reason)
                        <flux:select.option value="{{ $reason->cod_motiv_anu }}">{{ $reason->descripcion_anulacion }}</flux:select.option>
                    @endforeach
                </flux:select>
                @error('voidReason') <flux:text class="text-sm text-red-600">{{ $message }}</flux:text> @enderror

                <flux:textarea wire:model="voidNotes" rows="2" placeholder="Observación para el aprobador (opcional)" />

                <div class="flex gap-2">
                    <flux:button variant="danger" wire:click="requestVoid">Enviar solicitud</flux:button>
                    <flux:button variant="ghost" wire:click="$set('showVoidForm', false)">Cancelar</flux:button>
                </div>
            </div>
        @endif
    </flux:card>
</section>
