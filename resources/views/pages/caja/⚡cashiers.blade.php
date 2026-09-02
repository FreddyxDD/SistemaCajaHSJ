<?php

use App\Models\Caja\CashSession;
use App\Models\Caja\ChargeDocument;
use App\Support\Caja\LegacyDate;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Cajeros y turnos')] class extends Component {
    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public ?string $cashier = null;

    public function mount(): void
    {
        $this->from = $this->from !== '' ? $this->from : now()->subDays(6)->format('Y-m-d');
        $this->to = $this->to !== '' ? $this->to : now()->format('Y-m-d');
    }

    public function setRange(string $preset): void
    {
        $this->to = now()->format('Y-m-d');
        $this->from = match ($preset) {
            'today' => now()->format('Y-m-d'),
            '7d' => now()->subDays(6)->format('Y-m-d'),
            '30d' => now()->subDays(29)->format('Y-m-d'),
            'month' => now()->startOfMonth()->format('Y-m-d'),
            default => $this->from,
        };
    }

    public function selectCashier(?string $code): void
    {
        $this->cashier = $this->cashier === $code ? null : $code;
    }

    /**
     * Turnos del rango con su cajero y totales. Es la base de las dos tablas:
     * se agrupa por cajero para el resumen y se filtra para el detalle.
     */
    private function sessionsQuery()
    {
        return DB::connection('caja')
            ->table('CAJA_APERTURA_CIERRE as s')
            ->leftJoin('Usuario as u', 'u.cod_usu', '=', 's.cod_usu')
            ->leftJoin('Cabecera_documento_MH as d', 'd.cod_aper_cierre_caja', '=', 's.cod_aper_cierre_caja')
            ->whereRaw(LegacyDate::sqlToDate('s.fecha_apertura').' between ? and ?', [$this->from, $this->to]);
    }

    #[Computed]
    public function cashiers()
    {
        return $this->sessionsQuery()
            ->selectRaw("
                s.cod_usu,
                MAX(u.nom_usu) as nombre,
                COUNT(DISTINCT s.cod_aper_cierre_caja) as turnos,
                SUM(CASE WHEN d.estado_doc = ? THEN 1 ELSE 0 END) as boletas,
                SUM(CASE WHEN d.estado_doc = ? THEN d.total_doc ELSE 0 END) as recaudado,
                SUM(CASE WHEN d.estado_doc = ? THEN 1 ELSE 0 END) as anuladas,
                -- DISTINCT obligatorio: el LEFT JOIN de documentos repite la fila del
                -- turno una vez por comprobante, y un SUM simple contaria de mas.
                COUNT(DISTINCT CASE WHEN s.estado_aper_cierre_caja = 'P' THEN s.cod_aper_cierre_caja END) as turnos_abiertos
            ", [ChargeDocument::ESTADO_EMITIDO, ChargeDocument::ESTADO_EMITIDO, ChargeDocument::ESTADO_ANULADO])
            ->groupBy('s.cod_usu')
            ->orderByDesc('recaudado')
            ->get();
    }

    #[Computed]
    public function sessions()
    {
        if (! $this->cashier) {
            return collect();
        }

        return $this->sessionsQuery()
            ->where('s.cod_usu', $this->cashier)
            ->selectRaw("
                s.cod_aper_cierre_caja,
                MAX(s.fecha_apertura) as fecha_apertura,
                MAX(s.hora_apertura) as hora_apertura,
                MAX(s.fecha_cierre) as fecha_cierre,
                MAX(s.hora_cierre) as hora_cierre,
                MAX(s.estado_aper_cierre_caja) as estado,
                SUM(CASE WHEN d.estado_doc = ? THEN 1 ELSE 0 END) as boletas,
                SUM(CASE WHEN d.estado_doc = ? THEN d.total_doc ELSE 0 END) as recaudado,
                SUM(CASE WHEN d.estado_doc = ? THEN 1 ELSE 0 END) as anuladas
            ", [ChargeDocument::ESTADO_EMITIDO, ChargeDocument::ESTADO_EMITIDO, ChargeDocument::ESTADO_ANULADO])
            ->groupBy('s.cod_aper_cierre_caja')
            ->orderByDesc('s.cod_aper_cierre_caja')
            ->get();
    }

    #[Computed]
    public function selectedCashierName(): ?string
    {
        return $this->cashiers->firstWhere('cod_usu', $this->cashier)?->nombre;
    }

    /**
     * Turnos abiertos que ya pasaron el limite de horas, de cualquier cajero y sin
     * filtro de fecha: son los que descuadran el arqueo, y justamente los mas viejos
     * caen fuera del rango consultado.
     */
    #[Computed]
    public function staleSessions()
    {
        $abiertos = CashSession::query()
            ->open()
            ->orderBy('cod_aper_cierre_caja')
            ->get()
            ->filter->exceedsMaxDuration()
            ->values();

        if ($abiertos->isEmpty()) {
            return $abiertos;
        }

        $nombres = DB::connection('caja')
            ->table('Usuario')
            ->whereIn('cod_usu', $abiertos->pluck('cod_usu'))
            ->pluck('nom_usu', 'cod_usu');

        return $abiertos->each(fn ($session) => $session->setAttribute(
            'nombre_cajero',
            trim((string) ($nombres[$session->cod_usu] ?? '')) ?: $session->cod_usu,
        ));
    }

    #[Computed]
    public function totals(): array
    {
        $rows = $this->cashiers;

        return [
            'cajeros' => $rows->count(),
            'turnos' => (int) $rows->sum('turnos'),
            'abiertos' => (int) $rows->sum('turnos_abiertos'),
            'recaudado' => (float) $rows->sum('recaudado'),
            'boletas' => (int) $rows->sum('boletas'),
            'anuladas' => (int) $rows->sum('anuladas'),
        ];
    }
}; ?>

<section class="w-full space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="xl">Cajeros y turnos</flux:heading>
            <flux:text class="text-zinc-500">Recaudación agrupada por cajero, no boletas sueltas. Haz clic en un cajero para ver sus turnos.</flux:text>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <flux:button size="sm" variant="ghost" wire:click="setRange('today')">Hoy</flux:button>
            <flux:button size="sm" variant="ghost" wire:click="setRange('7d')">7 días</flux:button>
            <flux:button size="sm" variant="ghost" wire:click="setRange('30d')">30 días</flux:button>
            <flux:button size="sm" variant="ghost" wire:click="setRange('month')">Este mes</flux:button>
            <flux:input type="date" wire:model.live="from" class="w-40" />
            <flux:text class="text-zinc-500">a</flux:text>
            <flux:input type="date" wire:model.live="to" class="w-40" />
        </div>
    </div>

    {{-- Turnos que nadie cerró: el desorden que hay que perseguir. No depende del
         filtro de fechas porque los peores casos son siempre los más viejos. --}}
    @if ($this->staleSessions->isNotEmpty())
        <flux:callout variant="warning" heading="{{ $this->staleSessions->count() }} turno(s) abiertos superan las {{ (int) \App\Models\Caja\CashSession::maxHours() }} horas">
            <flux:text class="text-sm">
                Estos turnos siguen aceptando cobros y su cajero no puede abrir uno nuevo hasta cerrarlos.
            </flux:text>

            <div class="mt-3 space-y-2">
                @foreach ($this->staleSessions as $pendiente)
                    <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-white/60 p-2 dark:bg-white/5">
                        <flux:text class="text-sm">
                            <span class="font-semibold">{{ $pendiente->nombre_cajero }}</span>
                            · turno {{ $pendiente->cod_aper_cierre_caja }}
                            · abierto {{ $pendiente->fecha_apertura }} {{ $pendiente->hora_apertura }}
                            · <span class="font-medium text-amber-700 dark:text-amber-300">{{ $pendiente->durationLabel() }}</span>
                        </flux:text>

                        <div class="flex items-center gap-2">
                            <flux:button href="{{ route('caja.sessions.show', $pendiente->cod_aper_cierre_caja) }}" wire:navigate size="xs" variant="ghost">
                                Ver turno
                            </flux:button>
                            <flux:button
                                href="{{ route('caja.sessions.report', [$pendiente->cod_aper_cierre_caja, 'imprimir' => 1]) }}"
                                target="_blank"
                                size="xs"
                                variant="ghost"
                                icon="printer"
                            >
                                Imprimir
                            </flux:button>
                        </div>
                    </div>
                @endforeach
            </div>
        </flux:callout>
    @endif

    {{-- Resumen del periodo --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="acrilico p-5">
            <div class="flex items-center gap-2">
                <flux:icon.users class="size-4 text-zinc-400" />
                <flux:text class="text-sm text-zinc-500">Cajeros con actividad</flux:text>
            </div>
            <div class="mt-1 text-3xl font-semibold">{{ $this->totals['cajeros'] }}</div>
            <flux:text class="text-xs text-zinc-500">{{ $this->totals['turnos'] }} turnos en el periodo</flux:text>
        </div>

        <div class="acrilico p-5">
            <div class="flex items-center gap-2">
                <flux:icon.banknotes class="size-4 text-accent" />
                <flux:text class="text-sm text-zinc-500">Recaudado</flux:text>
            </div>
            <div class="mt-1 text-3xl font-semibold text-accent">S/ {{ number_format($this->totals['recaudado'], 2) }}</div>
            <flux:text class="text-xs text-zinc-500">{{ $this->totals['boletas'] }} comprobantes</flux:text>
        </div>

        <div class="acrilico p-5">
            <div class="flex items-center gap-2">
                <flux:icon.clock class="size-4 text-amber-500" />
                <flux:text class="text-sm text-zinc-500">Turnos abiertos</flux:text>
            </div>
            <div class="mt-1 text-3xl font-semibold {{ $this->totals['abiertos'] > 0 ? 'text-amber-600 dark:text-amber-400' : '' }}">{{ $this->totals['abiertos'] }}</div>
            <flux:text class="text-xs text-zinc-500">Sin cerrar en el periodo</flux:text>
        </div>

        <div class="acrilico p-5">
            <div class="flex items-center gap-2">
                <flux:icon.x-circle class="size-4 text-red-500" />
                <flux:text class="text-sm text-zinc-500">Anuladas</flux:text>
            </div>
            <div class="mt-1 text-3xl font-semibold {{ $this->totals['anuladas'] > 0 ? 'text-red-600 dark:text-red-400' : '' }}">{{ $this->totals['anuladas'] }}</div>
            <flux:text class="text-xs text-zinc-500">Comprobantes anulados</flux:text>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-5">
        {{-- Cajeros --}}
        <div class="lg:col-span-2">
            <div class="overflow-hidden acrilico">
                <div class="border-b border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-white/10 dark:bg-white/5">
                    <flux:subheading>Cajeros</flux:subheading>
                </div>

                <div class="max-h-[32rem] divide-y overflow-y-auto dark:divide-white/10">
                    @forelse ($this->cashiers as $c)
                        @php $active = $this->cashier === $c->cod_usu; @endphp
                        <button
                            type="button"
                            wire:click="selectCashier('{{ $c->cod_usu }}')"
                            class="flex w-full items-center gap-3 px-4 py-3 text-left {{ $active ? 'bg-accent/10' : 'hover:bg-zinc-50 dark:hover:bg-white/5' }}"
                        >
                            <div class="flex size-9 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-xs font-semibold text-zinc-600 dark:bg-white/10 dark:text-zinc-300">
                                {{ \Illuminate\Support\Str::of($c->nombre ?? $c->cod_usu)->explode(' ')->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('') }}
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-medium {{ $active ? 'text-accent' : '' }}">
                                    {{ $c->nombre ?? 'Sin nombre' }}
                                </div>
                                <div class="flex items-center gap-2 text-xs text-zinc-500">
                                    <span>{{ $c->cod_usu }}</span>
                                    <span>·</span>
                                    <span>{{ $c->turnos }} {{ \Illuminate\Support\Str::plural('turno', $c->turnos) }}</span>
                                    @if ($c->turnos_abiertos > 0)
                                        <flux:badge color="amber" size="sm">abierto</flux:badge>
                                    @endif
                                </div>
                            </div>
                            <div class="shrink-0 text-right">
                                <div class="text-sm font-semibold">S/ {{ number_format($c->recaudado, 2) }}</div>
                                <div class="text-xs text-zinc-500">{{ $c->boletas }} bol.</div>
                            </div>
                        </button>
                    @empty
                        <div class="px-4 py-8 text-center text-sm text-zinc-500">
                            No hay actividad de caja en el periodo seleccionado.
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- Turnos del cajero seleccionado --}}
        <div class="lg:col-span-3">
            <div class="overflow-hidden acrilico">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-white/10 dark:bg-white/5">
                    <flux:subheading>
                        {{ $this->cashier ? 'Turnos de '.$this->selectedCashierName : 'Turnos' }}
                    </flux:subheading>

                    {{-- El cajero central revisa aquí el mismo reporte que el cajero le entrega. --}}
                    @if ($this->cashier)
                        <div class="flex flex-wrap gap-2">
                            <flux:button
                                href="{{ route('caja.daily-report', ['cajero' => $this->cashier, 'desde' => $from, 'hasta' => $to]) }}"
                                target="_blank"
                                size="xs"
                                variant="ghost"
                                icon="document-text"
                            >
                                Reporte diario A4
                            </flux:button>
                            <flux:button
                                href="{{ route('caja.daily-report', ['cajero' => $this->cashier, 'desde' => $from, 'hasta' => $to, 'formato' => 'ticket']) }}"
                                target="_blank"
                                size="xs"
                                variant="ghost"
                                icon="receipt-percent"
                            >
                                Ticketera
                            </flux:button>
                        </div>
                    @endif
                </div>

                @if (! $this->cashier)
                    <div class="flex flex-col items-center justify-center px-4 py-16 text-center">
                        <flux:icon.cursor-arrow-rays class="size-8 text-zinc-300 dark:text-zinc-600" />
                        <flux:text class="mt-3 text-sm text-zinc-500">
                            Selecciona un cajero de la lista para ver sus turnos, el detalle de lo recaudado
                            y para imprimir su reporte diario en A4 o en ticketera.
                        </flux:text>
                    </div>
                @else
                    <div class="max-h-[32rem] divide-y overflow-y-auto dark:divide-white/10">
                        @foreach ($this->sessions as $s)
                            <a href="{{ route('caja.sessions.show', $s->cod_aper_cierre_caja) }}" wire:navigate class="flex items-center gap-4 px-4 py-3 hover:bg-zinc-50 dark:hover:bg-white/5">
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2">
                                        <span class="font-medium">{{ $s->cod_aper_cierre_caja }}</span>
                                        <flux:badge :color="$s->estado === 'P' ? 'amber' : 'zinc'" size="sm">
                                            {{ $s->estado === 'P' ? 'Abierto' : 'Cerrado' }}
                                        </flux:badge>
                                    </div>
                                    <div class="mt-0.5 text-xs text-zinc-500">
                                        Apertura {{ $s->fecha_apertura }} {{ $s->hora_apertura }}
                                        @if ($s->estado !== 'P')
                                            · Cierre {{ $s->fecha_cierre }} {{ $s->hora_cierre }}
                                        @endif
                                    </div>
                                </div>
                                <div class="shrink-0 text-right">
                                    <div class="font-semibold">S/ {{ number_format($s->recaudado, 2) }}</div>
                                    <div class="text-xs text-zinc-500">
                                        {{ $s->boletas }} bol.
                                        @if ($s->anuladas > 0)
                                            <span class="text-red-600 dark:text-red-400">· {{ $s->anuladas }} anul.</span>
                                        @endif
                                    </div>
                                </div>
                                <flux:icon.chevron-right class="size-4 shrink-0 text-zinc-400" />
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</section>
