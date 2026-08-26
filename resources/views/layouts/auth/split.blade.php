<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white antialiased dark:bg-linear-to-b dark:from-neutral-950 dark:to-neutral-900">
        <div class="relative grid h-dvh flex-col items-center justify-center px-8 sm:px-0 lg:max-w-none lg:grid-cols-2 lg:px-0">
            <div class="relative hidden h-full flex-col justify-between overflow-hidden p-10 text-white lg:flex dark:border-e dark:border-neutral-800">
                <div class="absolute inset-0 bg-linear-to-br from-indigo-950 via-neutral-950 to-neutral-950"></div>
                <div class="absolute -top-24 -right-24 h-96 w-96 rounded-full bg-accent/10 blur-3xl"></div>
                <div class="absolute -bottom-32 -left-16 h-96 w-96 rounded-full bg-accent/10 blur-3xl"></div>

                <a href="{{ route('home') }}" class="relative z-20 flex items-center gap-2 text-lg font-medium" wire:navigate>
                    <span class="flex h-10 w-10 items-center justify-center rounded-md bg-white/10">
                        <x-app-logo-icon class="h-6 fill-current text-white" />
                    </span>
                    {{ config('app.name') }}
                </a>

                <div class="relative z-20 space-y-8">
                    <flux:heading size="xl" class="text-white!">
                        Todo el flujo de caja, en un solo lugar.
                    </flux:heading>

                    <ul class="space-y-5">
                        <li class="flex items-start gap-3">
                            <flux:icon.clock class="mt-0.5 size-5 shrink-0 text-accent" />
                            <div>
                                <p class="font-medium text-white">Turnos con trazabilidad</p>
                                <p class="text-sm text-white/60">Apertura y cierre de caja quedan registrados por usuario, fecha y hora.</p>
                            </div>
                        </li>
                        <li class="flex items-start gap-3">
                            <flux:icon.magnifying-glass class="mt-0.5 size-5 shrink-0 text-accent" />
                            <div>
                                <p class="font-medium text-white">Cobro rápido</p>
                                <p class="text-sm text-white/60">Busca al paciente por nombre, documento o historia clínica y factura con el precio correcto según la forma de pago.</p>
                            </div>
                        </li>
                        <li class="flex items-start gap-3">
                            <flux:icon.document-check class="mt-0.5 size-5 shrink-0 text-accent" />
                            <div>
                                <p class="font-medium text-white">Historial completo</p>
                                <p class="text-sm text-white/60">Cada comprobante emitido queda disponible para consulta y anulación con motivo.</p>
                            </div>
                        </li>
                    </ul>
                </div>

                <p class="relative z-20 text-xs text-white/40">Hospital San José — Módulo de Gestión de Caja</p>
            </div>
            <div class="w-full lg:p-8">
                <div class="mx-auto flex w-full flex-col justify-center space-y-6 sm:w-[350px]">
                    <a href="{{ route('home') }}" class="z-20 flex flex-col items-center gap-2 font-medium lg:hidden" wire:navigate>
                        <span class="flex h-9 w-9 items-center justify-center rounded-md">
                            <x-app-logo-icon class="size-9 fill-current text-black dark:text-white" />
                        </span>

                        <span class="sr-only">{{ config('app.name') }}</span>
                    </a>
                    {{ $slot }}
                </div>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
