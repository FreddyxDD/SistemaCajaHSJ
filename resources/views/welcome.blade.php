<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['title' => null])
    </head>
    {{-- Fondo del rediseño: dos orbes de acento difuminados sobre el color base. --}}
    <body
        class="min-h-screen bg-white antialiased dark:bg-[#14171a] dark:text-[#eef1f3]"
        style="
            background-image:
                radial-gradient(circle at 20% 0%, color-mix(in srgb, var(--color-accent) 14%, transparent), transparent 45%),
                radial-gradient(circle at 85% 15%, color-mix(in srgb, var(--color-accent) 10%, transparent), transparent 40%);
        "
    >
        <div class="flex min-h-svh flex-col">
            <nav class="acrilico-nav sticky top-0 z-30 flex items-center gap-3 px-6 py-2.5 lg:px-8">
                <a href="{{ route('home') }}" class="flex items-center gap-2.5 font-medium whitespace-nowrap">
                    <x-hospital-logo size="size-9" />
                    {{ __('Gestión de Caja HSJ') }}
                </a>

                <div class="ms-auto flex items-center gap-3">
                    <div x-data>
                        <flux:button
                            variant="ghost"
                            size="sm"
                            icon="sun"
                            square
                            :aria-label="__('Cambiar tema')"
                            x-on:click="$flux.dark = ! $flux.dark"
                        />
                    </div>

                    @auth
                        <flux:button href="{{ route('dashboard') }}" variant="primary" size="sm">{{ __('Ir al panel') }}</flux:button>
                    @else
                        <flux:link href="{{ route('login') }}" class="text-sm">{{ __('Iniciar sesión') }}</flux:link>
                        @if (Route::has('register'))
                            <flux:button href="{{ route('register') }}" variant="primary" size="sm">{{ __('Crear cuenta') }}</flux:button>
                        @endif
                    @endauth
                </div>
            </nav>

            <main class="relative flex flex-1 flex-col items-center gap-5 overflow-hidden px-6 pt-16 pb-10 text-center">
                <div class="hero-orb absolute top-16 left-[8%] size-64 bg-accent/20"></div>
                <div class="hero-orb absolute top-28 right-[10%] size-56 bg-accent/15" style="animation-delay: 2s;"></div>

                <div class="logo-3d relative z-10" style="perspective: 600px;">
                    <x-hospital-logo size="size-18" />
                </div>

                <flux:badge color="indigo" class="hero-rise-1 relative z-10">{{ __('Hospital San José') }}</flux:badge>

                <h1 class="hero-rise-2 relative z-10 max-w-3xl text-5xl leading-[1.05] font-semibold tracking-tight sm:text-6xl">
                    {{ __('Gestión de Caja') }}
                </h1>

                <p class="hero-rise-3 relative z-10 max-w-xl text-base opacity-70">
                    {{ __('Apertura y cierre de turno, cobro de servicios por paciente y forma de pago, y trazabilidad completa de cada comprobante emitido — en un solo lugar.') }}
                </p>

                <div class="hero-rise-4 relative z-10">
                    @auth
                        <flux:button href="{{ route('dashboard') }}" variant="primary">{{ __('Ir al panel') }}</flux:button>
                    @else
                        <flux:button href="{{ route('login') }}" variant="primary">{{ __('Iniciar sesión') }}</flux:button>
                    @endauth
                </div>

                {{-- Tarjetas de valor con inclinacion 3D siguiendo al cursor (±10°). --}}
                <div
                    class="relative z-10 mt-12 grid w-full max-w-4xl grid-cols-1 gap-5 sm:grid-cols-3"
                    style="perspective: 900px;"
                    x-data="{
                        tilt(event) {
                            const rect = event.currentTarget.getBoundingClientRect();
                            const px = (event.clientX - rect.left) / rect.width - 0.5;
                            const py = (event.clientY - rect.top) / rect.height - 0.5;
                            event.currentTarget.style.transform = `translateY(-4px) rotateX(${py * -10}deg) rotateY(${px * 10}deg)`;
                        },
                        reset(event) {
                            event.currentTarget.style.transform = '';
                        },
                    }"
                >
                    @php
                        $features = [
                            ['clock', __('Control de turnos'), __('Apertura y cierre de caja con arqueo automático')],
                            ['magnifying-glass', __('Cobro rápido'), __('Búsqueda de servicios y emisión de comprobante en segundos')],
                            ['chart-bar', __('Reportes en vivo'), __('Recaudación, anulaciones y desempeño de cajeros al día')],
                        ];
                    @endphp

                    @foreach ($features as [$icon, $titulo, $detalle])
                        <div
                            class="acrilico tilt lift flex flex-col items-center gap-2.5 p-6 text-center"
                            x-on:mousemove="tilt($event)"
                            x-on:mouseleave="reset($event)"
                        >
                            <flux:icon :name="$icon" class="size-5.5 text-accent" />
                            <div class="font-semibold">{{ $titulo }}</div>
                            <div class="text-sm opacity-65">{{ $detalle }}</div>
                        </div>
                    @endforeach
                </div>
            </main>

            <footer class="flex flex-wrap items-center justify-between gap-2 border-t border-zinc-200 px-6 py-5 text-xs opacity-60 lg:px-8 dark:border-white/20">
                <span>© 2026 Hospital San José de Chincha — Sistema de Gestión de Caja</span>
                <span>Creado por Ing. FMC</span>
            </footer>
        </div>

        @fluxScripts
    </body>
</html>
