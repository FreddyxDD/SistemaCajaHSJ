<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-[#14171a] dark:text-[#eef1f3]">
        @php
            $u = auth()->user();
            $cajaEnvironments = app(\App\Support\Caja\CajaDatabaseEnvironment::class);
            $cajaEnvironment = $cajaEnvironments->selected(request());
            $pendingVoids = ($u?->canDo('caja.void.approve'))
                ? \App\Models\VoidRequest::query()->pending()->count()
                : 0;

            // Una sola definicion del menu para la barra superior (escritorio) y el
            // panel lateral (movil). Cada entrada es
            // ['icono', 'ruta', 'patron activo', 'etiqueta', badge, hijos].
            // "Turno" agrupa la operacion diaria del cajero: su propio turno, el cobro
            // nuevo y los comprobantes que emitio.
            $turnoChildren = array_values(array_filter([
                $u?->canOpenCashSession()
                    ? ['clock', route('caja.sessions.index'), 'caja.sessions.index', __('Mi turno'), null, []]
                    : null,
                $u?->canDo('caja.charge.create')
                    ? ['plus-circle', route('caja.charges.create'), 'caja.charges.create', __('Nuevo cobro'), null, []]
                    : null,
                $u?->canDo('caja.view')
                    ? ['document-text', route('caja.charges.index'), 'caja.charges.index|caja.charges.show', __('Comprobantes'), null, []]
                    : null,
            ]));

            $adminChildren = array_values(array_filter([
                $u?->canDo('users.view')
                    ? ['user-group', route('admin.users.index'), 'admin.users.*', __('Usuarios y roles'), null, []]
                    : null,
                $u?->canDo('users.view')
                    ? ['identification', route('admin.legacy-cashiers.index'), 'admin.legacy-cashiers.*', __('Cajeros del sistema'), null, []]
                    : null,
                $u?->canDo('caja.catalog.manage')
                    ? ['tag', route('admin.catalog.index'), 'admin.catalog.index', __('Catálogo y precios'), null, []]
                    : null,
                $u?->canDo('caja.catalog.audit')
                    ? ['clipboard-document-list', route('admin.catalog.audit'), 'admin.catalog.audit', __('Auditoría del catálogo'), null, []]
                    : null,
                $u?->canDo('audit.access.view')
                    ? ['finger-print', route('admin.access-audit'), 'admin.access-audit', __('Auditoría de accesos'), null, []]
                    : null,
            ]));

            $navItems = array_values(array_filter([
                ['squares-2x2', route('dashboard'), 'dashboard', __('Panel'), null, []],
                $turnoChildren !== []
                    ? ['clock', null, 'caja.sessions.index|caja.charges.*', __('Turno'), null, $turnoChildren]
                    : null,
                $u?->canDo('caja.prices.view')
                    ? ['currency-dollar', route('caja.prices.lookup'), 'caja.prices.*', __('Consulta de precios'), null, []]
                    : null,
                $u?->canDo('caja.void.request')
                    ? ['x-circle', route('caja.void-requests.index'), 'caja.void-requests.*', __('Anulaciones'), $pendingVoids ?: null, []]
                    : null,
                $u?->canDo('caja.cashiers.view')
                    ? ['users', route('caja.cashiers.index'), 'caja.cashiers.*|caja.sessions.show', __('Cajeros'), null, []]
                    : null,
                $u?->canDo('reports.view')
                    ? ['chart-bar', route('caja.reports.index'), 'caja.reports.*', __('Reportes'), null, []]
                    : null,
                $adminChildren !== []
                    ? ['shield-check', null, 'admin.*', __('Administración'), null, $adminChildren]
                    : null,
            ]));
        @endphp

        {{-- Sin `container`: la barra ocupa todo el ancho, con el mismo padding 4 del contenido. --}}
        <flux:header sticky class="acrilico-nav border-b border-zinc-200 px-4! dark:border-white/10">
            <flux:sidebar.toggle class="mr-2 lg:hidden" icon="bars-2" inset="left" />

            <a href="{{ route('dashboard') }}" wire:navigate class="flex items-center gap-2 font-semibold whitespace-nowrap">
                <x-hospital-logo size="size-7" />
                HSJ · CAJA
            </a>

            <flux:navbar class="-mb-px max-lg:hidden">
                @foreach ($navItems as [$icon, $href, $pattern, $label, $badge, $children])
                    @if ($children === [])
                        <flux:navbar.item
                            :icon="$icon"
                            :href="$href"
                            :current="request()->routeIs(explode('|', $pattern))"
                            :badge="$badge"
                            badge-color="amber"
                            wire:navigate
                        >
                            {{ $label }}
                        </flux:navbar.item>
                    @else
                        <flux:dropdown>
                            <flux:navbar.item
                                :icon="$icon"
                                icon-trailing="chevron-down"
                                :current="request()->routeIs(explode('|', $pattern))"
                            >
                                {{ $label }}
                            </flux:navbar.item>

                            <flux:navmenu>
                                @foreach ($children as [$childIcon, $childHref, $childPattern, $childLabel])
                                    <flux:navmenu.item
                                        :icon="$childIcon"
                                        :href="$childHref"
                                        :current="request()->routeIs(explode('|', $childPattern))"
                                        wire:navigate
                                    >
                                        {{ $childLabel }}
                                    </flux:navmenu.item>
                                @endforeach
                            </flux:navmenu>
                        </flux:dropdown>
                    @endif
                @endforeach
            </flux:navbar>

            <flux:spacer />

            @if ($cajaEnvironments->enabled())
                @if ($u?->canDo('users.view'))
                    <flux:dropdown position="bottom" align="end">
                        <flux:button
                            variant="ghost"
                            size="sm"
                            icon="circle-stack"
                            class="me-1 {{ $cajaEnvironment === \App\Support\Caja\CajaDatabaseEnvironment::INSTITUTIONAL ? 'text-red-600 dark:text-red-300' : 'text-amber-600 dark:text-amber-300' }}"
                            title="Base de Caja activa: {{ $cajaEnvironments->label($cajaEnvironment) }}"
                        >
                            {{ $cajaEnvironments->shortLabel($cajaEnvironment) }}
                        </flux:button>

                        <flux:menu>
                            <flux:menu.heading>Base de datos de Caja</flux:menu.heading>

                            <form method="POST" action="{{ route('caja-environment.update') }}">
                                @csrf
                                <input type="hidden" name="environment" value="development">
                                <flux:menu.item
                                    as="button"
                                    type="submit"
                                    icon="beaker"
                                    class="w-full cursor-pointer"
                                    :disabled="$cajaEnvironment === \App\Support\Caja\CajaDatabaseEnvironment::DEVELOPMENT"
                                >
                                    Desarrollo
                                </flux:menu.item>
                            </form>

                            <form
                                method="POST"
                                action="{{ route('caja-environment.update') }}"
                                onsubmit="return confirm('Cambiarás a la base institucional real. Los cobros y anulaciones afectarán datos operativos. ¿Deseas continuar?')"
                            >
                                @csrf
                                <input type="hidden" name="environment" value="institutional">
                                <flux:menu.item
                                    as="button"
                                    type="submit"
                                    icon="building-office"
                                    class="w-full cursor-pointer text-red-600 dark:text-red-300"
                                    :disabled="$cajaEnvironment === \App\Support\Caja\CajaDatabaseEnvironment::INSTITUTIONAL"
                                >
                                    Institucional
                                </flux:menu.item>
                            </form>
                        </flux:menu>
                    </flux:dropdown>
                @else
                    <flux:badge :color="$cajaEnvironment === \App\Support\Caja\CajaDatabaseEnvironment::INSTITUTIONAL ? 'red' : 'amber'" size="sm" class="me-1">
                        {{ $cajaEnvironments->shortLabel($cajaEnvironment) }}
                    </flux:badge>
                @endif
            @endif

            {{-- Tema claro/oscuro. `$flux.dark` es el estado que ya administra y
                 persiste Flux (@fluxAppearance); no duplicar esa logica. --}}
            <div x-data class="me-2">
                <flux:tooltip :content="__('Cambiar tema')" position="bottom">
                    <flux:button
                        variant="ghost"
                        size="sm"
                        square
                        :aria-label="__('Cambiar tema')"
                        x-on:click="$flux.dark = ! $flux.dark"
                    >
                        <flux:icon.sun x-show="$flux.dark" x-cloak class="size-5" />
                        <flux:icon.moon x-show="! $flux.dark" class="size-5" />
                    </flux:button>
                </flux:tooltip>
            </div>

            <flux:dropdown position="bottom" align="end">
                {{-- En telefono queda solo el avatar: el nombre completo empujaba la
                     barra 80px mas alla del ancho de la pantalla. El nombre sigue
                     visible dentro del menu. --}}
                <flux:profile
                    :name="$u->name"
                    :initials="$u->initials()"
                    icon-trailing="chevron-down"
                    data-test="header-menu-button"
                    class="max-sm:[&>span]:hidden max-sm:[&>div:last-child]:hidden"
                />

                <flux:menu>
                    <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                        <flux:avatar :name="$u->name" :initials="$u->initials()" />
                        <div class="grid flex-1 text-start text-sm leading-tight">
                            <flux:heading class="truncate">{{ $u->name }}</flux:heading>
                            <flux:text class="truncate">{{ $u->email }}</flux:text>
                        </div>
                    </div>

                    <flux:menu.separator />

                    <flux:menu.item :href="route('profile.edit')" icon="user-circle" wire:navigate>
                        {{ __('Perfil') }}
                    </flux:menu.item>

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

        {{-- Menú lateral solo para pantallas pequeñas. El fondo es obligatorio: sin el,
             el cajon se abre translucido y se lee encima del contenido de la pagina. --}}
        <flux:sidebar collapsible="mobile" sticky class="border-e border-zinc-200 bg-white lg:hidden dark:border-white/10 dark:bg-[#1e2226]">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="in-data-flux-sidebar-on-desktop:not-in-data-flux-sidebar-collapsed-desktop:-mr-2" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                @foreach ($navItems as [$icon, $href, $pattern, $label, $badge, $children])
                    @if ($children === [])
                        <flux:sidebar.item
                            :icon="$icon"
                            :href="$href"
                            :current="request()->routeIs(explode('|', $pattern))"
                            :badge="$badge"
                            badge-color="amber"
                            wire:navigate
                        >
                            {{ $label }}
                        </flux:sidebar.item>
                    @else
                        <flux:sidebar.group
                            expandable
                            :icon="$icon"
                            :heading="$label"
                            :expanded="request()->routeIs(explode('|', $pattern))"
                        >
                            @foreach ($children as [$childIcon, $childHref, $childPattern, $childLabel])
                                <flux:sidebar.item
                                    :icon="$childIcon"
                                    :href="$childHref"
                                    :current="request()->routeIs(explode('|', $childPattern))"
                                    wire:navigate
                                >
                                    {{ $childLabel }}
                                </flux:sidebar.item>
                            @endforeach
                        </flux:sidebar.group>
                    @endif
                @endforeach
            </flux:sidebar.nav>

            <flux:spacer />

            <flux:sidebar.nav>
                @if ($cajaEnvironments->enabled())
                    <flux:sidebar.item icon="circle-stack" :href="route('profile.edit')" wire:navigate>
                        Base activa: {{ $cajaEnvironments->label($cajaEnvironment) }}
                    </flux:sidebar.item>
                @endif

                <div x-data>
                    <flux:sidebar.item icon="sun" href="#" x-on:click.prevent="$flux.dark = ! $flux.dark">
                        <span x-show="$flux.dark" x-cloak>{{ __('Modo claro') }}</span>
                        <span x-show="! $flux.dark">{{ __('Modo oscuro') }}</span>
                    </flux:sidebar.item>
                </div>
            </flux:sidebar.nav>
        </flux:sidebar>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
