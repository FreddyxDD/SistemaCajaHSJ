<?php

use App\Models\Caja\CashSession;
use App\Models\Caja\ChargeDocument;
use App\Support\Caja\LegacyIdGenerator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Turno de caja')] class extends Component {
    #[Computed]
    public function legacyCode(): string
    {
        return LegacyIdGenerator::legacyUserCode(Auth::user());
    }

    #[Computed]
    public function openSession(): ?CashSession
    {
        return $this->openSessions->first();
    }

    /**
     * Todos los turnos abiertos del cajero. Deberia haber como mucho uno, pero el
     * legado permite varios y arrastra turnos de hace anios sin cerrar; mostrarlos
     * todos es lo unico que permite limpiarlos.
     */
    #[Computed]
    public function openSessions()
    {
        return CashSession::query()
            ->open()
            ->where('cod_usu', $this->legacyCode)
            ->orderByDesc('cod_aper_cierre_caja')
            ->get();
    }

    /** Turnos abiertos que ya pasaron el limite de horas: pendientes de cierre. */
    #[Computed]
    public function staleSessions()
    {
        return $this->openSessions->filter->exceedsMaxDuration()->values();
    }

    /** Ultimo turno ya cerrado: es el que el cajero suele reimprimir. */
    #[Computed]
    public function previousSession(): ?CashSession
    {
        return CashSession::query()
            ->where('cod_usu', $this->legacyCode)
            ->where('estado_aper_cierre_caja', CashSession::ESTADO_CERRADO)
            // fecha/hora son texto y no ordenan cronologicamente; el codigo si.
            ->orderByDesc('cod_aper_cierre_caja')
            ->first();
    }

    #[Computed]
    public function recentSessions()
    {
        return CashSession::query()
            ->where('cod_usu', $this->legacyCode)
            ->orderByDesc('cod_aper_cierre_caja')
            ->limit(10)
            ->get();
    }

    /** Recaudado y numero de boletas de los turnos listados, en una sola consulta. */
    #[Computed]
    public function totalsBySession(): array
    {
        $codes = $this->recentSessions
            ->pluck('cod_aper_cierre_caja')
            ->merge($this->openSessions->pluck('cod_aper_cierre_caja'))
            ->merge([$this->previousSession?->cod_aper_cierre_caja])
            ->filter()
            ->unique();

        if ($codes->isEmpty()) {
            return [];
        }

        return DB::connection('caja')
            ->table('Cabecera_documento_MH')
            ->whereIn('cod_aper_cierre_caja', $codes)
            ->where('estado_doc', ChargeDocument::ESTADO_EMITIDO)
            ->selectRaw('cod_aper_cierre_caja, COUNT(*) as boletas, SUM(total_doc) as total')
            ->groupBy('cod_aper_cierre_caja')
            ->get()
            ->keyBy('cod_aper_cierre_caja')
            ->all();
    }

    public function open(): void
    {
        abort_unless(Auth::user()->canOpenCashSession(), 403, 'Solo los cajeros pueden abrir turnos de caja.');

        // Regla del hospital: un turno a la vez. Sin esto quedan turnos zombis
        // abiertos y el arqueo del cajero central no cuadra.
        if ($this->openSessions->isNotEmpty()) {
            $abierto = $this->openSession;

            $this->addError(
                'session',
                "Ya tienes el turno {$abierto->cod_aper_cierre_caja} abierto ({$abierto->durationLabel()}). Ciérralo antes de abrir uno nuevo.",
            );

            return;
        }

        CashSession::query()->create([
            'cod_aper_cierre_caja' => LegacyIdGenerator::nextCashSessionCode(),
            'cod_usu' => $this->legacyCode,
            'fecha_apertura' => now()->format('d/m/Y'),
            'hora_apertura' => now()->format('H:i:s'),
            'fecha_cierre' => '00/00/0000',
            'hora_cierre' => '00:00:00',
            'estado_aper_cierre_caja' => CashSession::ESTADO_ABIERTO,
        ]);

        $this->refreshSessions();
    }

    public function close(?string $sessionCode = null): void
    {
        abort_unless(Auth::user()->canDo('caja.session.close'), 403);

        $session = $sessionCode
            ? $this->openSessions->firstWhere('cod_aper_cierre_caja', $sessionCode)
            : $this->openSession;

        if (! $session) {
            return;
        }

        // Se cierra aunque haya pasado el limite de horas: el turno largo es algo que
        // hay que reportar, no una razon para dejarlo abierto.
        $session->update([
            'fecha_cierre' => now()->format('d/m/Y'),
            'hora_cierre' => now()->format('H:i:s'),
            'estado_aper_cierre_caja' => CashSession::ESTADO_CERRADO,
        ]);

        session()->flash('ok', "Turno {$session->cod_aper_cierre_caja} cerrado. Ya puedes imprimir su reporte contable.");

        $this->refreshSessions();
    }

    private function refreshSessions(): void
    {
        unset(
            $this->openSession,
            $this->openSessions,
            $this->staleSessions,
            $this->previousSession,
            $this->recentSessions,
            $this->totalsBySession,
        );
    }
}; ?>

<section class="w-full space-y-6">
    <flux:heading size="xl">Turno de caja</flux:heading>

    @error('session')
        <flux:callout variant="danger" heading="{{ $message }}" />
    @enderror

    @if (session('ok'))
        <div class="flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-500/30 dark:bg-emerald-400/10">
            <flux:icon.check-circle class="mt-0.5 size-5 shrink-0 text-emerald-600 dark:text-emerald-400" />
            <flux:text class="text-emerald-800 dark:text-emerald-300">{{ session('ok') }}</flux:text>
        </div>
    @endif

    {{-- Quién está cobrando, en grande, y el aviso si el turno pasó el límite de horas. --}}
    <x-current-cashier-banner :session="$this->openSession">
        @if ($this->openSession)
            <flux:button
                href="{{ route('caja.sessions.report', [$this->openSession->cod_aper_cierre_caja, 'imprimir' => 1]) }}"
                target="_blank"
                variant="primary"
                size="sm"
                icon="printer"
            >
                Imprimir mi turno
            </flux:button>
        @endif
    </x-current-cashier-banner>

    {{-- Turnos viejos sin cerrar: son los que bloquean la apertura de uno nuevo. --}}
    @if ($this->staleSessions->isNotEmpty())
        <flux:callout variant="warning" heading="Tienes {{ $this->staleSessions->count() }} turno(s) pendientes de cerrar">
            <div class="mt-2 space-y-2">
                @foreach ($this->staleSessions as $pendiente)
                    <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-white/60 p-2 dark:bg-white/5">
                        <flux:text class="text-sm">
                            <span class="font-semibold">{{ $pendiente->cod_aper_cierre_caja }}</span>
                            · abierto {{ $pendiente->fecha_apertura }} {{ $pendiente->hora_apertura }}
                            · {{ $pendiente->durationLabel() }}
                        </flux:text>
                        <div class="flex items-center gap-2">
                            <flux:button
                                href="{{ route('caja.sessions.report', [$pendiente->cod_aper_cierre_caja, 'imprimir' => 1]) }}"
                                target="_blank"
                                size="xs"
                                variant="ghost"
                                icon="printer"
                            >
                                Imprimir
                            </flux:button>
                            <flux:button
                                wire:click="close('{{ $pendiente->cod_aper_cierre_caja }}')"
                                wire:confirm="¿Cerrar el turno {{ $pendiente->cod_aper_cierre_caja }}?"
                                size="xs"
                                variant="danger"
                            >
                                Cerrar
                            </flux:button>
                        </div>
                    </div>
                @endforeach
            </div>
        </flux:callout>
    @endif

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        {{-- Turno actual --}}
        @if ($this->openSession)
            @php $totalActual = $this->totalsBySession[$this->openSession->cod_aper_cierre_caja] ?? null; @endphp

            <flux:card class="space-y-4">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <flux:subheading>Turno actual</flux:subheading>
                        <flux:heading size="lg">{{ $this->openSession->cod_aper_cierre_caja }}</flux:heading>
                        <flux:text class="mt-1">
                            Abierto el {{ $this->openSession->fecha_apertura }} a las {{ $this->openSession->hora_apertura }}
                        </flux:text>
                    </div>
                    <flux:badge :color="$this->openSession->exceedsMaxDuration() ? 'amber' : 'green'">
                        {{ $this->openSession->exceedsMaxDuration() ? 'Excede '.(int) \App\Models\Caja\CashSession::maxHours().' h' : 'Abierto' }}
                    </flux:badge>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <flux:text class="text-xs text-zinc-500">Recaudado</flux:text>
                        <div class="text-2xl font-semibold text-accent">S/ {{ number_format($totalActual->total ?? 0, 2) }}</div>
                    </div>
                    <div>
                        <flux:text class="text-xs text-zinc-500">Comprobantes</flux:text>
                        <div class="text-2xl font-semibold">{{ $totalActual->boletas ?? 0 }}</div>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    <flux:button href="{{ route('caja.charges.create') }}" wire:navigate variant="primary">Registrar cobro</flux:button>
                    <flux:button
                        href="{{ route('caja.sessions.report', [$this->openSession->cod_aper_cierre_caja, 'imprimir' => 1]) }}"
                        target="_blank"
                        variant="filled"
                        icon="printer"
                    >
                        Imprimir reporte
                    </flux:button>
                    <flux:button wire:click="close" wire:confirm="¿Cerrar el turno de caja actual?" variant="danger">Cerrar turno</flux:button>
                </div>

                <flux:text class="text-xs text-zinc-500">
                    El reporte de un turno abierto sale marcado como provisional.
                </flux:text>
            </flux:card>
        @elseif (Auth::user()->canOpenCashSession())
            <flux:card class="space-y-4">
                <div>
                    <flux:subheading>Turno actual</flux:subheading>
                    <flux:text class="mt-1">
                        No tienes un turno de caja abierto. Debes abrir uno antes de registrar cobros.
                    </flux:text>
                </div>
                <flux:button wire:click="open" variant="primary" icon="play">Abrir turno de caja</flux:button>
                <flux:text class="text-xs text-zinc-500">
                    Un turno dura como máximo {{ (int) \App\Models\Caja\CashSession::maxHours() }} horas y no puedes
                    tener dos abiertos a la vez.
                </flux:text>
            </flux:card>
        @endif

        {{-- Turno anterior --}}
        <flux:card class="space-y-4">
            <flux:subheading>Turno anterior</flux:subheading>

            @if ($this->previousSession)
                @php $totalPrevio = $this->totalsBySession[$this->previousSession->cod_aper_cierre_caja] ?? null; @endphp

                <div>
                    <flux:heading size="lg">{{ $this->previousSession->cod_aper_cierre_caja }}</flux:heading>
                    <flux:text class="mt-1 text-sm text-zinc-500">
                        {{ $this->previousSession->fecha_apertura }} {{ $this->previousSession->hora_apertura }}
                        &rarr; {{ $this->previousSession->fecha_cierre }} {{ $this->previousSession->hora_cierre }}
                        · {{ $this->previousSession->durationLabel() }}
                    </flux:text>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <flux:text class="text-xs text-zinc-500">Recaudado</flux:text>
                        <div class="text-2xl font-semibold">S/ {{ number_format($totalPrevio->total ?? 0, 2) }}</div>
                    </div>
                    <div>
                        <flux:text class="text-xs text-zinc-500">Comprobantes</flux:text>
                        <div class="text-2xl font-semibold">{{ $totalPrevio->boletas ?? 0 }}</div>
                    </div>
                </div>

                <flux:button
                    href="{{ route('caja.sessions.report', [$this->previousSession->cod_aper_cierre_caja, 'imprimir' => 1]) }}"
                    target="_blank"
                    variant="filled"
                    icon="printer"
                >
                    Imprimir turno anterior
                </flux:button>
            @else
                <flux:text class="text-sm text-zinc-500">Todavía no tienes turnos cerrados.</flux:text>
            @endif
        </flux:card>
    </div>

    {{-- Historial --}}
    <div>
        <flux:subheading class="mb-2">Turnos recientes</flux:subheading>

        {{-- En telefono se ocultan las columnas de apoyo (apertura, cierre, duracion) y
             queda lo que identifica y decide: codigo, recaudado, estado y el reporte.
             El scroll horizontal vive dentro de la tabla, nunca en la pagina. --}}
        <div class="overflow-x-auto">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Código</flux:table.column>
                <flux:table.column class="max-sm:hidden">Apertura</flux:table.column>
                <flux:table.column class="max-sm:hidden">Cierre</flux:table.column>
                <flux:table.column class="max-sm:hidden">Duración</flux:table.column>
                <flux:table.column>Recaudado</flux:table.column>
                <flux:table.column>Estado</flux:table.column>
                <flux:table.column>Reporte</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($this->recentSessions as $session)
                    @php $totales = $this->totalsBySession[$session->cod_aper_cierre_caja] ?? null; @endphp
                    <flux:table.row>
                        <flux:table.cell>{{ $session->cod_aper_cierre_caja }}</flux:table.cell>
                        <flux:table.cell class="max-sm:hidden">{{ $session->fecha_apertura }} {{ $session->hora_apertura }}</flux:table.cell>
                        <flux:table.cell class="max-sm:hidden">
                            @if ($session->isOpen())
                                &mdash;
                            @else
                                {{ $session->fecha_cierre }} {{ $session->hora_cierre }}
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="max-sm:hidden">
                            <span class="{{ $session->exceedsMaxDuration() ? 'font-medium text-amber-600 dark:text-amber-400' : '' }}">
                                {{ $session->durationLabel() }}
                            </span>
                        </flux:table.cell>
                        <flux:table.cell>S/ {{ number_format($totales->total ?? 0, 2) }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="$session->isOpen() ? ($session->exceedsMaxDuration() ? 'amber' : 'green') : 'zinc'">
                                {{ $session->isOpen() ? ($session->exceedsMaxDuration() ? 'Pendiente de cierre' : 'Abierto') : 'Cerrado' }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:button
                                href="{{ route('caja.sessions.report', [$session->cod_aper_cierre_caja, 'imprimir' => 1]) }}"
                                target="_blank"
                                size="xs"
                                variant="ghost"
                                icon="printer"
                            >
                                Imprimir
                            </flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
        </div>
    </div>
</section>
