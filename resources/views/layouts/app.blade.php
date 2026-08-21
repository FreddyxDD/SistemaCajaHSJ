{{-- El rediseño v2 usa una barra de navegacion superior acrilica
     (docs/design/handoff-rediseno-ui-v2.md); el panel lateral queda solo para movil.

     El contenido ocupa todo el ancho de la pantalla con un padding de 4; el pie va
     dentro de <flux:main> para que quede al fondo y no como columna lateral. --}}
<x-layouts::app.header :title="$title ?? null">
    <flux:main class="flex min-h-[calc(100svh-4rem)] w-full max-w-none! flex-col gap-6 p-4!">
        {{ $slot }}

        <x-app-footer />
    </flux:main>
</x-layouts::app.header>
