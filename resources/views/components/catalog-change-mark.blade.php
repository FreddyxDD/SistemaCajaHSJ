@props([
    'change' => null,
    'compact' => false,
])

@if ($change)
    @php
        $color = match ($change['tipo']) {
            'nuevo' => 'emerald',
            'precio' => 'amber',
            'nombre' => 'sky',
            default => 'zinc',
        };

        $icono = match ($change['tipo']) {
            'nuevo' => 'sparkles',
            'precio' => 'arrow-trending-up',
            'nombre' => 'pencil',
            default => 'information-circle',
        };

        // El detalle completo va en el title nativo: el catálogo es una lista larga y
        // un tooltip por fila la volvería ilegible.
        $titulo = $change['detalle']
            .' · '.$change['fecha']->format('d/m/Y H:i')
            .($change['usuario'] ? ' · '.$change['usuario'] : '');
    @endphp

    <flux:badge size="sm" :color="$color" :icon="$icono" :title="$titulo" class="shrink-0">
        {{ $compact ? '' : $change['etiqueta'] }}
    </flux:badge>
@endif
