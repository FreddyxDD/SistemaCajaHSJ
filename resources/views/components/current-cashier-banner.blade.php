@props([
    'session' => null,
])

@php
    use App\Models\Caja\CashSession;

    $usuario = auth()->user();
    $iniciales = $usuario?->initials();
    $excedido = $session?->exceedsMaxDuration() ?? false;
@endphp

{{--
    Quien está cobrando ahora mismo, en grande.

    Pasa que un cajero deja la sesión abierta, se sienta otro y sigue emitiendo
    boletas contra el turno del primero. Todo queda a nombre de quien abrió, y el
    cuadre del cajero central sale mal. Este bloque no lo impide por sí solo, pero
    hace imposible no darse cuenta.
--}}
<div
    {{ $attributes->class([
        'flex flex-wrap items-center gap-4 rounded-xl border-2 p-4',
        'border-amber-400 bg-amber-50 dark:border-amber-500/50 dark:bg-amber-400/10' => $excedido,
        'border-accent/40 bg-accent/5' => ! $excedido,
    ]) }}
>
    <div class="flex size-12 shrink-0 items-center justify-center rounded-full bg-accent text-lg font-bold text-accent-foreground">
        {{ $iniciales }}
    </div>

    <div class="min-w-0 flex-1">
        <flux:text class="text-xs tracking-wide text-zinc-500 uppercase">Cajero en sesión</flux:text>
        <div class="truncate text-xl font-bold">{{ $usuario?->name }}</div>

        @if ($session)
            <div class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-zinc-600 dark:text-zinc-400">
                <span class="inline-flex items-center gap-1">
                    <flux:icon.clock class="size-4" />
                    Turno <span class="font-semibold">{{ $session->cod_aper_cierre_caja }}</span>
                </span>
                <span>Abierto {{ $session->fecha_apertura }} {{ $session->hora_apertura }}</span>
                <flux:badge size="sm" :color="$excedido ? 'amber' : 'zinc'">
                    {{ $session->durationLabel() }}
                </flux:badge>
            </div>
        @else
            <flux:text class="text-sm text-zinc-500">Sin turno de caja abierto</flux:text>
        @endif
    </div>

    <div class="shrink-0">
        {{ $slot }}
    </div>
</div>

@if ($excedido)
    <div class="flex items-start gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-500/40 dark:bg-amber-400/10">
        <flux:icon.exclamation-triangle class="mt-0.5 size-5 shrink-0 text-amber-600 dark:text-amber-400" />
        <div>
            <flux:text class="font-semibold text-amber-900 dark:text-amber-200">
                Este turno lleva {{ $session->durationLabel() }} abierto y supera las
                {{ (int) CashSession::maxHours() }} horas permitidas.
            </flux:text>
            <flux:text class="mt-1 block text-sm text-amber-800 dark:text-amber-300">
                Ciérralo para poder abrir uno nuevo. Si no eres <b>{{ $usuario?->name }}</b>, cierra su sesión antes de
                seguir cobrando: todo lo que emitas quedará a su nombre.
            </flux:text>
        </div>
    </div>
@endif
