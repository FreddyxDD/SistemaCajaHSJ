<?php

use App\Support\Caja\LegacyDate;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Cajeros del sistema')] class extends Component {
    public string $q = '';

    public string $tipo = 'T000005';

    #[Computed]
    public function tipos()
    {
        return DB::connection('caja')
            ->table('Tipo_Usuario as t')
            ->leftJoin('Usuario as u', 'u.cod_tipo', '=', 't.cod_tipo')
            ->selectRaw('t.cod_tipo, MAX(t.descripcion) as descripcion, COUNT(u.cod_usu) as usuarios')
            ->groupBy('t.cod_tipo')
            ->orderBy('t.cod_tipo')
            ->get();
    }

    /**
     * Usuarios del catalogo legado (SISGESH_BD.Usuario) con su actividad de caja.
     * Es la tabla a la que apuntan las FK de turnos y comprobantes.
     */
    #[Computed]
    public function usuarios()
    {
        return DB::connection('caja')
            ->table('Usuario as u')
            ->leftJoin('CAJA_APERTURA_CIERRE as s', 's.cod_usu', '=', 'u.cod_usu')
            ->when($this->tipo !== 'all', fn ($q) => $q->where('u.cod_tipo', $this->tipo))
            ->when(trim($this->q) !== '', function ($query) {
                $like = '%'.trim($this->q).'%';
                $query->where(fn ($q) => $q->where('u.nom_usu', 'like', $like)->orWhere('u.cod_usu', 'like', $like));
            })
            ->selectRaw("
                u.cod_usu,
                MAX(u.nom_usu) as nom_usu,
                MAX(u.usu_sis) as usu_sis,
                MAX(u.estado_usuario) as estado,
                COUNT(s.cod_aper_cierre_caja) as turnos,
                MAX(s.fecha_apertura) as ultima_apertura,
                SUM(CASE WHEN s.estado_aper_cierre_caja = 'P' THEN 1 ELSE 0 END) as turnos_abiertos
            ")
            ->groupBy('u.cod_usu')
            ->orderByDesc('turnos')
            ->limit(100)
            ->get();
    }
}; ?>

<section class="w-full space-y-6">
    <div>
        <flux:heading size="xl">Cajeros del sistema</flux:heading>
        <flux:text class="text-zinc-500">
            Catálogo de usuarios del sistema de caja (<code class="text-xs">SISGESH_BD.Usuario</code>): es la tabla a la que quedan
            ligados los turnos y cada boleta emitida.
        </flux:text>
    </div>

    <div class="flex flex-wrap gap-2">
        <button
            type="button"
            wire:click="$set('tipo', 'all')"
            class="rounded-full border px-3 py-1.5 text-sm font-medium {{ $tipo === 'all' ? 'border-indigo-500 bg-indigo-50 text-indigo-700 dark:bg-indigo-400/10 dark:text-indigo-400' : 'border-zinc-300 hover:border-indigo-500 dark:border-zinc-600' }}"
        >
            Todos
        </button>
        @foreach ($this->tipos as $t)
            <button
                type="button"
                wire:click="$set('tipo', '{{ $t->cod_tipo }}')"
                class="flex items-center gap-2 rounded-full border px-3 py-1.5 text-sm font-medium {{ $tipo === $t->cod_tipo ? 'border-indigo-500 bg-indigo-50 text-indigo-700 dark:bg-indigo-400/10 dark:text-indigo-400' : 'border-zinc-300 hover:border-indigo-500 dark:border-zinc-600' }}"
            >
                {{ ucfirst(mb_strtolower($t->descripcion)) }}
                <span class="rounded-full bg-zinc-100 px-1.5 text-xs dark:bg-white/10">{{ $t->usuarios }}</span>
            </button>
        @endforeach
    </div>

    <flux:input wire:model.live.debounce.400ms="q" placeholder="Buscar por nombre o código..." icon="magnifying-glass" />

    <div class="overflow-hidden acrilico rounded-xl border border-zinc-200 dark:border-white/10">
        <div class="divide-y dark:divide-white/10">
            @forelse ($this->usuarios as $u)
                <div class="flex items-center gap-4 px-4 py-3">
                    <div class="flex size-9 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-xs font-semibold text-zinc-600 dark:bg-white/10 dark:text-zinc-300">
                        {{ \Illuminate\Support\Str::of($u->nom_usu)->explode(' ')->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('') }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:text class="font-medium">{{ $u->nom_usu }}</flux:text>
                            @if ($u->estado !== 'A')
                                <flux:badge color="red" size="sm">Inactivo</flux:badge>
                            @endif
                            @if ($u->turnos_abiertos > 0)
                                <flux:badge color="amber" size="sm">Turno abierto</flux:badge>
                            @endif
                        </div>
                        <div class="text-xs text-zinc-500">
                            {{ $u->cod_usu }} · usuario <code>{{ $u->usu_sis }}</code>
                        </div>
                    </div>
                    <div class="shrink-0 text-right">
                        <div class="text-sm font-medium">{{ $u->turnos }} {{ \Illuminate\Support\Str::plural('turno', $u->turnos) }}</div>
                        @if ($u->ultima_apertura)
                            <div class="text-xs text-zinc-500">último: {{ $u->ultima_apertura }}</div>
                        @endif
                    </div>
                </div>
            @empty
                <div class="px-4 py-8 text-center text-sm text-zinc-500">No hay usuarios que coincidan.</div>
            @endforelse
        </div>
    </div>
</section>
