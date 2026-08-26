<?php

use App\Models\Caja\CashSession;
use App\Models\Caja\ChargeDocument;
use App\Models\VoidRequest;
use App\Support\Caja\LegacyDate;
use App\Support\Caja\LegacyIdGenerator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Panel')] class extends Component {
    /**
     * Quien solo consulta precios (asistentas sociales, personal de servicios) no
     * tiene nada que hacer en el panel de recaudacion: se le lleva directo a su
     * pantalla en vez de mostrarle indicadores en blanco.
     */
    public function mount()
    {
        $user = Auth::user();

        if (! $user->canDo('caja.view') && $user->canDo('caja.prices.view')) {
            return $this->redirectRoute('caja.prices.lookup', navigate: true);
        }
    }

    #[Computed]
    public function pendingVoids()
    {
        if (! Auth::user()->canDo('caja.void.approve')) {
            return collect();
        }

        return VoidRequest::query()->pending()->orderByDesc('requested_at')->limit(5)->get();
    }

    /** Ranking de cajeros del dia: quien esta recaudando y cuanto. */
    #[Computed]
    public function cashiersToday()
    {
        if (! Auth::user()->canDo('caja.cashiers.view')) {
            return collect();
        }

        return DB::connection('caja')
            ->table('CAJA_APERTURA_CIERRE as s')
            ->leftJoin('Usuario as u', 'u.cod_usu', '=', 's.cod_usu')
            ->leftJoin('Cabecera_documento_MH as d', 'd.cod_aper_cierre_caja', '=', 's.cod_aper_cierre_caja')
            ->where('s.fecha_apertura', LegacyDate::format(now()))
            ->selectRaw("
                s.cod_usu,
                MAX(u.nom_usu) as nombre,
                MAX(s.estado_aper_cierre_caja) as estado,
                SUM(CASE WHEN d.estado_doc = ? THEN 1 ELSE 0 END) as boletas,
                SUM(CASE WHEN d.estado_doc = ? THEN d.total_doc ELSE 0 END) as recaudado
            ", [ChargeDocument::ESTADO_EMITIDO, ChargeDocument::ESTADO_EMITIDO])
            ->groupBy('s.cod_usu')
            ->orderByDesc('recaudado')
            ->limit(6)
            ->get();
    }

    #[Computed]
    public function mySession(): ?CashSession
    {
        return CashSession::query()
            ->open()
            ->where('cod_usu', LegacyIdGenerator::legacyUserCode(Auth::user()))
            ->first();
    }

    #[Computed]
    public function openSessionsCount(): int
    {
        return CashSession::query()->open()->count();
    }

    #[Computed]
    public function today(): array
    {
        $today = LegacyDate::format(now());

        $row = ChargeDocument::query()
            ->where('fecha_actu', $today)
            ->where('estado_doc', ChargeDocument::ESTADO_EMITIDO)
            ->selectRaw('COUNT(*) as cobros, COALESCE(SUM(total_doc), 0) as total')
            ->first();

        $voided = ChargeDocument::query()
            ->where('fecha_actu', $today)
            ->where('estado_doc', ChargeDocument::ESTADO_ANULADO)
            ->count();

        return [
            'total' => (float) ($row->total ?? 0),
            'cobros' => (int) ($row->cobros ?? 0),
            'ticket_promedio' => $row->cobros > 0 ? $row->total / $row->cobros : 0,
            'anulados' => $voided,
        ];
    }

    #[Computed]
    public function todayByPaymentGroup()
    {
        return DB::connection('caja')
            ->table('Cabecera_documento_MH as doc')
            ->join('Jerarquia_Forma_Pago_MH as pm', 'pm.cod_jerar_forma_pago', '=', 'doc.cod_jerar_forma_pago')
            ->where('doc.fecha_actu', LegacyDate::format(now()))
            ->where('doc.estado_doc', ChargeDocument::ESTADO_EMITIDO)
            ->selectRaw("COALESCE(pm.fp_padre, 'Otros') as grupo, SUM(doc.total_doc) as total, COUNT(*) as cobros")
            ->groupBy('pm.fp_padre')
            ->orderByDesc('total')
            ->get();
    }

    #[Computed]
    public function last7Days()
    {
        $since = now()->subDays(6)->startOfDay();
        $dateExpr = LegacyDate::sqlToDate('fecha_actu');

        $rows = DB::connection('caja')
            ->table('Cabecera_documento_MH')
            ->where('estado_doc', ChargeDocument::ESTADO_EMITIDO)
            ->whereRaw("{$dateExpr} >= ?", [$since->format('Y-m-d')])
            ->selectRaw("{$dateExpr} as dia, SUM(total_doc) as total, COUNT(*) as cobros")
            ->groupBy(DB::raw($dateExpr))
            ->get()
            ->keyBy(fn ($row) => Carbon::parse($row->dia)->format('Y-m-d'));

        $days = collect(range(0, 6))->map(function ($offset) use ($rows) {
            $date = now()->subDays(6 - $offset)->startOfDay();
            $key = $date->format('Y-m-d');
            $row = $rows->get($key);

            return [
                'label' => $date->translatedFormat('D d'),
                'total' => (float) ($row->total ?? 0),
                'cobros' => (int) ($row->cobros ?? 0),
            ];
        });

        $max = $days->max('total') ?: 1;

        return $days->map(fn ($day) => [...$day, 'pct' => max(4, round($day['total'] / $max * 100))]);
    }

    #[Computed]
    public function topServices()
    {
        $since = LegacyDate::format(now()->subDays(30));

        return DB::connection('caja')
            ->table('Detalle_documento_MH as det')
            ->join('Cabecera_documento_MH as doc', 'doc.id_documento', '=', 'det.id_documento')
            ->join('Precio_MH as precio', 'precio.cod_precio', '=', 'det.cod_precio')
            ->join('Nomenclatura_caja_MH as item', 'item.cod_nomen_caja', '=', 'precio.cod_nomen_caja')
            ->where('doc.estado_doc', ChargeDocument::ESTADO_EMITIDO)
            ->whereRaw(LegacyDate::sqlToDate('doc.fecha_actu').' >= ?', [now()->subDays(30)->format('Y-m-d')])
            ->selectRaw('item.descripcion_nomen_tipo as servicio, SUM(det.total_detalle) as total, SUM(det.cantidad_detalle) as cantidad')
            ->groupBy('item.descripcion_nomen_tipo')
            ->orderByDesc('total')
            ->limit(5)
            ->get();
    }

    #[Computed]
    public function recentCharges()
    {
        return ChargeDocument::query()
            ->orderByDesc('id_documento')
            ->limit(6)
            ->get();
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">Panel de recaudación</flux:heading>
            <flux:text class="text-zinc-500">Hoy, {{ now()->translatedFormat('l d \d\e F \d\e Y') }}</flux:text>
        </div>

        @if ($this->mySession)
            <flux:badge color="green">Tu turno: {{ $this->mySession->cod_aper_cierre_caja }}</flux:badge>
        @else
            <flux:button href="{{ route('caja.sessions.index') }}" variant="primary" size="sm">Abrir turno de caja</flux:button>
        @endif
    </div>

    {{-- Alerta accionable: anulaciones esperando aprobacion --}}
    @if ($this->pendingVoids->isNotEmpty())
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-500/30 dark:bg-amber-400/10">
            <div class="flex items-start justify-between gap-4">
                <div class="flex items-start gap-3">
                    <flux:icon.exclamation-triangle class="mt-0.5 size-5 shrink-0 text-amber-600 dark:text-amber-400" />
                    <div>
                        <flux:text class="font-medium text-amber-900 dark:text-amber-300">
                            {{ $this->pendingVoids->count() }} {{ Str::plural('anulación', $this->pendingVoids->count()) }} {{ Str::plural('pendiente', $this->pendingVoids->count()) }} de tu aprobación
                        </flux:text>
                        <flux:text class="block text-sm text-amber-800 dark:text-amber-400/80">
                            {{ $this->pendingVoids->take(2)->map(fn ($v) => $v->document_number.' (S/ '.number_format($v->document_total, 2).')')->implode(', ') }}@if ($this->pendingVoids->count() > 2), …@endif
                        </flux:text>
                    </div>
                </div>
                <flux:button href="{{ route('caja.void-requests.index') }}" wire:navigate size="sm" variant="primary">Revisar</flux:button>
            </div>
        </div>
    @endif

    {{-- KPIs principales --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="relative overflow-hidden acrilico p-5">
            <div class="absolute -top-6 -right-6 h-24 w-24 rounded-full bg-accent/10"></div>
            <flux:text class="text-sm text-zinc-500">Recaudado hoy</flux:text>
            <div class="mt-1 text-3xl font-semibold text-accent">S/ {{ number_format($this->today['total'], 2) }}</div>
            <flux:text class="mt-1 text-xs text-zinc-500">{{ $this->today['cobros'] }} {{ Str::plural('cobro', $this->today['cobros']) }} emitidos</flux:text>
        </div>

        <div class="relative overflow-hidden acrilico p-5">
            <flux:text class="text-sm text-zinc-500">Ticket promedio hoy</flux:text>
            <div class="mt-1 text-3xl font-semibold">S/ {{ number_format($this->today['ticket_promedio'], 2) }}</div>
            <flux:text class="mt-1 text-xs text-zinc-500">Por comprobante emitido</flux:text>
        </div>

        <div class="relative overflow-hidden acrilico p-5">
            <flux:text class="text-sm text-zinc-500">Cajas abiertas ahora</flux:text>
            <div class="mt-1 text-3xl font-semibold">{{ $this->openSessionsCount }}</div>
            <flux:text class="mt-1 text-xs text-zinc-500">Turnos activos en todo el hospital</flux:text>
        </div>

        <div class="relative overflow-hidden acrilico p-5">
            <flux:text class="text-sm text-zinc-500">Anulados hoy</flux:text>
            <div class="mt-1 text-3xl font-semibold {{ $this->today['anulados'] > 0 ? 'text-red-600 dark:text-red-400' : '' }}">{{ $this->today['anulados'] }}</div>
            <flux:text class="mt-1 text-xs text-zinc-500">Comprobantes anulados</flux:text>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        {{-- Tendencia 7 dias --}}
        <div class="acrilico p-5 lg:col-span-2">
            <flux:subheading class="mb-4">Recaudación — últimos 7 días</flux:subheading>
            <div class="flex h-40 items-end gap-3">
                @foreach ($this->last7Days as $day)
                    <div class="flex flex-1 flex-col items-center gap-2">
                        <flux:text class="text-xs text-zinc-500">S/ {{ number_format($day['total'], 0) }}</flux:text>
                        <div class="flex h-28 w-full items-end rounded-md bg-zinc-100 dark:bg-white/10">
                            <div class="bar-grow w-full rounded-md bg-accent" style="height: {{ $day['pct'] }}%"></div>
                        </div>
                        <flux:text class="text-xs text-zinc-500 capitalize">{{ $day['label'] }}</flux:text>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Recaudado por forma de pago --}}
        <div class="acrilico p-5">
            <flux:subheading class="mb-4">Hoy por forma de pago</flux:subheading>

            @if ($this->todayByPaymentGroup->isEmpty())
                <flux:text class="text-sm text-zinc-500">Sin cobros registrados hoy.</flux:text>
            @else
                @php $maxGroup = $this->todayByPaymentGroup->max('total') ?: 1; @endphp
                <div class="space-y-3">
                    @foreach ($this->todayByPaymentGroup as $group)
                        <div>
                            <div class="flex items-center justify-between text-sm">
                                <span>{{ $group->grupo }}</span>
                                <span class="font-medium">S/ {{ number_format($group->total, 2) }}</span>
                            </div>
                            <div class="mt-1 h-2 w-full rounded-full bg-zinc-100 dark:bg-white/10">
                                <div class="h-2 rounded-full bg-accent" style="width: {{ max(4, round($group->total / $maxGroup * 100)) }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- Cajeros activos hoy --}}
    @if ($this->cashiersToday->isNotEmpty())
        <div class="acrilico p-5">
            <div class="mb-4 flex items-center justify-between">
                <flux:subheading>Cajeros hoy</flux:subheading>
                <flux:link href="{{ route('caja.cashiers.index') }}" wire:navigate class="text-sm">Ver todos</flux:link>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($this->cashiersToday as $c)
                    <div class="flex items-center gap-3 rounded-lg border border-zinc-200 p-3 dark:border-white/10">
                        <div class="flex size-9 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-xs font-semibold text-zinc-600 dark:bg-white/10 dark:text-zinc-300">
                            {{ \Illuminate\Support\Str::of($c->nombre ?? $c->cod_usu)->explode(' ')->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('') }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm font-medium">{{ $c->nombre ?? $c->cod_usu }}</div>
                            <div class="flex items-center gap-1.5 text-xs text-zinc-500">
                                <span>{{ $c->boletas }} bol.</span>
                                @if ($c->estado === 'P')
                                    <flux:badge color="amber" size="sm">abierto</flux:badge>
                                @endif
                            </div>
                        </div>
                        <div class="shrink-0 text-sm font-semibold text-accent">
                            S/ {{ number_format($c->recaudado, 2) }}
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        {{-- Top servicios --}}
        <div class="acrilico p-5">
            <div class="mb-4 flex items-center justify-between">
                <flux:subheading>Top servicios — últimos 30 días</flux:subheading>
                <flux:link href="{{ route('caja.reports.index') }}" wire:navigate class="text-sm">Ver reportes</flux:link>
            </div>

            @if ($this->topServices->isEmpty())
                <flux:text class="text-sm text-zinc-500">Sin datos en este periodo.</flux:text>
            @else
                <div class="space-y-2">
                    @foreach ($this->topServices as $service)
                        <div class="flex items-center justify-between gap-4 text-sm">
                            <span class="line-clamp-1 text-zinc-700 dark:text-zinc-300">{{ $service->servicio }}</span>
                            <span class="shrink-0 font-medium">S/ {{ number_format($service->total, 2) }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Cobros recientes --}}
        <div class="acrilico p-5">
            <div class="mb-4 flex items-center justify-between">
                <flux:subheading>Cobros recientes</flux:subheading>
                <flux:link href="{{ route('caja.charges.index') }}" wire:navigate class="text-sm">Ver todos</flux:link>
            </div>

            @if ($this->recentCharges->isEmpty())
                <flux:text class="text-sm text-zinc-500">Aún no hay cobros registrados.</flux:text>
            @else
                <div class="space-y-2">
                    @foreach ($this->recentCharges as $charge)
                        <flux:link href="{{ route('caja.charges.show', $charge->id_documento) }}" wire:navigate class="flex items-center justify-between gap-4 text-sm no-underline!">
                            <span class="line-clamp-1 text-zinc-700 dark:text-zinc-300">{{ $charge->cliente }}</span>
                            <span class="shrink-0 font-medium">S/ {{ number_format($charge->total_doc, 2) }}</span>
                        </flux:link>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
