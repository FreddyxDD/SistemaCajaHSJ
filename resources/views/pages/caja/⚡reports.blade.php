<?php

use App\Models\Caja\ChargeDocument;
use App\Support\Caja\LegacyDate;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Reportes de caja')] class extends Component {
    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function mount(): void
    {
        $this->from = $this->from !== '' ? $this->from : now()->startOfMonth()->format('Y-m-d');
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

    private function dateExpr(string $column = 'fecha_actu'): string
    {
        return LegacyDate::sqlToDate($column);
    }

    #[Computed]
    public function summary(): array
    {
        $row = ChargeDocument::query()
            ->whereRaw($this->dateExpr().' between ? and ?', [$this->from, $this->to])
            ->where('estado_doc', ChargeDocument::ESTADO_EMITIDO)
            ->selectRaw('COUNT(*) as cobros, COALESCE(SUM(total_doc), 0) as total')
            ->first();

        $voided = ChargeDocument::query()
            ->whereRaw($this->dateExpr().' between ? and ?', [$this->from, $this->to])
            ->where('estado_doc', ChargeDocument::ESTADO_ANULADO)
            ->selectRaw('COUNT(*) as cobros, COALESCE(SUM(total_doc), 0) as total')
            ->first();

        return [
            'total' => (float) ($row->total ?? 0),
            'cobros' => (int) ($row->cobros ?? 0),
            'ticket_promedio' => $row->cobros > 0 ? $row->total / $row->cobros : 0,
            'anulados_cobros' => (int) ($voided->cobros ?? 0),
            'anulados_total' => (float) ($voided->total ?? 0),
        ];
    }

    #[Computed]
    public function byPaymentGroup()
    {
        return DB::connection('caja')
            ->table('Cabecera_documento_MH as doc')
            ->join('Jerarquia_Forma_Pago_MH as pm', 'pm.cod_jerar_forma_pago', '=', 'doc.cod_jerar_forma_pago')
            ->whereRaw($this->dateExpr('doc.fecha_actu').' between ? and ?', [$this->from, $this->to])
            ->where('doc.estado_doc', ChargeDocument::ESTADO_EMITIDO)
            ->selectRaw("COALESCE(pm.fp_padre, 'Otros') as grupo, SUM(doc.total_doc) as total, COUNT(*) as cobros")
            ->groupBy('pm.fp_padre')
            ->orderByDesc('total')
            ->get();
    }

    #[Computed]
    public function byDay()
    {
        $expr = $this->dateExpr();

        return DB::connection('caja')
            ->table('Cabecera_documento_MH')
            ->where('estado_doc', ChargeDocument::ESTADO_EMITIDO)
            ->whereRaw("{$expr} between ? and ?", [$this->from, $this->to])
            ->selectRaw("{$expr} as dia, SUM(total_doc) as total, COUNT(*) as cobros")
            ->groupBy(DB::raw($expr))
            ->orderBy('dia')
            ->get();
    }

    #[Computed]
    public function topServices()
    {
        return DB::connection('caja')
            ->table('Detalle_documento_MH as det')
            ->join('Cabecera_documento_MH as doc', 'doc.id_documento', '=', 'det.id_documento')
            ->join('Precio_MH as precio', 'precio.cod_precio', '=', 'det.cod_precio')
            ->join('Nomenclatura_caja_MH as item', 'item.cod_nomen_caja', '=', 'precio.cod_nomen_caja')
            ->where('doc.estado_doc', ChargeDocument::ESTADO_EMITIDO)
            ->whereRaw($this->dateExpr('doc.fecha_actu').' between ? and ?', [$this->from, $this->to])
            ->selectRaw('item.descripcion_nomen_tipo as servicio, SUM(det.total_detalle) as total, SUM(det.cantidad_detalle) as cantidad')
            ->groupBy('item.descripcion_nomen_tipo')
            ->orderByDesc('total')
            ->limit(10)
            ->get();
    }
}; ?>

<section class="w-full space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading size="xl">Reportes de caja</flux:heading>
            <flux:text class="text-zinc-500">Cuadre y analítica de recaudación por periodo.</flux:text>
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

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="acrilico p-5">
            <flux:text class="text-sm text-zinc-500">Total recaudado</flux:text>
            <div class="mt-1 text-2xl font-semibold text-accent">S/ {{ number_format($this->summary['total'], 2) }}</div>
        </div>
        <div class="acrilico p-5">
            <flux:text class="text-sm text-zinc-500">Comprobantes emitidos</flux:text>
            <div class="mt-1 text-2xl font-semibold">{{ $this->summary['cobros'] }}</div>
        </div>
        <div class="acrilico p-5">
            <flux:text class="text-sm text-zinc-500">Ticket promedio</flux:text>
            <div class="mt-1 text-2xl font-semibold">S/ {{ number_format($this->summary['ticket_promedio'], 2) }}</div>
        </div>
        <div class="acrilico p-5">
            <flux:text class="text-sm text-zinc-500">Anulados</flux:text>
            <div class="mt-1 text-2xl font-semibold {{ $this->summary['anulados_cobros'] > 0 ? 'text-red-600 dark:text-red-400' : '' }}">
                {{ $this->summary['anulados_cobros'] }}
            </div>
            <flux:text class="text-xs text-zinc-500">S/ {{ number_format($this->summary['anulados_total'], 2) }} no cobrados</flux:text>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <div class="acrilico p-5">
            <flux:subheading class="mb-4">Recaudación por forma de pago</flux:subheading>

            @if ($this->byPaymentGroup->isEmpty())
                <flux:text class="text-sm text-zinc-500">Sin cobros en el periodo seleccionado.</flux:text>
            @else
                @php
                    $groupTotal = $this->byPaymentGroup->sum('total') ?: 1;
                @endphp
                {{-- El margen negativo deja que el scroll llegue al borde de la tarjeta. --}}
                <div class="-mx-5 overflow-x-auto px-5">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Grupo</flux:table.column>
                        <flux:table.column>Cobros</flux:table.column>
                        <flux:table.column>Total</flux:table.column>
                        <flux:table.column>%</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($this->byPaymentGroup as $group)
                            <flux:table.row>
                                <flux:table.cell>{{ $group->grupo }}</flux:table.cell>
                                <flux:table.cell>{{ $group->cobros }}</flux:table.cell>
                                <flux:table.cell>S/ {{ number_format($group->total, 2) }}</flux:table.cell>
                                <flux:table.cell>{{ number_format($group->total / $groupTotal * 100, 1) }}%</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
                </div>
            @endif
        </div>

        <div class="acrilico p-5">
            <flux:subheading class="mb-4">Top 10 servicios facturados</flux:subheading>

            @if ($this->topServices->isEmpty())
                <flux:text class="text-sm text-zinc-500">Sin datos en el periodo seleccionado.</flux:text>
            @else
                <div class="max-h-80 space-y-2 overflow-y-auto">
                    @foreach ($this->topServices as $service)
                        <div class="flex items-center justify-between gap-4 text-sm">
                            <span class="line-clamp-1 text-zinc-700 dark:text-zinc-300">{{ $service->servicio }}</span>
                            <span class="shrink-0 text-zinc-500">×{{ (int) $service->cantidad }}</span>
                            <span class="shrink-0 font-medium">S/ {{ number_format($service->total, 2) }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <div class="acrilico p-5">
        <flux:subheading class="mb-4">Recaudación por día</flux:subheading>

        @if ($this->byDay->isEmpty())
            <flux:text class="text-sm text-zinc-500">Sin cobros en el periodo seleccionado.</flux:text>
        @else
            @php $maxDay = $this->byDay->max('total') ?: 1; @endphp
            <div class="flex h-40 items-end gap-1.5 overflow-x-auto">
                @foreach ($this->byDay as $day)
                    <div class="flex h-full min-w-8 flex-1 flex-col items-center justify-end gap-1" title="{{ \Illuminate\Support\Carbon::parse($day->dia)->format('d/m/Y') }}: S/ {{ number_format($day->total, 2) }}">
                        <div class="flex w-full flex-1 items-end rounded-md bg-zinc-100 dark:bg-white/10">
                            <div class="bar-grow w-full rounded-md bg-accent" style="height: {{ max(4, round($day->total / $maxDay * 100)) }}%"></div>
                        </div>
                        <flux:text class="text-[10px] text-zinc-500">{{ \Illuminate\Support\Carbon::parse($day->dia)->format('d/m') }}</flux:text>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</section>
