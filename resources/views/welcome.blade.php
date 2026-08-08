<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head', ['title' => null])
    </head>
    <body class="min-h-screen bg-white antialiased dark:bg-neutral-950">
        <div class="relative isolate overflow-hidden">
            <div class="absolute inset-x-0 -top-40 -z-10 transform-gpu overflow-hidden blur-3xl">
                <div class="relative left-1/2 aspect-[1155/678] w-[72rem] -translate-x-1/2 bg-linear-to-tr from-emerald-500/30 to-emerald-900/10 opacity-30"></div>
            </div>

            <header class="mx-auto flex max-w-6xl items-center justify-between px-6 py-6 lg:px-8">
                <a href="{{ route('home') }}" class="flex items-center gap-2 font-medium text-white">
                    <span class="flex h-9 w-9 items-center justify-center rounded-md bg-white/10">
                        <x-app-logo-icon class="h-5 fill-current text-white" />
                    </span>
                    {{ config('app.name') }}
                </a>

                <nav class="flex items-center gap-3">
                    @auth
                        <flux:button href="{{ route('dashboard') }}" variant="primary">Ir al panel</flux:button>
                    @else
                        <flux:button href="{{ route('login') }}" variant="ghost" class="text-white!">Iniciar sesión</flux:button>
                        @if (Route::has('register'))
                            <flux:button href="{{ route('register') }}" variant="primary">Crear cuenta</flux:button>
                        @endif
                    @endauth
                </nav>
            </header>

            <main class="mx-auto max-w-4xl px-6 pt-16 pb-24 text-center lg:px-8 lg:pt-24">
                <flux:badge color="emerald" class="mb-6">Hospital San José</flux:badge>

                <h1 class="text-4xl font-semibold tracking-tight text-white sm:text-6xl">
                    Gestión de Caja
                </h1>
                <p class="mx-auto mt-6 max-w-2xl text-lg text-white/60">
                    Apertura y cierre de turno, cobro de servicios por paciente y forma de pago, y trazabilidad completa
                    de cada comprobante emitido — en un solo lugar.
                </p>

                <div class="mt-10 flex items-center justify-center gap-4">
                    @auth
                        <flux:button href="{{ route('dashboard') }}" variant="primary">Ir al panel</flux:button>
                    @else
                        <flux:button href="{{ route('login') }}" variant="primary">Iniciar sesión</flux:button>
                    @endauth
                </div>
            </main>

            <div class="mx-auto max-w-6xl px-6 pb-24 lg:px-8">
                <div class="grid grid-cols-1 gap-6 sm:grid-cols-3">
                    <div class="rounded-xl border border-white/10 bg-white/5 p-6">
                        <flux:icon.clock class="size-6 text-emerald-400" />
                        <h3 class="mt-4 font-medium text-white">Turnos con trazabilidad</h3>
                        <p class="mt-2 text-sm text-white/60">Cada apertura y cierre de caja queda registrado por usuario, fecha y hora.</p>
                    </div>
                    <div class="rounded-xl border border-white/10 bg-white/5 p-6">
                        <flux:icon.magnifying-glass class="size-6 text-emerald-400" />
                        <h3 class="mt-4 font-medium text-white">Cobro rápido</h3>
                        <p class="mt-2 text-sm text-white/60">Busca al paciente por nombre, documento o historia clínica y factura con el precio correcto según su forma de pago.</p>
                    </div>
                    <div class="rounded-xl border border-white/10 bg-white/5 p-6">
                        <flux:icon.chart-bar class="size-6 text-emerald-400" />
                        <h3 class="mt-4 font-medium text-white">Reportes y analítica</h3>
                        <p class="mt-2 text-sm text-white/60">Cuadre de recaudación por forma de pago, tendencia diaria y servicios más facturados.</p>
                    </div>
                </div>
            </div>
        </div>

        @fluxScripts
    </body>
</html>
