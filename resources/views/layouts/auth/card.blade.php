<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body
        class="min-h-screen bg-white antialiased dark:bg-[#14171a] dark:text-[#eef1f3]"
        style="
            background-image:
                radial-gradient(circle at 20% 0%, color-mix(in srgb, var(--color-accent) 14%, transparent), transparent 45%),
                radial-gradient(circle at 85% 15%, color-mix(in srgb, var(--color-accent) 10%, transparent), transparent 40%);
        "
    >
        <div class="flex min-h-svh flex-col items-center justify-center p-6">
            {{-- Tarjeta de 380px del rediseño: logo + identificacion del hospital arriba,
                 el formulario de la pantalla en el slot y la vuelta a la landing al pie. --}}
            <div class="acrilico flex w-full max-w-[380px] flex-col gap-4 p-8">
                <div class="flex items-center gap-3">
                    <x-hospital-logo size="size-11" class="shrink-0" />
                    <div>
                        <div class="text-xs font-extrabold tracking-[0.07em] text-accent uppercase">
                            {{ __('Hospital San José de Chincha') }}
                        </div>
                        <flux:heading size="lg" class="mt-1">{{ __('Sistema de Caja') }}</flux:heading>
                    </div>
                </div>

                {{ $slot }}

                <flux:link href="{{ route('home') }}" class="block text-center text-sm" wire:navigate>
                    ← {{ __('Volver al inicio') }}
                </flux:link>
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
