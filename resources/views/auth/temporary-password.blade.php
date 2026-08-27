<x-layouts::auth :title="__('Cambiar contraseña temporal')">
    <div class="flex flex-col gap-5">
        <div class="space-y-2 text-center">
            <flux:heading size="xl">Cambia tu contraseña temporal</flux:heading>
            <flux:text class="text-zinc-500">
                Por seguridad, debes establecer una contraseña personal antes de ingresar a CAJA.
            </flux:text>
        </div>

        <form method="POST" action="{{ route('password.temporary.update') }}" class="flex flex-col gap-5">
            @csrf
            @method('PUT')

            <flux:input
                name="current_password"
                label="Contraseña temporal"
                type="password"
                required
                autofocus
                autocomplete="current-password"
                viewable
            />

            <flux:input
                name="password"
                label="Nueva contraseña"
                type="password"
                required
                autocomplete="new-password"
                viewable
            />

            <flux:input
                name="password_confirmation"
                label="Confirmar nueva contraseña"
                type="password"
                required
                autocomplete="new-password"
                viewable
            />

            <flux:button variant="primary" type="submit" class="w-full">
                Guardar contraseña e ingresar
            </flux:button>
        </form>

        <form method="POST" action="{{ route('logout') }}" class="text-center">
            @csrf
            <flux:button type="submit" variant="ghost">Cerrar sesión</flux:button>
        </form>
    </div>
</x-layouts::auth>
