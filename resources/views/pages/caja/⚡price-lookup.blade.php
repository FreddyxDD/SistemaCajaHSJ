<?php

use App\Models\Caja\BillableItem;
use App\Models\Caja\ItemCategory;
use App\Models\Caja\PaymentMethod;
use App\Models\Caja\Price;
use App\Support\Caja\CatalogChanges;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Consulta publica del tarifario, de solo lectura.
 *
 * A caja se acerca personal —sobre todo asistentas sociales— a preguntar cuanto
 * cuesta lo que el medico indico, y hoy tienen que interrumpir al cajero en plena
 * atencion. Esta pantalla contesta esa pregunta sin tocar la caja: no abre turno, no
 * emite comprobantes y no escribe nada.
 *
 * Ademas permite armar la lista de examenes y simular el descuento de 25/50/75/100 %
 * que la asistenta social evalua. La simulacion es orientativa: el cobro real y su
 * descuento los aplica caja al emitir el comprobante.
 */
new #[Title('Consulta de precios')] class extends Component {
    #[Url(as: 'fp', except: '')]
    public string $paymentMethodCode = '';

    #[Url(as: 'q', except: '')]
    public string $q = '';

    public ?string $categoryFilter = null;

    /** @var array<int, string> cod_precio de los servicios agregados a la cotizacion */
    public array $selected = [];

    /** Descuento simulado: 0, 25, 50, 75 o 100. */
    public int $discount = 0;

    public function mount(): void
    {
        abort_unless(Auth::user()?->canDo('caja.prices.view'), 403);

        // Se abre en la forma de pago con mas tarifas cargadas, no en la primera
        // alfabetica: varias formas de pago existen en el catalogo sin un solo precio
        // (ANIVERSARIO, por ejemplo) y abrir ahi deja la pantalla en blanco.
        if ($this->paymentMethodCode === '') {
            $this->paymentMethodCode = (string) ($this->paymentMethods
                ->sortByDesc('precios_cargados')
                ->first()?->cod_jerar_forma_pago ?? '');
        }
    }

    /**
     * Formas de pago "hoja" (las unicas con precio propio), con cuantas tarifas tiene
     * cargada cada una.
     */
    #[Computed]
    public function paymentMethods()
    {
        $conHijos = PaymentMethod::query()
            ->whereNotNull('relacion_forma_pago')
            ->distinct()
            ->pluck('relacion_forma_pago');

        $conteo = Price::query()
            ->selectRaw('cod_jerar_forma_pago, COUNT(*) as n')
            ->groupBy('cod_jerar_forma_pago')
            ->pluck('n', 'cod_jerar_forma_pago');

        return PaymentMethod::query()
            ->whereNotIn('cod_jerar_forma_pago', $conHijos)
            ->orderByRaw("CASE WHEN fp_padre = 'CONTADO' THEN 0 ELSE 1 END")
            ->orderBy('fp_padre')
            ->orderBy('nom_forma_pago')
            ->get()
            ->each(fn ($pm) => $pm->setAttribute(
                'precios_cargados',
                (int) ($conteo[$pm->cod_jerar_forma_pago] ?? 0),
            ));
    }

    #[Computed]
    public function selectedPaymentMethod(): ?PaymentMethod
    {
        return $this->paymentMethods->firstWhere('cod_jerar_forma_pago', $this->paymentMethodCode);
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

    #[Computed]
    public function changes(): array
    {
        return CatalogChanges::recent($this->selectedPaymentMethod?->nom_forma_pago);
    }

    #[Computed]
    public function results()
    {
        if ($this->paymentMethodCode === '') {
            return collect();
        }

        return Price::query()
            ->where('cod_jerar_forma_pago', $this->paymentMethodCode)
            ->with('billableItem')
            // Un servicio retirado del catalogo no se cobra, asi que tampoco se cotiza.
            ->whereHas('billableItem', fn ($q) => $q->where(
                fn ($activo) => $activo->where('estado_nomenclatura', true)->orWhereNull('estado_nomenclatura')
            ))
            ->when($this->categoryFilter, fn ($query) => $query->whereHas(
                'billableItem',
                fn ($q) => $q->where('grupo', $this->categoryFilter),
            ))
            ->when(mb_strlen(trim($this->q)) >= 2, fn ($query) => $query->whereHas(
                'billableItem',
                fn ($q) => $q->search(trim($this->q)),
            ))
            ->join('Nomenclatura_caja_MH as nc', 'nc.cod_nomen_caja', '=', 'Precio_MH.cod_nomen_caja')
            ->orderBy('nc.descripcion_nomen_tipo')
            ->select('Precio_MH.*')
            ->limit(60)
            ->get();
    }

    /** Los servicios elegidos, resueltos aunque queden fuera del filtro actual. */
    #[Computed]
    public function selectedItems()
    {
        if ($this->selected === []) {
            return collect();
        }

        return Price::query()
            ->whereIn('cod_precio', $this->selected)
            ->with('billableItem')
            ->get();
    }

    #[Computed]
    public function quote(): array
    {
        $total = (float) $this->selectedItems->sum('precio');
        $descuento = round($total * $this->discount / 100, 2);

        return [
            'total' => $total,
            'descuento' => $descuento,
            'pagar' => round($total - $descuento, 2),
        ];
    }

    public function toggle(string $codPrecio): void
    {
        $this->selected = in_array($codPrecio, $this->selected, true)
            ? array_values(array_diff($this->selected, [$codPrecio]))
            : [...$this->selected, $codPrecio];
    }

    public function clearQuote(): void
    {
        $this->selected = [];
        $this->discount = 0;
    }

    public function updatedPaymentMethodCode(): void
    {
        // Los precios cambian con la forma de pago: una cotizacion armada con otra
        // tarifa dejaria de ser cierta sin que se note.
        $this->clearQuote();
    }

    public function setCategory(?string $codigo): void
    {
        $this->categoryFilter = $codigo;
    }
}; ?>

<section class="w-full space-y-6">
    <div>
        <flux:heading size="xl">Consulta de precios</flux:heading>
        <flux:text class="text-zinc-500">
            Tarifario vigente del hospital. Esta pantalla solo consulta: no registra cobros ni modifica nada.
        </flux:text>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <flux:card class="space-y-4">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <flux:select wire:model.live="paymentMethodCode" label="Forma de pago">
                        @foreach ($this->paymentMethods as $pm)
                            <flux:select.option value="{{ $pm->cod_jerar_forma_pago }}">
                                {{ trim($pm->nom_forma_pago) }}{{ trim((string) $pm->fp_padre) ? ' ('.trim($pm->fp_padre).')' : '' }}{{ $pm->precios_cargados === 0 ? ' — sin tarifario' : '' }}
                            </flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:input wire:model.live.debounce.400ms="q" label="Buscar" icon="magnifying-glass" placeholder="Nombre del examen o servicio..." clearable />
                </div>

                <div class="flex flex-wrap gap-2">
                    <button
                        type="button"
                        wire:click="setCategory(null)"
                        class="rounded-full border px-3 py-1 text-xs font-medium {{ $categoryFilter === null ? 'border-accent bg-accent/10 text-accent' : 'border-zinc-300 hover:border-accent dark:border-zinc-600' }}"
                    >
                        Todas
                    </button>
                    @foreach ($this->categories as $categoria)
                        <button
                            type="button"
                            wire:click="setCategory('{{ $categoria->codigo_grupo }}')"
                            class="rounded-full border px-3 py-1 text-xs font-medium {{ $categoryFilter === $categoria->codigo_grupo ? 'border-accent bg-accent/10 text-accent' : 'border-zinc-300 hover:border-accent dark:border-zinc-600' }}"
                        >
                            {{ ucfirst(mb_strtolower($categoria->nombre_grupo_nomen)) }}
                        </button>
                    @endforeach
                </div>

                @if (! empty($this->changes))
                    <div class="flex items-start gap-2 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 dark:border-amber-500/40 dark:bg-amber-400/10">
                        <flux:icon.megaphone class="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400" />
                        <flux:text class="text-xs text-amber-900 dark:text-amber-200">
                            {{ count($this->changes) }} {{ Str::plural('servicio', count($this->changes)) }} con cambios en los
                            últimos {{ (int) config('caja.avisos_cambios_dias', 7) }} días. Van marcados en la lista.
                        </flux:text>
                    </div>
                @endif

                <div class="max-h-[32rem] divide-y overflow-y-auto rounded-lg border dark:divide-zinc-700 dark:border-zinc-700">
                    @forelse ($this->results as $price)
                        @php
                            $elegido = in_array($price->cod_precio, $selected, true);
                            $cambio = $this->changes[$price->cod_nomen_caja] ?? null;
                        @endphp
                        <button
                            type="button"
                            wire:click="toggle('{{ $price->cod_precio }}')"
                            class="flex w-full items-center justify-between gap-4 px-3 py-2.5 text-left {{ $elegido ? 'bg-accent/10' : 'hover:bg-zinc-100 dark:hover:bg-zinc-800' }}"
                        >
                            <span class="min-w-0 flex-1">
                                <span class="text-sm {{ $elegido ? 'font-medium text-accent' : '' }}">
                                    {{ $price->billableItem?->descripcion_nomen_tipo }}
                                </span>

                                @if ($cambio)
                                    <span class="mt-1 flex flex-wrap items-center gap-2">
                                        <x-catalog-change-mark :change="$cambio" />
                                        <span class="text-xs text-zinc-500">{{ $cambio['detalle'] }}</span>
                                    </span>
                                @endif
                            </span>

                            <span class="flex shrink-0 items-center gap-2">
                                <span class="font-medium whitespace-nowrap">S/ {{ number_format($price->precio, 2) }}</span>
                                @if ($elegido)
                                    <flux:icon.check-circle class="size-5 text-accent" />
                                @else
                                    <flux:icon.plus-circle class="size-5 text-zinc-400" />
                                @endif
                            </span>
                        </button>
                    @empty
                        <div class="px-3 py-6 text-center text-sm text-zinc-500">
                            @if (mb_strlen(trim($q)) >= 2)
                                Ningún servicio coincide con "{{ $q }}" en esta forma de pago.
                            @else
                                No hay servicios con precio para esta forma de pago.
                            @endif
                        </div>
                    @endforelse
                </div>

                @if ($this->results->count() >= 60)
                    <flux:text class="text-xs text-zinc-500">
                        Se muestran los primeros 60 resultados. Usa la búsqueda o las categorías para acotar.
                    </flux:text>
                @endif
            </flux:card>
        </div>

        {{-- Cotización y simulación de descuento --}}
        <div class="lg:sticky lg:top-6 lg:h-fit">
            <flux:card class="space-y-4">
                <div class="flex items-center justify-between">
                    <flux:subheading>Cotización</flux:subheading>
                    @if ($selected !== [])
                        <flux:button wire:click="clearQuote" size="xs" variant="ghost">Limpiar</flux:button>
                    @endif
                </div>

                @if ($selected === [])
                    <flux:text class="text-sm text-zinc-500">
                        Elige los exámenes o servicios de la lista para ver cuánto suman.
                    </flux:text>
                @else
                    <div class="max-h-64 space-y-2 overflow-y-auto">
                        @foreach ($this->selectedItems as $item)
                            <div class="flex items-start justify-between gap-2 text-sm">
                                <span class="min-w-0 flex-1">{{ $item->billableItem?->descripcion_nomen_tipo }}</span>
                                <span class="shrink-0 font-medium">S/ {{ number_format($item->precio, 2) }}</span>
                                <button type="button" wire:click="toggle('{{ $item->cod_precio }}')" class="shrink-0 text-zinc-400 hover:text-red-500">
                                    <flux:icon.x-mark class="size-4" />
                                </button>
                            </div>
                        @endforeach
                    </div>

                    <flux:separator />

                    <div>
                        <flux:text class="mb-2 block text-xs text-zinc-500">Descuento social a simular</flux:text>
                        <div class="grid grid-cols-5 gap-1">
                            @foreach ([0, 25, 50, 75, 100] as $porcentaje)
                                <button
                                    type="button"
                                    wire:click="$set('discount', {{ $porcentaje }})"
                                    class="rounded-md border px-2 py-1.5 text-sm font-medium {{ $discount === $porcentaje ? 'border-accent bg-accent/10 text-accent' : 'border-zinc-300 hover:border-accent dark:border-zinc-600' }}"
                                >
                                    {{ $porcentaje }}%
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <div class="space-y-1 text-sm">
                        <div class="flex justify-between">
                            <span class="text-zinc-500">Total del tarifario</span>
                            <span class="font-medium">S/ {{ number_format($this->quote['total'], 2) }}</span>
                        </div>
                        @if ($discount > 0)
                            <div class="flex justify-between text-emerald-700 dark:text-emerald-400">
                                <span>Descuento {{ $discount }}%</span>
                                <span class="font-medium">− S/ {{ number_format($this->quote['descuento'], 2) }}</span>
                            </div>
                        @endif
                    </div>

                    <div class="flex items-baseline justify-between border-t pt-3 dark:border-zinc-700">
                        <span class="font-medium">A pagar</span>
                        <span class="text-2xl font-bold text-accent">S/ {{ number_format($this->quote['pagar'], 2) }}</span>
                    </div>

                    <flux:text class="text-xs text-zinc-500">
                        Cálculo referencial con la tarifa
                        <b>{{ trim((string) $this->selectedPaymentMethod?->nom_forma_pago) }}</b>.
                        El cobro y el descuento definitivos los aplica caja al emitir el comprobante.
                    </flux:text>
                @endif
            </flux:card>
        </div>
    </div>
</section>
