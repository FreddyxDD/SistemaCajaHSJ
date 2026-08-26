<?php

use App\Models\Caja\ChargeDocument;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Cobros')] class extends Component {
    use WithPagination;

    public string $q = '';

    #[Computed]
    public function documents()
    {
        return ChargeDocument::query()
            ->when(trim($this->q) !== '', fn ($query) => $query
                ->where('id_documento', 'like', '%'.$this->q.'%')
                ->orWhere('num_documento', 'like', '%'.$this->q.'%')
                ->orWhere('cliente', 'like', '%'.$this->q.'%'))
            // fecha_actu/hora_actu son texto (DD/MM/YYYY), no ordenan cronologicamente;
            // id_documento es un correlativo monotonico y sí refleja el orden real.
            ->orderByDesc('id_documento')
            ->paginate(20);
    }

    public function updatedQ(): void
    {
        $this->resetPage();
    }
}; ?>

<section class="w-full space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:heading size="xl">Cobros</flux:heading>
        <flux:button href="{{ route('caja.charges.create') }}" variant="primary">Nuevo cobro</flux:button>
    </div>

    <flux:input wire:model.live.debounce.400ms="q" placeholder="Buscar por documento o cliente..." />

    {{-- En telefono se ocultan cliente y fecha; quedan documento, total y estado. --}}
    <div class="overflow-x-auto">
    <flux:table :paginate="$this->documents">
        <flux:table.columns>
            <flux:table.column>Documento</flux:table.column>
            <flux:table.column class="max-sm:hidden">Cliente</flux:table.column>
            <flux:table.column>Total</flux:table.column>
            <flux:table.column>Estado</flux:table.column>
            <flux:table.column class="max-sm:hidden">Fecha</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($this->documents as $document)
                <flux:table.row :key="$document->id_documento">
                    <flux:table.cell>
                        <flux:link href="{{ route('caja.charges.show', $document->id_documento) }}">{{ $document->num_documento }}</flux:link>
                    </flux:table.cell>
                    <flux:table.cell class="max-sm:hidden">{{ $document->cliente }}</flux:table.cell>
                    <flux:table.cell>S/ {{ number_format($document->total_doc, 2) }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge :color="$document->isVoided() ? 'red' : 'green'">
                            {{ $document->isVoided() ? 'Anulado' : 'Vigente' }}
                        </flux:badge>
                    </flux:table.cell>
                    <flux:table.cell class="max-sm:hidden">{{ $document->fecha_actu }} {{ $document->hora_actu }}</flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
    </div>
</section>
