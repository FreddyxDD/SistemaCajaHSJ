<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            @php
                $u = auth()->user();
                $pendingVoids = ($u?->canDo('caja.void.approve'))
                    ? \App\Models\VoidRequest::query()->pending()->count()
                    : 0;
            @endphp

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Platform')" class="grid">
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Panel') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>

                @if ($u?->canDo('caja.view'))
                    <flux:sidebar.group :heading="__('Operación')" class="grid">
                        @if ($u?->canOpenCashSession())
                            <flux:sidebar.item icon="banknotes" :href="route('caja.sessions.index')" :current="request()->routeIs('caja.sessions.index')" wire:navigate>
                                {{ __('Mi turno') }}
                            </flux:sidebar.item>
                        @endif
                        @if ($u?->canDo('caja.charge.create'))
                            <flux:sidebar.item icon="plus-circle" :href="route('caja.charges.create')" :current="request()->routeIs('caja.charges.create')" wire:navigate>
                                {{ __('Nuevo cobro') }}
                            </flux:sidebar.item>
                        @endif
                        <flux:sidebar.item icon="document-text" :href="route('caja.charges.index')" :current="request()->routeIs('caja.charges.index')" wire:navigate>
                            {{ __('Comprobantes') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                @endif

                @if ($u?->canDo('caja.cashiers.view') || $u?->canDo('caja.void.request') || $u?->canDo('reports.view'))
                    <flux:sidebar.group :heading="__('Supervisión')" class="grid">
                        @if ($u?->canDo('caja.cashiers.view'))
                            <flux:sidebar.item icon="users" :href="route('caja.cashiers.index')" :current="request()->routeIs('caja.cashiers.*') || request()->routeIs('caja.sessions.show')" wire:navigate>
                                {{ __('Cajeros y turnos') }}
                            </flux:sidebar.item>
                        @endif
                        @if ($u?->canDo('caja.void.request'))
                            <flux:sidebar.item icon="x-circle" :href="route('caja.void-requests.index')" :current="request()->routeIs('caja.void-requests.*')" :badge="$pendingVoids ?: null" badge-color="amber" wire:navigate>
                                {{ __('Anulaciones') }}
                            </flux:sidebar.item>
                        @endif
                        @if ($u?->canDo('reports.view'))
                            <flux:sidebar.item icon="chart-bar" :href="route('caja.reports.index')" :current="request()->routeIs('caja.reports.*')" wire:navigate>
                                {{ __('Reportes') }}
                            </flux:sidebar.item>
                        @endif
                    </flux:sidebar.group>
                @endif

                @if ($u?->canDo('users.view'))
                    <flux:sidebar.group :heading="__('Administración')" class="grid">
                        <flux:sidebar.item icon="user-group" :href="route('admin.users.index')" :current="request()->routeIs('admin.users.*')" wire:navigate>
                            {{ __('Usuarios y roles') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="identification" :href="route('admin.legacy-cashiers.index')" :current="request()->routeIs('admin.legacy-cashiers.*')" wire:navigate>
                            {{ __('Cajeros del sistema') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                @endif
            </flux:sidebar.nav>

            <flux:spacer />

            <flux:sidebar.nav>
                {{-- Tema claro/oscuro. `$flux.dark` es el estado que ya administra y
                     persiste Flux (@fluxAppearance); no duplicar esa logica. --}}
                <div x-data>
                    <flux:sidebar.item icon="sun" href="#" x-on:click.prevent="$flux.dark = ! $flux.dark">
                        <span x-show="$flux.dark" x-cloak>{{ __('Modo claro') }}</span>
                        <span x-show="! $flux.dark">{{ __('Modo oscuro') }}</span>
                    </flux:sidebar.item>
                </div>
            </flux:sidebar.nav>

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
