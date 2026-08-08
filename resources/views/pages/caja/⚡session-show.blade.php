<?php

use App\Models\Caja\CashSession;
use App\Models\Caja\ChargeDocument;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Detalle de turno')] class extends Component {
    public string $sessionCode;

    public function mount(string $sessionCode): void
    {
        $this->sessionCode = $sessionCode;
    }

    #[Computed]
    public function session(): CashSession
    {
        return CashSession::query()->findOrFail($this->sessionCode);
    }

    #[Computed]
    public function cashier()
    {
        return DB::connection('caja')->table('Usuario')->where('cod_usu', $this->session->cod_usu)->first();
    }

    #[Computed]
    public function documents()
    {
        return ChargeDocument::query()
            ->with('paymentMethod')
            ->where('cod_aper_cierre_caja', $this->sessionCode)
            ->orderByDesc('id_documento')
            ->get();
    }

    #[Computed]
    public function totals(): array
    {
        $docs = $this->documents;
        $vigentes = $docs->where('estado_doc', ChargeDocument::ESTADO_EMITIDO);

        return [
            'recaudado' => (float) $vigentes->sum('total_doc'),
            'boletas' => $vigentes->count(),
            'anuladas' => $docs->where('estado_doc', ChargeDocument::ESTADO_ANULADO)->count(),
            'perdido' => (float) $docs->where('estado_doc', ChargeDocument::ESTADO_ANULADO)->sum('total_doc'),
        ];
    }

    /** Cuadre del turno: cuanto entro por cada forma de pago. */
    #[Computed]
    public function byPaymentMethod()
    {
        return DB::connection('caja')
            ->table('Cabecera_documento_MH as d')
            ->leftJoin('Jerarquia_Forma_Pago_MH as pm', 'pm.cod_jerar_forma_pago', '=', 'd.cod_jerar_forma_pago')
            ->where('d.cod_aper_cierre_caja', $this->sessionCode)
            ->where('d.estado_doc', ChargeDocument::ESTADO_EMITIDO)
            ->selectRaw("COALESCE(pm.nom_forma_pago, 'Sin forma de pago') as forma, COALESCE(pm.fp_padre, 'Otros') as grupo, COUNT(*) as boletas, SUM(d.total_doc) as total")
            ->groupBy('pm.nom_forma_pago', 'pm.fp_padre')
            ->orderByDesc('total')
            ->get();
    }
}; ?>

<section class="w-full max-w-5xl mx-auto space-y-6">
    <div>
        <flux:button href="{{ route('caja.cashiers.index') }}" wire:navigate variant="ghost" size="sm" icon="arrow-left">Volver a cajeros</flux:button>
    </div>

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">Turno {{ $this->session->cod_aper_cierre_caja }}</flux:heading>
            <div class="mt-1 flex items-center gap-2">
                <flux:icon.user class="size-4 text-zinc-400" />
                <flux:text class="font-medium">{{ $this->cashier?->nom_usu ?? 'Sin nombre' }}</flux:text>
                <flux:text class="text-xs text-zinc-400">{{ $this->session->cod_usu }}</flux:text>
            </div>
        </div>
        <flux:badge :color="$this->session->isOpen() ? 'amber' : 'zinc'">
            {{ $this->session->isOpen() ? 'Turno abierto' : 'Turno cerrado' }}
        </flux:badge>
    </div>

    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-white/10 dark:bg-white/5">
            <flux:text class="text-sm text-zinc-500">Recaudado en el turno</flux:text>
            <div class="mt-1 text-2xl font-semibold text-emerald-600 dark:text-emerald-400">S/ {{ number_format($this->totals['recaudado'], 2) }}</div>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-white/10 dark:bg-white/5">
            <flux:text class="text-sm text-zinc-500">Comprobantes</flux:text>
            <div class="mt-1 text-2xl font-semibold">{{ $this->totals['boletas'] }}</div>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-white/10 dark:bg-white/5">
            <flux:text class="text-sm text-zinc-500">Anulados</flux:text>
            <div class="mt-1 text-2xl font-semibold {{ $this->totals['anuladas'] > 0 ? 'text-red-600 dark:text-red-400' : '' }}">{{ $this->totals['anuladas'] }}</div>
            <flux:text class="text-xs text-zinc-500">S/ {{ number_format($this->totals['perdido'], 2) }}</flux:text>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-white/10 dark:bg-white/5">
            <flux:text class="text-sm text-zinc-500">Apertura</flux:text>
            <div class="mt-1 text-sm font-medium">{{ $this->session->fecha_apertura }}</div>
            <flux:text class="text-xs text-zinc-500">{{ $this->session->hora_apertura }}</flux:text>
            @if (! $this->session->isOpen())
                <flux:text class="mt-2 block text-xs text-zinc-500">Cierre: {{ $this->session->fecha_cierre }} {{ $this->session->hora_cierre }}</flux:text>
            @endif
        </div>
    </div>

    {{-- Cuadre por forma de pago --}}
    <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-white/10 dark:bg-white/5">
        <flux:subheading class="mb-4">Cuadre por forma de pago</flux:subheading>

        @if ($this->byPaymentMethod->isEmpty())
            <flux:text class="text-sm text-zinc-500">Este turno no tiene cobros registrados.</flux:text>
        @else
            @php $maxPm = $this->byPaymentMethod->max('total') ?: 1; @endphp
            <div class="space-y-3">
                @foreach ($this->byPaymentMethod as $pm)
                    <div>
                        <div class="flex items-center justify-between text-sm">
                            <span>{{ $pm->forma }} <span class="text-xs text-zinc-400">({{ $pm->grupo }})</span></span>
                            <span class="font-medium">S/ {{ number_format($pm->total, 2) }} <span class="text-xs text-zinc-500">· {{ $pm->boletas }} bol.</span></span>
                        </div>
                        <div class="mt-1 h-2 w-full rounded-full bg-zinc-100 dark:bg-white/10">
                            <div class="h-2 rounded-full bg-emerald-500 dark:bg-emerald-400" style="width: {{ max(4, round($pm->total / $maxPm * 100)) }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Boletas del turno --}}
    <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-white/10">
        <div class="border-b border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-white/10 dark:bg-white/5">
            <flux:subheading>Comprobantes emitidos en este turno</flux:subheading>
        </div>

        <div class="max-h-[32rem] divide-y overflow-y-auto dark:divide-white/10">
            @forelse ($this->documents as $doc)
                <a href="{{ route('caja.charges.show', $doc->id_documento) }}" wire:navigate class="flex items-center gap-4 px-4 py-3 hover:bg-zinc-50 dark:hover:bg-white/5">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <span class="font-medium">{{ $doc->num_documento }}</span>
                            @if ($doc->isVoided())
                                <flux:badge color="red" size="sm">Anulado</flux:badge>
                            @endif
                        </div>
                        <div class="mt-0.5 truncate text-xs text-zinc-500">
                            {{ $doc->cliente }} · {{ $doc->paymentMethod?->nom_forma_pago }} · {{ $doc->hora_actu }}
                        </div>
                    </div>
                    <div class="shrink-0 font-semibold {{ $doc->isVoided() ? 'text-zinc-400 line-through' : '' }}">
                        S/ {{ number_format($doc->total_doc, 2) }}
                    </div>
                    <flux:icon.chevron-right class="size-4 shrink-0 text-zinc-400" />
                </a>
            @empty
                <div class="px-4 py-8 text-center text-sm text-zinc-500">Este turno aún no tiene comprobantes.</div>
            @endforelse
        </div>
    </div>
</section>
