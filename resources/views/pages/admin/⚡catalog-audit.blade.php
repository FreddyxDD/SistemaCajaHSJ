<?php

use App\Models\AuditEvent;
use App\Models\Caja\BillableItem;
use App\Models\User;
use App\Support\Caja\CatalogAudit;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Vista del auditor sobre los cambios del catalogo.
 *
 * Es solo lectura a proposito: quien audita no modifica. Cada evento se muestra con
 * su antes y su despues campo por campo, quien lo hizo, cuando y con que motivo.
 */
new #[Title('Auditoría del catálogo')] class extends Component {
    use WithPagination;

    #[Url(as: 'item', except: '')]
    public string $itemFilter = '';

    #[Url(as: 'accion', except: 'all')]
    public string $accionFilter = 'all';

    #[Url(as: 'usuario', except: '')]
    public string $usuarioFilter = '';

    #[Url(as: 'desde', except: '')]
    public string $desde = '';

    #[Url(as: 'hasta', except: '')]
    public string $hasta = '';

    public function mount(): void
    {
        abort_unless(Auth::user()?->canDo('caja.catalog.audit'), 403);
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    public function limpiar(): void
    {
        $this->itemFilter = '';
        $this->accionFilter = 'all';
        $this->usuarioFilter = '';
        $this->desde = '';
        $this->hasta = '';
        $this->resetPage();
    }

    /** Usuarios que efectivamente han tocado el catalogo; no todo el padron. */
    #[Computed]
    public function usuarios()
    {
        $ids = AuditEvent::query()
            ->where('module', CatalogAudit::MODULE)
            ->whereNotNull('user_id')
            ->distinct()
            ->pluck('user_id');

        return User::query()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function totals(): array
    {
        $base = fn () => AuditEvent::query()->where('module', CatalogAudit::MODULE);

        return [
            'eventos' => $base()->count(),
            'servicios' => $base()->distinct()->count('auditable_id'),
            'ultimos30' => $base()->where('occurred_at', '>=', now()->subDays(30))->count(),
            'usuarios' => $base()->whereNotNull('user_id')->distinct()->count('user_id'),
        ];
    }

    #[Computed]
    public function events()
    {
        return AuditEvent::query()
            ->where('module', CatalogAudit::MODULE)
            ->when($this->accionFilter !== 'all', fn ($q) => $q->where('action', $this->accionFilter))
            ->when($this->usuarioFilter !== '', fn ($q) => $q->where('user_id', $this->usuarioFilter))
            ->when($this->desde !== '', fn ($q) => $q->whereDate('occurred_at', '>=', $this->desde))
            ->when($this->hasta !== '', fn ($q) => $q->whereDate('occurred_at', '<=', $this->hasta))
            ->when(trim($this->itemFilter) !== '', function ($query) {
                $termino = trim($this->itemFilter);

                // El auditor busca por nombre del servicio, no por su codigo interno;
                // se resuelven primero los codigos que coinciden con esa descripcion.
                $codigos = BillableItem::query()
                    ->where('descripcion_nomen_tipo', 'like', "%{$termino}%")
                    ->orWhere('cod_nomen_caja', 'like', "%{$termino}%")
                    ->orWhere('nomen_caja', 'like', "%{$termino}%")
                    ->limit(200)
                    ->pluck('cod_nomen_caja');

                $query->whereIn('auditable_id', $codigos);
            })
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(20);
    }
}; ?>

<section class="w-full space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">Auditoría del catálogo</flux:heading>
            <flux:text class="text-zinc-500">
                Historial completo de altas, bajas, ediciones y cambios de precio, con el valor anterior y el posterior
                de cada campo. Es una vista de solo lectura.
            </flux:text>
        </div>

        <flux:button href="{{ route('admin.catalog.index') }}" wire:navigate variant="ghost" size="sm" icon="arrow-left">
            Volver al catálogo
        </flux:button>
    </div>

    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        @php
            $tarjetas = [
                ['Cambios registrados', $this->totals['eventos'], 'En total'],
                ['Servicios afectados', $this->totals['servicios'], 'Con al menos un cambio'],
                ['Últimos 30 días', $this->totals['ultimos30'], 'Cambios recientes'],
                ['Responsables', $this->totals['usuarios'], 'Usuarios que modificaron'],
            ];
        @endphp

        @foreach ($tarjetas as [$titulo, $valor, $detalle])
            <div class="acrilico p-5">
                <flux:text class="text-sm text-zinc-500">{{ $titulo }}</flux:text>
                <div class="mt-1 text-2xl font-semibold">{{ $valor }}</div>
                <flux:text class="text-xs text-zinc-500">{{ $detalle }}</flux:text>
            </div>
        @endforeach
    </div>

    <div class="acrilico space-y-3 p-4">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <flux:input
                wire:model.live.debounce.400ms="itemFilter"
                label="Servicio"
                icon="magnifying-glass"
                placeholder="Nombre o código..."
                size="sm"
            />

            <flux:select wire:model.live="accionFilter" label="Tipo de cambio" size="sm">
                <flux:select.option value="all">Todos</flux:select.option>
                @foreach (\App\Support\Caja\CatalogAudit::ACCIONES as $codigo => $etiqueta)
                    <flux:select.option value="{{ $codigo }}">{{ $etiqueta }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="usuarioFilter" label="Responsable" size="sm">
                <flux:select.option value="">Todos</flux:select.option>
                @foreach ($this->usuarios as $usuario)
                    <flux:select.option value="{{ $usuario->id }}">{{ $usuario->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model.live="desde" label="Desde" type="date" size="sm" />
            <flux:input wire:model.live="hasta" label="Hasta" type="date" size="sm" />
        </div>

        <div class="flex items-center justify-between">
            <flux:text class="text-xs text-zinc-500">
                {{ $this->events->total() }} {{ \Illuminate\Support\Str::plural('cambio', $this->events->total()) }}
                {{ $this->events->total() === $this->totals['eventos'] ? 'registrados' : 'con los filtros aplicados' }}
            </flux:text>
            <flux:button wire:click="limpiar" variant="ghost" size="xs" icon="x-mark">Limpiar filtros</flux:button>
        </div>
    </div>

    <div class="space-y-3">
        @forelse ($this->events as $evento)
            <x-catalog-audit-entry :event="$evento" />
        @empty
            <div class="acrilico px-4 py-10 text-center">
                <flux:text class="text-sm text-zinc-500">
                    @if ($this->totals['eventos'] === 0)
                        Todavía no hay cambios registrados en el catálogo. A partir de ahora, cada alta, baja, edición o
                        cambio de precio quedará aquí con su antes y su después.
                    @else
                        Ningún cambio coincide con los filtros aplicados.
                    @endif
                </flux:text>
            </div>
        @endforelse
    </div>

    <div>{{ $this->events->links() }}</div>
</section>
