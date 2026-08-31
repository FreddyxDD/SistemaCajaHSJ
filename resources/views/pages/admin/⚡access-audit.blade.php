<?php

use App\Models\AuditEvent;
use App\Support\Audit\AccessAudit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Auditoria de accesos: quien entra, desde donde, cuantas veces y que consulta.
 *
 * Lee `audit_events`, donde el listener de autenticacion y el middleware de
 * navegacion dejan el rastro. Es de solo lectura: aqui no se corrige ni se borra
 * nada, un rastro editable no sirve como evidencia.
 */
new #[Title('Auditoría de accesos')] class extends Component {
    use WithPagination;

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    /** Usuario cuyo detalle se esta viendo. */
    #[Url]
    public ?int $usuario = null;

    /** 'accesos' = ingresos y salidas; 'navegacion' = vistas; 'fallidos' = intentos. */
    public string $tab = 'accesos';

    public function mount(): void
    {
        abort_unless(Auth::user()?->canDo('audit.access.view'), 403);

        $this->from = $this->from !== '' ? $this->from : now()->subDays(6)->format('Y-m-d');
        $this->to = $this->to !== '' ? $this->to : now()->format('Y-m-d');
    }

    public function setRange(string $preset): void
    {
        $this->to = now()->format('Y-m-d');
        $this->from = match ($preset) {
            'today' => now()->format('Y-m-d'),
            '7d' => now()->subDays(6)->format('Y-m-d'),
            '30d' => now()->subDays(29)->format('Y-m-d'),
            'month' => now()->startOfMonth()->format('Y-m-d'),
            default => $this->from,
        };

        $this->resetPage();
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->resetPage();
    }

    public function selectUser(?int $userId): void
    {
        $this->usuario = $this->usuario === $userId ? null : $userId;
        $this->resetPage();
    }

    /** Base comun: el rango de fechas elegido. */
    private function enRango()
    {
        return AuditEvent::query()
            ->where('module', AccessAudit::MODULE)
            ->whereBetween('occurred_at', [
                \Illuminate\Support\Carbon::parse($this->from)->startOfDay(),
                \Illuminate\Support\Carbon::parse($this->to)->endOfDay(),
            ]);
    }

    /** Resumen por usuario: cuantas veces entro, desde cuantos equipos, que vio. */
    #[Computed]
    public function usuarios()
    {
        return $this->enRango()
            ->whereNotNull('user_id')
            ->selectRaw("
                user_id,
                SUM(CASE WHEN action = ? THEN 1 ELSE 0 END) as ingresos,
                SUM(CASE WHEN action = ? THEN 1 ELSE 0 END) as fallidos,
                SUM(CASE WHEN event_type = ? THEN 1 ELSE 0 END) as vistas,
                COUNT(DISTINCT ip_address) as equipos,
                MAX(occurred_at) as ultimo_acceso
            ", [
                AccessAudit::ACCION_INGRESO,
                AccessAudit::ACCION_FALLIDO,
                AccessAudit::TIPO_NAVEGACION,
            ])
            ->groupBy('user_id')
            ->orderByDesc('ingresos')
            ->get()
            ->map(function ($fila) {
                // El nombre se lee del propio evento, no de la tabla de usuarios: si
                // el usuario se dio de baja el rastro debe seguir siendo legible.
                $fila->nombre = $this->enRango()
                    ->where('user_id', $fila->user_id)
                    ->whereNotNull('metadata')
                    ->orderByDesc('occurred_at')
                    ->value('metadata')['usuario'] ?? 'Usuario '.$fila->user_id;

                return $fila;
            });
    }

    #[Computed]
    public function totals(): array
    {
        $filas = $this->usuarios;

        return [
            'usuarios' => $filas->count(),
            'ingresos' => (int) $filas->sum('ingresos'),
            'vistas' => (int) $filas->sum('vistas'),
            'fallidos' => (int) $this->enRango()->where('action', AccessAudit::ACCION_FALLIDO)->count(),
        ];
    }

    /** Equipos/IP desde los que se conecto cada usuario del periodo. */
    #[Computed]
    public function equipos()
    {
        return $this->enRango()
            ->where('event_type', AccessAudit::TIPO_ACCESO)
            ->when($this->usuario, fn ($q) => $q->where('user_id', $this->usuario))
            ->selectRaw('ip_address, COUNT(*) as eventos, MAX(occurred_at) as ultimo')
            ->groupBy('ip_address')
            ->orderByDesc('eventos')
            ->limit(12)
            ->get();
    }

    /** Las vistas mas consultadas del periodo. */
    #[Computed]
    public function vistasMasVistas()
    {
        return $this->enRango()
            ->where('event_type', AccessAudit::TIPO_NAVEGACION)
            ->when($this->usuario, fn ($q) => $q->where('user_id', $this->usuario))
            ->whereNotNull('route_name')
            ->selectRaw('route_name, COUNT(*) as visitas')
            ->groupBy('route_name')
            ->orderByDesc('visitas')
            ->limit(12)
            ->get();
    }

    /** El detalle cronologico, segun la pestaña elegida. */
    #[Computed]
    public function eventos()
    {
        return $this->enRango()
            ->when($this->usuario, fn ($q) => $q->where('user_id', $this->usuario))
            ->when($this->tab === 'accesos', fn ($q) => $q
                ->where('event_type', AccessAudit::TIPO_ACCESO)
                ->whereIn('action', [AccessAudit::ACCION_INGRESO, AccessAudit::ACCION_SALIDA]))
            ->when($this->tab === 'fallidos', fn ($q) => $q
                ->whereIn('action', [AccessAudit::ACCION_FALLIDO, AccessAudit::ACCION_BLOQUEO]))
            ->when($this->tab === 'navegacion', fn ($q) => $q->where('event_type', AccessAudit::TIPO_NAVEGACION))
            ->orderByDesc('occurred_at')
            ->paginate(25);
    }

    #[Computed]
    public function nombreUsuarioSeleccionado(): ?string
    {
        return $this->usuario
            ? $this->usuarios->firstWhere('user_id', $this->usuario)?->nombre
            : null;
    }
}; ?>

<section class="w-full space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="xl">Auditoría de accesos</flux:heading>
            <flux:text class="text-zinc-500">
                Quién entra a la aplicación, desde dónde, cuántas veces y qué vistas consulta. Solo lectura.
            </flux:text>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <flux:button size="sm" variant="ghost" wire:click="setRange('today')">Hoy</flux:button>
            <flux:button size="sm" variant="ghost" wire:click="setRange('7d')">7 días</flux:button>
            <flux:button size="sm" variant="ghost" wire:click="setRange('30d')">30 días</flux:button>
            <flux:button size="sm" variant="ghost" wire:click="setRange('month')">Este mes</flux:button>
            <flux:input type="date" wire:model.live="from" class="w-40" />
            <flux:text class="text-zinc-500">a</flux:text>
            <flux:input type="date" wire:model.live="to" class="w-40" />
        </div>
    </div>

    @unless (\App\Support\Audit\AccessAudit::tracksNavigation())
        <flux:callout variant="warning" heading="El rastro de navegación está desactivado">
            <flux:text class="text-sm">
                Se siguen registrando los inicios y cierres de sesión, pero no las vistas consultadas.
                Se activa con <code class="text-xs">AUDITORIA_NAVEGACION=true</code>.
            </flux:text>
        </flux:callout>
    @endunless

    {{-- Resumen del periodo --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="acrilico lift p-5">
            <div class="flex items-center gap-2">
                <flux:icon.users class="size-4 text-zinc-400" />
                <flux:text class="text-sm text-zinc-500">Usuarios que ingresaron</flux:text>
            </div>
            <div class="mt-1 text-3xl font-semibold">{{ $this->totals['usuarios'] }}</div>
        </div>

        <div class="acrilico lift p-5">
            <div class="flex items-center gap-2">
                <flux:icon.arrow-right-end-on-rectangle class="size-4 text-accent" />
                <flux:text class="text-sm text-zinc-500">Inicios de sesión</flux:text>
            </div>
            <div class="mt-1 text-3xl font-semibold text-accent">{{ $this->totals['ingresos'] }}</div>
        </div>

        <div class="acrilico lift p-5">
            <div class="flex items-center gap-2">
                <flux:icon.eye class="size-4 text-zinc-400" />
                <flux:text class="text-sm text-zinc-500">Vistas consultadas</flux:text>
            </div>
            <div class="mt-1 text-3xl font-semibold">{{ number_format($this->totals['vistas']) }}</div>
        </div>

        <div class="acrilico lift p-5">
            <div class="flex items-center gap-2">
                <flux:icon.exclamation-triangle class="size-4 text-amber-500" />
                <flux:text class="text-sm text-zinc-500">Intentos fallidos</flux:text>
            </div>
            <div class="mt-1 text-3xl font-semibold {{ $this->totals['fallidos'] > 0 ? 'text-amber-600 dark:text-amber-400' : '' }}">
                {{ $this->totals['fallidos'] }}
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-5">
        {{-- Usuarios --}}
        <div class="lg:col-span-2">
            <div class="overflow-hidden acrilico">
                <div class="border-b border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-white/10 dark:bg-white/5">
                    <flux:subheading>Usuarios del periodo</flux:subheading>
                </div>

                <div class="max-h-[32rem] divide-y overflow-y-auto dark:divide-white/10">
                    @forelse ($this->usuarios as $u)
                        <button
                            type="button"
                            wire:click="selectUser({{ $u->user_id }})"
                            class="flex w-full items-center gap-3 px-4 py-3 text-left max-sm:min-h-11 {{ $usuario === $u->user_id ? 'bg-accent/10' : 'hover:bg-zinc-50 dark:hover:bg-white/5' }}"
                        >
                            <div class="min-w-0 flex-1">
                                <flux:text class="font-medium">{{ $u->nombre }}</flux:text>
                                <div class="mt-0.5 flex flex-wrap items-center gap-x-3 text-xs text-zinc-500">
                                    <span>{{ $u->ingresos }} {{ Str::plural('ingreso', $u->ingresos) }}</span>
                                    <span>{{ $u->equipos }} {{ Str::plural('equipo', $u->equipos) }}</span>
                                    @if ($u->fallidos > 0)
                                        <span class="text-amber-600 dark:text-amber-400">{{ $u->fallidos }} fallidos</span>
                                    @endif
                                </div>
                            </div>
                            <div class="shrink-0 text-right text-xs text-zinc-500">
                                <div>{{ number_format($u->vistas) }} vistas</div>
                                <div>{{ \Illuminate\Support\Carbon::parse($u->ultimo_acceso)->format('d/m H:i') }}</div>
                            </div>
                        </button>
                    @empty
                        <div class="px-4 py-8 text-center text-sm text-zinc-500">
                            Sin accesos registrados en el periodo.
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- Detalle --}}
        <div class="space-y-4 lg:col-span-3">
            @if ($usuario)
                <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-accent/10 px-3 py-2">
                    <flux:text class="text-sm">
                        Filtrando por <b>{{ $this->nombreUsuarioSeleccionado }}</b>
                    </flux:text>
                    <flux:button wire:click="selectUser(null)" size="xs" variant="ghost">Quitar filtro</flux:button>
                </div>
            @endif

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="acrilico p-5">
                    <flux:subheading class="mb-3">Desde dónde se conectan</flux:subheading>

                    @forelse ($this->equipos as $e)
                        <div class="flex items-center justify-between gap-3 py-1 text-sm">
                            <span class="font-mono text-xs">{{ $e->ip_address ?: 'desconocida' }}</span>
                            <span class="shrink-0 text-xs text-zinc-500">
                                {{ $e->eventos }} · {{ \Illuminate\Support\Carbon::parse($e->ultimo)->format('d/m H:i') }}
                            </span>
                        </div>
                    @empty
                        <flux:text class="text-sm text-zinc-500">Sin datos en el periodo.</flux:text>
                    @endforelse
                </div>

                <div class="acrilico p-5">
                    <flux:subheading class="mb-3">Vistas más consultadas</flux:subheading>

                    @forelse ($this->vistasMasVistas as $v)
                        <div class="flex items-center justify-between gap-3 py-1 text-sm">
                            <span class="min-w-0 flex-1 truncate">{{ $v->route_name }}</span>
                            <span class="shrink-0 font-medium">{{ number_format($v->visitas) }}</span>
                        </div>
                    @empty
                        <flux:text class="text-sm text-zinc-500">Sin navegación registrada.</flux:text>
                    @endforelse
                </div>
            </div>

            {{-- Cronologia --}}
            <div class="overflow-hidden acrilico">
                <div class="flex flex-wrap gap-2 border-b border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-white/10 dark:bg-white/5">
                    @foreach (['accesos' => 'Ingresos y salidas', 'navegacion' => 'Vistas', 'fallidos' => 'Intentos fallidos'] as $clave => $etiqueta)
                        <button
                            type="button"
                            wire:click="setTab('{{ $clave }}')"
                            class="rounded-full border px-3 py-1 text-xs font-medium {{ $tab === $clave ? 'border-accent bg-accent/10 text-accent' : 'border-zinc-300 hover:border-accent dark:border-zinc-600' }}"
                        >
                            {{ $etiqueta }}
                        </button>
                    @endforeach
                </div>

                <div class="divide-y dark:divide-white/10">
                    @forelse ($this->eventos as $evento)
                        <div class="px-4 py-3">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <flux:badge
                                        size="sm"
                                        :color="match ($evento->action) {
                                            \App\Support\Audit\AccessAudit::ACCION_INGRESO => 'emerald',
                                            \App\Support\Audit\AccessAudit::ACCION_SALIDA => 'zinc',
                                            \App\Support\Audit\AccessAudit::ACCION_FALLIDO, \App\Support\Audit\AccessAudit::ACCION_BLOQUEO => 'red',
                                            default => 'sky',
                                        }"
                                    >
                                        {{ \App\Support\Audit\AccessAudit::ACCIONES[$evento->action] ?? $evento->action }}
                                    </flux:badge>

                                    <flux:text class="text-sm font-medium">
                                        {{ $evento->metadata['usuario'] ?? $evento->metadata['correo'] ?? 'Anónimo' }}
                                    </flux:text>

                                    @if ($evento->route_name)
                                        <span class="font-mono text-xs text-zinc-500">{{ $evento->route_name }}</span>
                                    @endif
                                </div>

                                <flux:text class="shrink-0 text-xs text-zinc-500">
                                    {{ $evento->occurred_at->format('d/m/Y H:i:s') }}
                                </flux:text>
                            </div>

                            <div class="mt-1 flex flex-wrap items-center gap-x-3 text-xs text-zinc-500">
                                <span class="font-mono">{{ $evento->ip_address ?: 'sin IP' }}</span>
                                @if ($evento->metadata['equipo'] ?? null)
                                    <span>{{ $evento->metadata['equipo'] }}</span>
                                @endif
                                @if ($evento->metadata['navegador'] ?? null)
                                    <span>{{ $evento->metadata['navegador'] }}</span>
                                @endif
                                @if (($evento->metadata['usuario_existe'] ?? null) === false)
                                    <span class="text-amber-600 dark:text-amber-400">el correo no existe</span>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="px-4 py-8 text-center text-sm text-zinc-500">
                            Sin eventos de este tipo en el periodo.
                        </div>
                    @endforelse
                </div>
            </div>

            <div>{{ $this->eventos->links() }}</div>
        </div>
    </div>
</section>
