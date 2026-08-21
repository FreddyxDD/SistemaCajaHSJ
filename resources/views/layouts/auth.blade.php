{{-- El rediseño v2 usa la tarjeta centrada de 380px como pantalla de acceso
     (docs/design/handoff-rediseno-ui-v2.md), no el layout partido. --}}
<x-layouts::auth.card :title="$title ?? null">
    {{ $slot }}
</x-layouts::auth.card>
