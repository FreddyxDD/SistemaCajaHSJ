<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])

{{-- Maneja el tema claro/oscuro (clase `dark` en <html>) y lo persiste. No agregar
     un script propio de tema: competiria con este y el modo no sobreviviria a la
     recarga. Para cambiarlo desde la UI se usa `$flux.dark` / `$flux.appearance`. --}}
@fluxAppearance
