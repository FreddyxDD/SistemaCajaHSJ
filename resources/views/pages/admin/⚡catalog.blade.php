<?php

use App\Models\AuditEvent;
use App\Models\Caja\BillableItem;
use App\Models\Caja\ItemCategory;
use App\Models\Caja\PaymentMethod;
use App\Models\Caja\Price;
use App\Support\Caja\CatalogAudit;
use App\Support\Caja\LegacyIdGenerator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Catálogo de servicios')] class extends Component {
    use WithPagination;

    /** Valores fijos del legado: hoy todas las filas de Nomenclatura_caja_MH los comparten. */
    private const TIPO_NOMEN = 'PAGOS ADMINISTRATIVOS';

    private const FUNCION_NOMEN = 'Nomenclatura';

    public string $q = '';

    public ?string $categoryFilter = null;

    public string $estadoFilter = 'activos';

    /** Servicio abierto en el panel de edición. */
    public ?string $editingItem = null;

    /** @var array<string, string> precio por forma de pago, indexado por cod_jerar_forma_pago */
    public array $prices = [];

    /** @var array<string, mixed> datos editables del servicio abierto */
    public array $form = [];

    public bool $showCreate = false;

    /** @var array<string, mixed> */
    public array $createForm = [];

    /** Servicio cuyo cambio de estado se está confirmando. */
    public ?string $statusTarget = null;

    public string $statusMotivo = '';

    public ?string $savedMessage = null;

    public function mount(): void
    {
        $this->resetCreateForm();
    }

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

    public function setEstado(string $estado): void
    {
        $this->estadoFilter = $estado;
        $this->editingItem = null;
        $this->resetPage();
    }

    #[Computed]
    public function canManage(): bool
    {
        return (bool) Auth::user()?->canDo('caja.catalog.manage');
    }

    #[Computed]
    public function canAudit(): bool
    {
        return (bool) Auth::user()?->canDo('caja.catalog.audit');
    }

    #[Computed]
    public function categories()
    {
        return ItemCategory::query()->orderBy('nombre_grupo_nomen')->get();
    }

    /** Servicios de la jerarquia (SERVICIO_JERARQUIA_3) a los que se asocia el item. */
    #[Computed]
    public function servicios()
    {
        return DB::connection('caja')
            ->table('SERVICIO_JERARQUIA_3')
            ->selectRaw('cod_nivel_servicio_3, RTRIM(nom_ser_3) as nom_ser_3')
            ->orderBy('nom_ser_3')
            ->get();
    }

    /** Cuentas contables de septimo nivel: definen a que cuenta entra lo recaudado. */
    #[Computed]
    public function cuentas()
    {
        return DB::connection('caja')
            ->table('Cuenta_7')
            ->selectRaw("Id_cuenta7, RTRIM(Cuenta_7) + ' - ' + RTRIM(descripcion_7) as etiqueta")
            ->orderBy('Cuenta_7')
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
            ->when($this->estadoFilter === 'activos', fn ($query) => $query->where('estado_nomenclatura', true))
            ->when($this->estadoFilter === 'inactivos', fn ($query) => $query->where(
                fn ($q) => $q->where('estado_nomenclatura', false)->orWhereNull('estado_nomenclatura')
            ))
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

    /** Ultimos movimientos del servicio abierto, para no tener que ir a la auditoria. */
    #[Computed]
    public function itemHistory()
    {
        if (! $this->editingItem) {
            return collect();
        }

        return AuditEvent::query()
            ->where('module', CatalogAudit::MODULE)
            ->where('auditable_id', $this->editingItem)
            ->orderByDesc('occurred_at')
            ->limit(3)
            ->get();
    }

    public function edit(string $codNomenCaja): void
    {
        if ($this->editingItem === $codNomenCaja) {
            $this->editingItem = null;

            return;
        }

        $item = BillableItem::query()->findOrFail($codNomenCaja);

        $this->editingItem = $codNomenCaja;
        $this->savedMessage = null;
        $this->statusTarget = null;
        $this->resetErrorBag();
        $this->form = $this->itemAttributes($item);

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

    public function saveItem(): void
    {
        abort_unless($this->canManage, 403);

        $item = BillableItem::query()->findOrFail($this->editingItem);
        $validated = $this->validate($this->itemRules('form'))['form'];

        $antes = $this->itemLabels($this->itemAttributes($item));

        $item->fill([
            'descripcion_nomen_tipo' => $validated['descripcion_nomen_tipo'],
            'nomen_caja' => $validated['nomen_caja'],
            'cod_grupo_nomen_aten' => $validated['cod_grupo_nomen_aten'],
            'grupo' => $this->grupoDeCategoria($validated['cod_grupo_nomen_aten']),
            'cod_nivel_servicio_3' => $validated['cod_nivel_servicio_3'],
            'id_cuenta7' => $validated['id_cuenta7'],
            'vis_admi' => $validated['vis_admi'],
            'vis_aten' => $validated['vis_aten'],
        ]);

        [$cambioAntes, $cambioDespues] = CatalogAudit::diff(
            $antes,
            $this->itemLabels($this->itemAttributes($item)),
        );

        if ($cambioDespues === []) {
            $this->savedMessage = 'No hubo cambios que guardar en los datos del servicio.';

            return;
        }

        $this->stampLegacy($item);
        $item->save();

        CatalogAudit::record(
            CatalogAudit::ACCION_EDICION,
            CatalogAudit::ITEM,
            $item->cod_nomen_caja,
            $cambioAntes,
            $cambioDespues,
            ['servicio' => $item->descripcion_nomen_tipo],
        );

        $this->form = $this->itemAttributes($item->refresh());
        unset($this->itemHistory);
        $this->savedMessage = 'Datos del servicio actualizados. El cambio quedó registrado en la auditoría.';
    }

    public function save(): void
    {
        abort_unless($this->canManage, 403);

        $item = BillableItem::query()->findOrFail($this->editingItem);

        $rules = [];
        foreach (array_keys($this->prices) as $code) {
            $rules["prices.{$code}"] = ['nullable', 'numeric', 'min:0', 'max:99999.99'];
        }

        $this->validate($rules);

        $usuario = mb_substr((string) Auth::user()->name, 0, 70);
        $etiquetas = $this->paymentMethods->keyBy('cod_jerar_forma_pago');
        $antes = [];
        $despues = [];

        DB::connection('caja')->transaction(function () use ($item, $usuario, $etiquetas, &$antes, &$despues) {
            $existentes = Price::query()
                ->where('cod_nomen_caja', $item->cod_nomen_caja)
                ->get()
                ->keyBy('cod_jerar_forma_pago');

            foreach ($this->prices as $formaPago => $valor) {
                $valor = trim((string) $valor);
                $actual = $existentes->get($formaPago);
                $etiqueta = trim((string) ($etiquetas[$formaPago]->nom_forma_pago ?? $formaPago));

                if ($valor === '') {
                    // Quitar el precio equivale a dejar de ofrecer el servicio con esa
                    // forma de pago; los comprobantes ya emitidos no se tocan.
                    if ($actual) {
                        $antes[$etiqueta] = number_format((float) $actual->precio, 2, '.', '');
                        $despues[$etiqueta] = 'sin precio';
                        $actual->delete();
                    }

                    continue;
                }

                if ($actual) {
                    if (round((float) $actual->precio, 2) !== round((float) $valor, 2)) {
                        $antes[$etiqueta] = number_format((float) $actual->precio, 2, '.', '');
                        $despues[$etiqueta] = number_format((float) $valor, 2, '.', '');

                        $actual->update([
                            'precio' => (float) $valor,
                            'nom_usu' => $usuario,
                            'fecha_actu' => now()->format('d/m/Y'),
                            'hora_actu' => now()->format('H:i:s'),
                        ]);
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

                $antes[$etiqueta] = 'sin precio';
                $despues[$etiqueta] = number_format((float) $valor, 2, '.', '');
            }
        });

        unset($this->priceCoverage, $this->itemHistory);

        if ($despues === []) {
            $this->savedMessage = 'No hubo cambios de precio que guardar.';

            return;
        }

        CatalogAudit::record(
            CatalogAudit::ACCION_PRECIOS,
            CatalogAudit::PRECIO,
            $item->cod_nomen_caja,
            $antes,
            $despues,
            ['servicio' => $item->descripcion_nomen_tipo, 'formas_afectadas' => count($despues)],
        );

        $this->savedMessage = count($despues).' precio(s) modificado(s). El antes y el después quedaron en la auditoría.';
    }

    public function confirmStatus(string $codNomenCaja): void
    {
        $this->statusTarget = $codNomenCaja;
        $this->statusMotivo = '';
        $this->resetErrorBag('statusMotivo');
    }

    public function cancelStatus(): void
    {
        $this->statusTarget = null;
        $this->statusMotivo = '';
    }

    /**
     * Retirar un servicio del catalogo NO lo borra: cambia estado_nomenclatura. Las
     * boletas ya emitidas siguen apuntando a el y su historia debe poder consultarse.
     */
    public function toggleStatus(): void
    {
        abort_unless($this->canManage, 403);

        $item = BillableItem::query()->findOrFail($this->statusTarget);

        $this->validate(
            ['statusMotivo' => ['required', 'string', 'min:5', 'max:500']],
            [
                'statusMotivo.required' => 'Indica el motivo del cambio de estado.',
                'statusMotivo.min' => 'Explica brevemente el motivo; queda en la auditoría.',
            ],
        );

        $estabaActivo = (bool) $item->estado_nomenclatura;

        $item->estado_nomenclatura = ! $estabaActivo;
        $this->stampLegacy($item);
        $item->save();

        CatalogAudit::record(
            $estabaActivo ? CatalogAudit::ACCION_DESACTIVADO : CatalogAudit::ACCION_ACTIVADO,
            CatalogAudit::ITEM,
            $item->cod_nomen_caja,
            ['Estado' => $estabaActivo ? 'Activo' : 'Inactivo'],
            ['Estado' => $estabaActivo ? 'Inactivo' : 'Activo'],
            ['servicio' => $item->descripcion_nomen_tipo, 'motivo' => $this->statusMotivo],
        );

        $this->statusTarget = null;
        $this->statusMotivo = '';
        unset($this->itemHistory);

        $this->savedMessage = $estabaActivo
            ? 'Servicio retirado del catálogo. Ya no se puede cobrar, pero su historial se conserva.'
            : 'Servicio reactivado: vuelve a estar disponible para cobro.';

        if ($this->editingItem === $item->cod_nomen_caja) {
            $this->form = $this->itemAttributes($item->refresh());
        }
    }

    public function create(): void
    {
        abort_unless($this->canManage, 403);

        $validated = $this->validate($this->itemRules('createForm'))['createForm'];

        $item = new BillableItem;
        $item->forceFill([
            'cod_nomen_caja' => LegacyIdGenerator::nextBillableItemCode(),
            'descripcion_nomen_tipo' => $validated['descripcion_nomen_tipo'],
            'nomen_caja' => $validated['nomen_caja'],
            'cod_grupo_nomen_aten' => $validated['cod_grupo_nomen_aten'],
            'grupo' => $this->grupoDeCategoria($validated['cod_grupo_nomen_aten']),
            'cod_nivel_servicio_3' => $validated['cod_nivel_servicio_3'],
            'id_cuenta7' => $validated['id_cuenta7'],
            'vis_admi' => $validated['vis_admi'],
            'vis_aten' => $validated['vis_aten'],
            // Constantes del legado: hoy todas las filas comparten estos dos valores.
            'tipo_nomen' => self::TIPO_NOMEN,
            'funcion_nomen' => self::FUNCION_NOMEN,
            'estado_nomenclatura' => true,
            'nom_usu' => mb_substr((string) Auth::user()->name, 0, 70),
            'fecha_actu' => now()->format('d/m/Y'),
            'hora_actu' => now()->format('H:i:s'),
        ]);
        $item->save();

        CatalogAudit::record(
            CatalogAudit::ACCION_ALTA,
            CatalogAudit::ITEM,
            $item->cod_nomen_caja,
            [],
            $this->itemLabels($this->itemAttributes($item)),
            ['servicio' => $item->descripcion_nomen_tipo, 'motivo' => $this->createForm['motivo'] ?: null],
        );

        $codigo = $item->cod_nomen_caja;

        $this->showCreate = false;
        $this->resetCreateForm();
        $this->resetPage();
        unset($this->priceCoverage);

        $this->edit($codigo);
        $this->savedMessage = "Servicio {$codigo} creado. Aún no tiene precios: cárgalos para poder cobrarlo.";
    }

    /** @return array<string, mixed> */
    private function itemAttributes(BillableItem $item): array
    {
        return [
            'descripcion_nomen_tipo' => trim((string) $item->descripcion_nomen_tipo),
            'nomen_caja' => trim((string) $item->nomen_caja),
            'cod_grupo_nomen_aten' => $item->cod_grupo_nomen_aten,
            'cod_nivel_servicio_3' => $item->cod_nivel_servicio_3,
            'id_cuenta7' => $item->id_cuenta7,
            'vis_admi' => trim((string) $item->vis_admi) ?: 'N',
            'vis_aten' => trim((string) $item->vis_aten) ?: 'N',
            'estado' => $item->estado_nomenclatura ? 'Activo' : 'Inactivo',
        ];
    }

    /**
     * Traduce los codigos a texto legible: el auditor no tiene por que saber que GN03
     * es Rayos X ni que CG00000030 es una cuenta contable.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function itemLabels(array $attributes): array
    {
        $categoria = $this->categories->firstWhere('cod_grupo_nomen_aten', $attributes['cod_grupo_nomen_aten']);
        $servicio = $this->servicios->firstWhere('cod_nivel_servicio_3', $attributes['cod_nivel_servicio_3']);
        $cuenta = $this->cuentas->firstWhere('Id_cuenta7', $attributes['id_cuenta7']);

        return [
            'Descripción' => $attributes['descripcion_nomen_tipo'],
            'Código corto' => $attributes['nomen_caja'],
            'Categoría' => trim((string) ($categoria->nombre_grupo_nomen ?? $attributes['cod_grupo_nomen_aten'])),
            'Servicio' => trim((string) ($servicio->nom_ser_3 ?? $attributes['cod_nivel_servicio_3'])),
            'Cuenta contable' => trim((string) ($cuenta->etiqueta ?? $attributes['id_cuenta7'])),
            'Visible en admisión' => $attributes['vis_admi'] === 'S' ? 'Sí' : 'No',
            'Visible en atención' => $attributes['vis_aten'] === 'S' ? 'Sí' : 'No',
            'Estado' => $attributes['estado'],
        ];
    }

    /** @return array<string, array<int, string>> */
    private function itemRules(string $prefix): array
    {
        return [
            "{$prefix}.descripcion_nomen_tipo" => ['required', 'string', 'min:3', 'max:1000'],
            "{$prefix}.nomen_caja" => ['required', 'string', 'max:12'],
            "{$prefix}.cod_grupo_nomen_aten" => ['required', 'string', 'max:4'],
            "{$prefix}.cod_nivel_servicio_3" => ['required', 'string', 'max:7'],
            "{$prefix}.id_cuenta7" => ['required', 'string', 'max:10'],
            "{$prefix}.vis_admi" => ['required', 'in:S,N'],
            "{$prefix}.vis_aten" => ['required', 'in:S,N'],
        ];
    }

    private function grupoDeCategoria(string $codGrupo): ?string
    {
        return $this->categories->firstWhere('cod_grupo_nomen_aten', $codGrupo)?->codigo_grupo;
    }

    /** Sella quien y cuando toco la fila, que es lo unico que el legado registra. */
    private function stampLegacy(BillableItem $item): void
    {
        $item->nom_usu = mb_substr((string) Auth::user()->name, 0, 70);
        $item->fecha_actu = now()->format('d/m/Y');
        $item->hora_actu = now()->format('H:i:s');
    }

    private function resetCreateForm(): void
    {
        $this->createForm = [
            'descripcion_nomen_tipo' => '',
            'nomen_caja' => '',
            'cod_grupo_nomen_aten' => 'GN01',
            'cod_nivel_servicio_3' => '',
            'id_cuenta7' => '',
            'vis_admi' => 'N',
            'vis_aten' => 'N',
            'motivo' => '',
        ];
    }
}; ?>

<section class="w-full space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">Catálogo de servicios y precios</flux:heading>
            <flux:text class="text-zinc-500">
                Mantenimiento del catálogo facturable (<code class="text-xs">Nomenclatura_caja_MH</code>) y de su precio en
                cada forma de pago (<code class="text-xs">Precio_MH</code>). Todo cambio queda auditado con su valor
                anterior y posterior.
            </flux:text>
        </div>

        <div class="flex items-center gap-2">
            @if ($this->canAudit)
                <flux:button href="{{ route('admin.catalog.audit') }}" wire:navigate variant="ghost" size="sm" icon="clipboard-document-list">
                    Auditoría de cambios
                </flux:button>
            @endif
            @if ($this->canManage)
                <flux:button wire:click="$set('showCreate', true)" variant="primary" size="sm" icon="plus">
                    Nuevo servicio
                </flux:button>
            @endif
        </div>
    </div>

    @if ($savedMessage)
        <div class="flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-500/30 dark:bg-emerald-400/10">
            <flux:icon.check-circle class="mt-0.5 size-5 shrink-0 text-emerald-600 dark:text-emerald-400" />
            <flux:text class="text-emerald-800 dark:text-emerald-300">{{ $savedMessage }}</flux:text>
        </div>
    @endif

    <div class="flex flex-wrap items-center gap-2">
        @foreach (['activos' => 'Activos', 'inactivos' => 'Retirados', 'todos' => 'Todos'] as $valor => $etiqueta)
            <button
                type="button"
                wire:click="setEstado('{{ $valor }}')"
                class="rounded-full border px-3 py-1.5 text-sm font-medium {{ $estadoFilter === $valor ? 'border-accent bg-accent/10 text-accent' : 'border-zinc-300 hover:border-accent dark:border-zinc-600' }}"
            >
                {{ $etiqueta }}
            </button>
        @endforeach

        <div class="mx-1 h-5 w-px bg-zinc-300 dark:bg-white/20"></div>

        <button
            type="button"
            wire:click="setCategory(null)"
            class="rounded-full border px-3 py-1.5 text-sm font-medium {{ $categoryFilter === null ? 'border-accent bg-accent/10 text-accent' : 'border-zinc-300 hover:border-accent dark:border-zinc-600' }}"
        >
            Todas las categorías
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

    <div class="overflow-hidden acrilico">
        <div class="divide-y dark:divide-white/10">
            @forelse ($this->items as $item)
                @php
                    $cobertura = $this->priceCoverage[$item->cod_nomen_caja] ?? null;
                    $activo = (bool) $item->estado_nomenclatura;
                @endphp

                <div class="{{ $activo ? '' : 'bg-zinc-50/60 dark:bg-white/[3%]' }}">
                    <button
                        type="button"
                        wire:click="edit('{{ $item->cod_nomen_caja }}')"
                        class="flex w-full items-center gap-4 px-4 py-3 text-left hover:bg-zinc-50 dark:hover:bg-white/5"
                    >
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <flux:text class="font-medium {{ $activo ? '' : 'text-zinc-500 line-through' }}">
                                    {{ $item->descripcion_nomen_tipo }}
                                </flux:text>
                                @unless ($activo)
                                    <flux:badge size="sm" color="zinc">Retirado</flux:badge>
                                @endunless
                            </div>
                            <div class="mt-0.5 text-xs text-zinc-500">
                                {{ $item->cod_nomen_caja }}
                                @if ($item->nomen_caja)
                                    · {{ trim($item->nomen_caja) }}
                                @endif
                                · actualizado por {{ trim((string) $item->nom_usu) }} el {{ $item->fecha_actu }}
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
                        <div class="space-y-6 border-t border-zinc-200 bg-zinc-50 px-4 py-4 dark:border-white/10 dark:bg-white/5">
                            {{-- Datos del servicio --}}
                            <div class="space-y-3">
                                <flux:subheading>Datos del servicio</flux:subheading>

                                <flux:input wire:model="form.descripcion_nomen_tipo" label="Descripción" :disabled="! $this->canManage" />
                                @error('form.descripcion_nomen_tipo') <flux:text class="text-sm text-red-600">{{ $message }}</flux:text> @enderror

                                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                    <flux:input wire:model="form.nomen_caja" label="Código corto" :disabled="! $this->canManage" />

                                    <flux:select wire:model="form.cod_grupo_nomen_aten" label="Categoría" :disabled="! $this->canManage">
                                        @foreach ($this->categories as $categoria)
                                            <flux:select.option value="{{ $categoria->cod_grupo_nomen_aten }}">
                                                {{ $categoria->nombre_grupo_nomen }}
                                            </flux:select.option>
                                        @endforeach
                                    </flux:select>

                                    <flux:select wire:model="form.cod_nivel_servicio_3" label="Servicio" :disabled="! $this->canManage">
                                        @foreach ($this->servicios as $servicio)
                                            <flux:select.option value="{{ $servicio->cod_nivel_servicio_3 }}">
                                                {{ $servicio->nom_ser_3 }}
                                            </flux:select.option>
                                        @endforeach
                                    </flux:select>

                                    <flux:select wire:model="form.id_cuenta7" label="Cuenta contable" :disabled="! $this->canManage" class="lg:col-span-2">
                                        @foreach ($this->cuentas as $cuenta)
                                            <flux:select.option value="{{ $cuenta->Id_cuenta7 }}">{{ $cuenta->etiqueta }}</flux:select.option>
                                        @endforeach
                                    </flux:select>

                                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                        <flux:select wire:model="form.vis_admi" label="Visible en admisión" :disabled="! $this->canManage">
                                            <flux:select.option value="S">Sí</flux:select.option>
                                            <flux:select.option value="N">No</flux:select.option>
                                        </flux:select>
                                        <flux:select wire:model="form.vis_aten" label="Visible en atención" :disabled="! $this->canManage">
                                            <flux:select.option value="S">Sí</flux:select.option>
                                            <flux:select.option value="N">No</flux:select.option>
                                        </flux:select>
                                    </div>
                                </div>

                                @if ($this->canManage)
                                    <div class="flex flex-wrap items-center gap-3">
                                        <flux:button wire:click="saveItem" variant="primary" size="sm" icon="check">Guardar datos</flux:button>

                                        <flux:button
                                            wire:click="confirmStatus('{{ $item->cod_nomen_caja }}')"
                                            size="sm"
                                            :variant="$activo ? 'danger' : 'filled'"
                                            :icon="$activo ? 'archive-box-x-mark' : 'arrow-path'"
                                        >
                                            {{ $activo ? 'Retirar del catálogo' : 'Reactivar' }}
                                        </flux:button>
                                    </div>
                                @endif

                                {{-- Confirmacion con motivo: el auditor necesita el porque, no solo el que. --}}
                                @if ($statusTarget === $item->cod_nomen_caja)
                                    <div class="space-y-3 rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-500/30 dark:bg-amber-400/10">
                                        <flux:text class="text-sm text-amber-900 dark:text-amber-200">
                                            @if ($activo)
                                                Al retirarlo dejará de poder cobrarse. No se borra: las boletas ya emitidas
                                                lo siguen referenciando y su historial se conserva.
                                            @else
                                                Volverá a estar disponible para cobro con los precios que tenga cargados.
                                            @endif
                                        </flux:text>

                                        <flux:input wire:model="statusMotivo" label="Motivo (queda en la auditoría)" placeholder="Ej: el tarifario 2026 retira este procedimiento" />
                                        @error('statusMotivo') <flux:text class="text-sm text-red-600">{{ $message }}</flux:text> @enderror

                                        <div class="flex items-center gap-2">
                                            <flux:button wire:click="toggleStatus" size="sm" :variant="$activo ? 'danger' : 'primary'">
                                                {{ $activo ? 'Confirmar retiro' : 'Confirmar reactivación' }}
                                            </flux:button>
                                            <flux:button wire:click="cancelStatus" size="sm" variant="ghost">Cancelar</flux:button>
                                        </div>
                                    </div>
                                @endif
                            </div>

                            {{-- Precios --}}
                            <div>
                                <flux:subheading class="mb-1">Precio por forma de pago</flux:subheading>
                                <flux:text class="mb-3 block text-xs text-zinc-500">
                                    Deja el campo vacío para que el servicio no se ofrezca con esa forma de pago.
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
                                                        :disabled="! $this->canManage"
                                                    />
                                                @endforeach
                                            </div>
                                        </div>
                                    @endforeach
                                </div>

                                @if ($this->canManage)
                                    <div class="mt-4 flex items-center gap-3">
                                        <flux:button wire:click="save" variant="primary" size="sm" icon="check">Guardar precios</flux:button>
                                        <flux:text class="text-xs text-zinc-500" wire:loading wire:target="save">Guardando...</flux:text>
                                    </div>
                                @endif
                            </div>

                            {{-- Ultimos movimientos, para no tener que salir a la auditoria --}}
                            @if ($this->canAudit && $this->itemHistory->isNotEmpty())
                                <div>
                                    <div class="mb-2 flex items-center justify-between">
                                        <flux:subheading>Últimos cambios</flux:subheading>
                                        <flux:link href="{{ route('admin.catalog.audit', ['item' => $item->cod_nomen_caja]) }}" wire:navigate class="text-xs">
                                            Ver historial completo
                                        </flux:link>
                                    </div>

                                    <div class="space-y-2">
                                        @foreach ($this->itemHistory as $evento)
                                            <x-catalog-audit-entry :event="$evento" />
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            @empty
                <div class="px-4 py-8 text-center text-sm text-zinc-500">No hay servicios que coincidan.</div>
            @endforelse
        </div>
    </div>

    <div>{{ $this->items->links() }}</div>

    {{-- Alta de servicio --}}
    <flux:modal wire:model.self="showCreate" class="md:w-[40rem]">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">Nuevo servicio del catálogo</flux:heading>
                <flux:text class="text-zinc-500">
                    Se crea activo y sin precios. Para poder cobrarlo hay que cargarle al menos un precio.
                </flux:text>
            </div>

            <flux:input wire:model="createForm.descripcion_nomen_tipo" label="Descripción" placeholder="Ej: CONSULTA ESTOMATOLOGICA ESPECIALIZADA" />
            @error('createForm.descripcion_nomen_tipo') <flux:text class="text-sm text-red-600">{{ $message }}</flux:text> @enderror

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <flux:input wire:model="createForm.nomen_caja" label="Código corto" placeholder="Ej: CJD0161" />

                <flux:select wire:model="createForm.cod_grupo_nomen_aten" label="Categoría">
                    @foreach ($this->categories as $categoria)
                        <flux:select.option value="{{ $categoria->cod_grupo_nomen_aten }}">{{ $categoria->nombre_grupo_nomen }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="createForm.cod_nivel_servicio_3" label="Servicio" placeholder="Selecciona el servicio...">
                    @foreach ($this->servicios as $servicio)
                        <flux:select.option value="{{ $servicio->cod_nivel_servicio_3 }}">{{ $servicio->nom_ser_3 }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="createForm.id_cuenta7" label="Cuenta contable" placeholder="Selecciona la cuenta...">
                    @foreach ($this->cuentas as $cuenta)
                        <flux:select.option value="{{ $cuenta->Id_cuenta7 }}">{{ $cuenta->etiqueta }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="createForm.vis_admi" label="Visible en admisión">
                    <flux:select.option value="S">Sí</flux:select.option>
                    <flux:select.option value="N">No</flux:select.option>
                </flux:select>

                <flux:select wire:model="createForm.vis_aten" label="Visible en atención">
                    <flux:select.option value="S">Sí</flux:select.option>
                    <flux:select.option value="N">No</flux:select.option>
                </flux:select>
            </div>

            @error('createForm.nomen_caja') <flux:text class="text-sm text-red-600">{{ $message }}</flux:text> @enderror
            @error('createForm.cod_nivel_servicio_3') <flux:text class="text-sm text-red-600">{{ $message }}</flux:text> @enderror
            @error('createForm.id_cuenta7') <flux:text class="text-sm text-red-600">{{ $message }}</flux:text> @enderror

            <flux:input wire:model="createForm.motivo" label="Motivo del alta (opcional)" placeholder="Ej: incorporado en el tarifario 2026" />

            <div class="flex justify-end gap-2">
                <flux:button wire:click="$set('showCreate', false)" variant="ghost">Cancelar</flux:button>
                <flux:button wire:click="create" variant="primary" icon="plus">Crear servicio</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
