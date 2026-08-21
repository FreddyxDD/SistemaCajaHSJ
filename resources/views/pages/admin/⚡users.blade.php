<?php

use App\Models\AccessAccount;
use App\Models\AccessApplication;
use App\Models\AccessRole;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Usuarios')] class extends Component {
    use WithPagination;

    public string $q = '';

    public ?int $editingUserId = null;

    /** @var array<int, int> Ids de roles marcados en el panel de edicion. */
    public array $selectedRoles = [];

    #[Computed]
    public function canManage(): bool
    {
        return Auth::user()->hasPermission('users.manage') || Auth::user()->hasRole('administrador');
    }

    #[Computed]
    public function application(): AccessApplication
    {
        return AccessApplication::query()->where('code', 'gestioncajahsj')->firstOrFail();
    }

    #[Computed]
    public function roles()
    {
        return AccessRole::query()
            ->where('application_id', $this->application->id)
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function users()
    {
        return User::query()
            ->when(trim($this->q) !== '', function ($query) {
                $like = '%'.trim($this->q).'%';
                $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('email', 'like', $like));
            })
            ->with(['accessAccount.roles' => fn ($q) => $q->where('application_id', $this->application->id)])
            ->orderBy('name')
            ->paginate(15);
    }

    public function updatedQ(): void
    {
        $this->resetPage();
    }

    public function edit(int $userId): void
    {
        abort_unless($this->canManage, 403);

        if ($this->editingUserId === $userId) {
            $this->editingUserId = null;

            return;
        }

        $user = User::query()->with('accessAccount.roles')->findOrFail($userId);

        $this->editingUserId = $userId;
        $this->selectedRoles = $user->accessAccount
            ? $user->accessAccount->roles->where('application_id', $this->application->id)->pluck('id')->all()
            : [];
    }

    public function saveRoles(int $userId): void
    {
        abort_unless($this->canManage, 403);

        $user = User::query()->findOrFail($userId);
        $account = $user->accessAccount;

        // Un usuario central puede existir sin cuenta de acceso todavia (p.ej. creado
        // por otra app del ecosistema); se crea al asignarle su primer rol aqui.
        if (! $account) {
            $account = AccessAccount::query()->create([
                'user_id' => $user->id,
                'username' => Str::of($user->email)->before('@')->limit(60, ''),
                'email' => $user->email,
                'display_name' => $user->name,
                'status' => 'active',
                'must_change_password' => false,
            ]);
        }

        $appRoleIds = $this->roles->pluck('id')->all();
        $selected = array_values(array_intersect($this->selectedRoles, $appRoleIds));

        // Solo se tocan los roles de ESTA aplicacion: los roles del usuario en
        // citashsj / intranet_hsj / legajos_hsj quedan intactos.
        $account->roles()->detach($appRoleIds);

        foreach ($selected as $roleId) {
            $account->roles()->attach($roleId, [
                'assigned_at' => now(),
                'assigned_by' => Auth::id(),
            ]);
        }

        $this->editingUserId = null;
        session()->flash('ok', "Roles actualizados para {$user->name}.");
    }
}; ?>

<section class="w-full space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="xl">Usuarios</flux:heading>
            <flux:text class="text-zinc-500">Usuarios del ecosistema HSJ y los roles que tienen dentro de Gestión de Caja.</flux:text>
        </div>
        <flux:badge color="zinc">{{ $this->users->total() }} usuarios</flux:badge>
    </div>

    @if (session('ok'))
        <div class="flex items-start gap-3 rounded-xl border border-indigo-200 bg-indigo-50 p-4 dark:border-indigo-500/30 dark:bg-indigo-400/10">
            <flux:icon.check-circle class="mt-0.5 size-5 shrink-0 text-indigo-600 dark:text-indigo-400" />
            <flux:text class="text-indigo-800 dark:text-indigo-300">{{ session('ok') }}</flux:text>
        </div>
    @endif

    <div class="flex items-start gap-3 rounded-xl border border-sky-200 bg-sky-50 p-4 dark:border-sky-500/30 dark:bg-sky-400/10">
        <flux:icon.information-circle class="mt-0.5 size-5 shrink-0 text-sky-600 dark:text-sky-400" />
        <flux:text class="text-sm text-sky-900 dark:text-sky-300">
            Los usuarios son compartidos con las demás aplicaciones del hospital (Citas, Intranet, Legajos).
            Desde aquí solo se asignan <strong>roles de Gestión de Caja</strong>; sus accesos en otras aplicaciones no se modifican.
        </flux:text>
    </div>

    <flux:input wire:model.live.debounce.400ms="q" placeholder="Buscar por nombre o correo..." icon="magnifying-glass" />

    <div class="space-y-3">
        @foreach ($this->users as $user)
            @php
                $appRoles = $user->accessAccount?->roles->where('application_id', $this->application->id) ?? collect();
            @endphp
            <div class="acrilico rounded-xl border border-zinc-200 bg-white p-4 dark:border-white/10 dark:bg-white/5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex min-w-0 items-start gap-3">
                        <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-sm font-semibold text-zinc-600 dark:bg-white/10 dark:text-zinc-300">
                            {{ \Illuminate\Support\Str::of($user->name)->explode(' ')->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('') }}
                        </div>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <flux:text class="font-medium">{{ $user->name }}</flux:text>
                                @if (! $user->activo)
                                    <flux:badge color="red" size="sm">Inactivo</flux:badge>
                                @endif
                            </div>
                            <flux:text class="text-sm text-zinc-500">{{ $user->email }}</flux:text>

                            <div class="mt-2 flex flex-wrap gap-1.5">
                                @forelse ($appRoles as $role)
                                    <flux:badge :color="match($role->code) { 'administrador' => 'purple', 'jefe_economia' => 'sky', 'cajero_central' => 'emerald', 'cajero' => 'zinc', default => 'zinc' }" size="sm">
                                        {{ $role->name }}
                                    </flux:badge>
                                @empty
                                    <flux:text class="text-xs text-zinc-400">Sin acceso a Gestión de Caja</flux:text>
                                @endforelse
                            </div>
                        </div>
                    </div>

                    @if ($this->canManage)
                        <flux:button size="sm" variant="ghost" wire:click="edit({{ $user->id }})" icon="pencil-square">
                            {{ $editingUserId === $user->id ? 'Cerrar' : 'Editar roles' }}
                        </flux:button>
                    @endif
                </div>

                @if ($editingUserId === $user->id && $this->canManage)
                    <div class="mt-4 space-y-3 rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-white/10 dark:bg-white/5">
                        <flux:subheading>Roles en Gestión de Caja</flux:subheading>

                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach ($this->roles as $role)
                                <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-zinc-200 bg-white p-3 hover:border-indigo-400 dark:border-white/10 dark:bg-white/5">
                                    <input
                                        type="checkbox"
                                        value="{{ $role->id }}"
                                        wire:model="selectedRoles"
                                        class="mt-0.5 size-4 rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500"
                                    >
                                    <span class="min-w-0">
                                        <span class="block text-sm font-medium">{{ $role->name }}</span>
                                        <span class="block text-xs text-zinc-500">{{ $role->description }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>

                        <div class="flex gap-2">
                            <flux:button variant="primary" wire:click="saveRoles({{ $user->id }})">Guardar roles</flux:button>
                            <flux:button variant="ghost" wire:click="edit({{ $user->id }})">Cancelar</flux:button>
                        </div>
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    {{ $this->users->links() }}
</section>
