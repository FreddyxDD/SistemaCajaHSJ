@props([
    'event',
])

@php
    use App\Support\Caja\CatalogAudit;

    $accion = CatalogAudit::ACCIONES[$event->action] ?? $event->action;
    $antes = $event->old_values ?? [];
    $despues = $event->new_values ?? [];
    $metadata = $event->metadata ?? [];

    // Un alta no tiene "antes"; una baja/reactivacion solo mueve el estado. En los
    // demas casos se listan solo los campos que efectivamente cambiaron.
    $campos = array_keys($despues ?: $antes);

    $color = match ($event->action) {
        CatalogAudit::ACCION_ALTA => 'emerald',
        CatalogAudit::ACCION_DESACTIVADO => 'red',
        CatalogAudit::ACCION_ACTIVADO => 'sky',
        CatalogAudit::ACCION_PRECIOS => 'violet',
        default => 'zinc',
    };
@endphp

<div {{ $attributes->class(['rounded-lg border border-zinc-200 bg-white p-3 dark:border-white/10 dark:bg-white/5']) }}>
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div class="flex flex-wrap items-center gap-2">
            <flux:badge size="sm" :color="$color">{{ $accion }}</flux:badge>
            <flux:text class="text-sm font-medium">{{ $metadata['servicio'] ?? $event->auditable_id }}</flux:text>
        </div>

        <flux:text class="text-xs text-zinc-500">
            {{ $metadata['usuario'] ?? 'Usuario desconocido' }} ·
            {{ $event->occurred_at?->format('d/m/Y H:i:s') }}
        </flux:text>
    </div>

    @if ($campos !== [])
        <div class="mt-2 overflow-x-auto">
            <table class="w-full text-xs">
                <thead>
                    <tr class="text-left text-zinc-500">
                        <th class="py-1 pe-3 font-medium">Campo</th>
                        <th class="py-1 pe-3 font-medium">Antes</th>
                        <th class="py-1 font-medium">Después</th>
                    </tr>
                </thead>
                <tbody class="divide-y dark:divide-white/10">
                    @foreach ($campos as $campo)
                        <tr>
                            <td class="py-1 pe-3 align-top">{{ $campo }}</td>
                            <td class="py-1 pe-3 align-top text-red-600 dark:text-red-400">
                                {{ $antes[$campo] ?? '—' }}
                            </td>
                            <td class="py-1 align-top text-emerald-700 dark:text-emerald-400">
                                {{ $despues[$campo] ?? '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if (! empty($metadata['motivo']))
        <flux:text class="mt-2 block text-xs text-zinc-500">
            <span class="font-medium">Motivo:</span> {{ $metadata['motivo'] }}
        </flux:text>
    @endif

    <div class="mt-2 text-[11px] text-zinc-400">
        {{ $event->auditable_type }} · {{ $event->auditable_id }}
        @if ($event->ip_address)
            · IP {{ $event->ip_address }}
        @endif
    </div>
</div>
