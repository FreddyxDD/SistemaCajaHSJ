<?php

use App\Models\Caja\BillableItem;
use App\Models\Caja\ItemCategory;
use App\Models\Caja\PaymentMethod;
use App\Models\Caja\Price;
use App\Support\Caja\LegacyIdGenerator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Catálogo de servicios')] class extends Component {
    use WithPagination;

    public string $q = '';

    public ?string $categoryFilter = null;

    /** Servicio abierto en el panel de precios. */
    public ?string $editingItem = null;

    /** @var array<string, string> precio por forma de pago, indexado por cod_jerar_forma_pago */
    public array $prices = [];

    public ?string $savedMessage = null;

    public function updatedQ(): void
    {
        $this->resetPage();
    }

    public function setCategory(?string $codigo): void
    {
        $this->categoryFilter = $codigo;
        $this->editingItem = null;
        $this->resetPage();
    }

    #[Computed]
    public function categories()
    {
        $gruposConItems = BillableItem::query()->whereNotNull('grupo')->distinct()->pluck('grupo');

        return ItemCategory::query()
            ->whereIn('codigo_grupo', $gruposConItems)
            ->orderBy('nombre_grupo_nomen')
            ->get();
    }

    /**
     * Formas de pago "hoja": las que realmente se cobran. Los nodos con hijos son
     * agrupadores de la jerarquia y nunca llevan precio propio.
     */
    #[Computed]
    public function paymentMethods()
    {
        $conHijos = PaymentMethod::query()
            ->whereNotNull('relacion_forma_pago')
            ->distinct()
            ->pluck('relacion_forma_pago');

        return PaymentMethod::query()
            ->whereNotIn('cod_jerar_forma_pago', $conHijos)
            ->orderBy('fp_padre')
            ->orderBy('nom_forma_pago')
            ->get();
    }

    #[Computed]
    public function items()
    {
        return BillableItem::query()
            ->when($this->categoryFilter, fn ($query) => $query->where('grupo', $this->categoryFilter))
            ->when(trim($this->q) !== '', function ($query) {
                $like = '%'.trim($this->q).'%';

                $query->where(fn ($q) => $q->where('descripcion_nomen_tipo', 'like', $like)
                    ->orWhere('nomen_caja', 'like', $like)
                    ->orWhere('cod_nomen_caja', 'like', $like));
            })
            ->orderBy('descripcion_nomen_tipo')
            ->paginate(15);
    }

    /** Cuantas formas de pago tienen precio cargado, por servicio de la pagina actual. */
    #[Computed]
    public function priceCoverage(): array
    {
        $codes = collect($this->items->items())->pluck('cod_nomen_caja');

        if ($codes->isEmpty()) {
            return [];
        }

        return DB::connection('caja')
            ->table('Precio_MH')
            ->whereIn('cod_nomen_caja', $codes)
            ->selectRaw('cod_nomen_caja, COUNT(*) as formas, MIN(precio) as minimo, MAX(precio) as maximo')
            ->groupBy('cod_nomen_caja')
            ->get()
            ->keyBy('cod_nomen_caja')
            ->all();
    }

    public function edit(string $codNomenCaja): void
    {
        if ($this->editingItem === $codNomenCaja) {
            $this->editingItem = null;

            return;
        }

        $this->editingItem = $codNomenCaja;
        $this->savedMessage = null;
        $this->resetErrorBag();

        $existentes = Price::query()
            ->where('cod_nomen_caja', $codNomenCaja)
            ->get()
            ->keyBy('cod_jerar_forma_pago');

        // Toda forma de pago aparece en el formulario; vacio significa "no se cobra
        // este servicio con esa forma de pago".
        $this->prices = $this->paymentMethods
            ->mapWithKeys(fn ($pm) => [
                $pm->cod_jerar_forma_pago => $existentes->has($pm->cod_jerar_forma_pago)
                    ? number_format((float) $existentes[$pm->cod_jerar_forma_pago]->precio, 2, '.', '')
                    : '',
            ])
            ->all();
    }

    public function save(): void
    {
        abort_unless(Auth::user()?->canDo('caja.catalog.manage'), 403);

        $item = BillableItem::query()->findOrFail($this->editingItem);

        $rules = [];
        foreach (array_keys($this->prices) as $code) {
            $rules["prices.{$code}"] = ['nullable', 'numeric', 'min:0', 'max:99999.99'];
        }

        $this->validate($rules, [], collect($this->prices)->mapWithKeys(
            fn ($v, $code) => ["prices.{$code}" => 'precio'],
        )->all());

        $usuario = mb_substr(Auth::user()->name, 0, 100);
        $creados = 0;
        $actualizados = 0;
        $eliminados = 0;

        DB::connection('caja')->transaction(function () use ($item, $usuario, &$creados, &$actualizados, &$eliminados) {
            $existentes = Price::query()
                ->where('cod_nomen_caja', $item->cod_nomen_caja)
                ->get()
                ->keyBy('cod_jerar_forma_pago');

            foreach ($this->prices as $formaPago => $valor) {
                $valor = trim((string) $valor);
                $actual = $existentes->get($formaPago);

                if ($valor === '') {
                    // Quitar el precio equivale a dejar de ofrecer el servicio con esa
                    // forma de pago; los comprobantes ya emitidos no se tocan.
                    if ($actual) {
                        $actual->delete();
                        $eliminados++;
                    }

                    continue;
                }

                if ($actual) {
                    if ((float) $actual->precio !== (float) $valor) {
                        $actual->update([
                            'precio' => (float) $valor,
                            'nom_usu' => $usuario,
                            'fecha_actu' => now()->format('d/m/Y'),
                            'hora_actu' => now()->format('H:i:s'),
                        ]);
                        $actualizados++;
                    }

                    continue;
                }

                Price::query()->create([
                    'cod_precio' => LegacyIdGenerator::nextPriceCode(),
                    'cod_jerar_forma_pago' => $formaPago,
                    'cod_nomen_caja' => $item->cod_nomen_caja,
                    'precio' => (float) $valor,
                    'nom_usu' => $usuario,
                    'fecha_actu' => now()->format('d/m/Y'),
                    'hora_actu' => now()->format('H:i:s'),
                ]);
                $creados++;
            }
        });

        unset($this->priceCoverage);

        $this->savedMessage = collect([
            $creados ? "$creados precio(s) agregado(s)" : null,
            $actualizados ? "$actualizados actualizado(s)" : null,
            $eliminados ? "$eliminados retirado(s)" : null,
        ])->filter()->implode(', ') ?: 'No hubo cambios que guardar.';
    }
}; ?>

<section class="w-full space-y-6">
    <div>
        <flux:heading size="xl">Catálogo de servicios y precios</flux:heading>
        <flux:text class="text-zinc-500">
            Mantenimiento del catálogo facturable (<code class="text-xs">Nomenclatura_caja_MH</code>) y de su precio en
            cada forma de pago (<code class="text-xs">Precio_MH</code>). Un servicio sin precio en una forma de pago
            no se puede cobrar con ella.
        </flux:text>
    </div>

    @if ($savedMessage)
        <div class="flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-500/30 dark:bg-emerald-400/10">
            <flux:icon.check-circle class="mt-0.5 size-5 shrink-0 text-emerald-600 dark:text-emerald-400" />
            <flux:text class="text-emerald-800 dark:text-emerald-300">{{ $savedMessage }}</flux:text>
        </div>
    @endif

    <div class="flex flex-wrap gap-2">
        <button
            type="button"
            wire:click="setCategory(null)"
            class="rounded-full border px-3 py-1.5 text-sm font-medium {{ $categoryFilter === null ? 'border-accent bg-accent/10 text-accent' : 'border-zinc-300 hover:border-accent dark:border-zinc-600' }}"
        >
            Todos
        </button>
        @foreach ($this->categories as $categoria)
            <button
                type="button"
                wire:click="setCategory('{{ $categoria->codigo_grupo }}')"
                class="rounded-full border px-3 py-1.5 text-sm font-medium {{ $categoryFilter === $categoria->codigo_grupo ? 'border-accent bg-accent/10 text-accent' : 'border-zinc-300 hover:border-accent dark:border-zinc-600' }}"
            >
                {{ ucfirst(mb_strtolower($categoria->nombre_grupo_nomen)) }}
            </button>
        @endforeach
    </div>

    <flux:input
        wire:model.live.debounce.400ms="q"
        icon="magnifying-glass"
        placeholder="Buscar servicio por nombre o código..."
        clearable
    />

    <div class="overflow-hidden acrilico rounded-xl border border-zinc-200 dark:border-white/10">
        <div class="divide-y dark:divide-white/10">
            @forelse ($this->items as $item)
                @php $cobertura = $this->priceCoverage[$item->cod_nomen_caja] ?? null; @endphp

                <div>
                    <button
                        type="button"
                        wire:click="edit('{{ $item->cod_nomen_caja }}')"
                        class="flex w-full items-center gap-4 px-4 py-3 text-left hover:bg-zinc-50 dark:hover:bg-white/5"
                    >
                        <div class="min-w-0 flex-1">
                            <flux:text class="font-medium">{{ $item->descripcion_nomen_tipo }}</flux:text>
                            <div class="mt-0.5 text-xs text-zinc-500">
                                {{ $item->cod_nomen_caja }}
                                @if ($item->nomen_caja)
                                    · {{ $item->nomen_caja }}
                                @endif
                            </div>
                        </div>

                        <div class="shrink-0 text-right text-xs">
                            @if ($cobertura)
                                <flux:badge size="sm" color="zinc">{{ $cobertura->formas }} formas de pago</flux:badge>
                                <div class="mt-0.5 text-zinc-500">
                                    S/ {{ number_format($cobertura->minimo, 2) }} – {{ number_format($cobertura->maximo, 2) }}
                                </div>
                            @else
                                <flux:badge size="sm" color="amber">Sin precios</flux:badge>
                            @endif
                        </div>

                        <flux:icon.chevron-down class="size-4 shrink-0 text-zinc-400 {{ $editingItem === $item->cod_nomen_caja ? 'rotate-180' : '' }}" />
                    </button>

                    @if ($editingItem === $item->cod_nomen_caja)
                        <div class="border-t border-zinc-200 bg-zinc-50 px-4 py-4 dark:border-white/10 dark:bg-white/5">
                            <flux:text class="mb-3 block text-xs text-zinc-500">
                                Precio por forma de pago. Deja el campo vacío para que el servicio no se ofrezca con esa
                                forma de pago.
                            </flux:text>

                            @php $porGrupo = $this->paymentMethods->groupBy(fn ($pm) => trim((string) $pm->fp_padre) ?: 'OTROS'); @endphp

                            <div class="space-y-4">
                                @foreach ($porGrupo as $nombreGrupo => $formas)
                                    <div>
                                        <div class="mb-2 flex items-center gap-2">
                                            <span class="size-2 rounded-full {{ \App\Support\Caja\PaymentMethodPalette::dot($nombreGrupo) }}"></span>
                                            <flux:text class="text-xs font-semibold tracking-wide uppercase">{{ $nombreGrupo }}</flux:text>
                                        </div>

                                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                            @foreach ($formas as $pm)
                                                <flux:input
                                                    wire:model="prices.{{ $pm->cod_jerar_forma_pago }}"
                                                    :label="$pm->nom_forma_pago"
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    placeholder="Sin precio"
                                                    size="sm"
                                                />
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            <div class="mt-4 flex items-center gap-3">
                                <flux:button wire:click="save" variant="primary" size="sm" icon="check">
                                    Guardar precios
                                </flux:button>
                                <flux:button wire:click="edit('{{ $item->cod_nomen_caja }}')" variant="ghost" size="sm">
                                    Cancelar
                                </flux:button>
                                <flux:text class="text-xs text-zinc-500" wire:loading wire:target="save">Guardando...</flux:text>
                            </div>
                        </div>
                    @endif
                </div>
            @empty
                <div class="px-4 py-8 text-center text-sm text-zinc-500">No hay servicios que coincidan.</div>
            @endforelse
        </div>
    </div>

    <div>{{ $this->items->links() }}</div>
</section>
