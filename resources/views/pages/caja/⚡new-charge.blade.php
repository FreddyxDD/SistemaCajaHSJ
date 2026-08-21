<?php

use App\Models\Caja\BillableItem;
use App\Models\Caja\CashSession;
use App\Models\Caja\ChargeDocument;
use App\Models\Caja\ChargeDocumentItem;
use App\Models\Caja\DocumentType;
use App\Models\Caja\ItemCategory;
use App\Models\Caja\LegacyHistoriaClinica;
use App\Models\Caja\PaymentMethod;
use App\Models\Caja\Price;
use App\Models\Sigh\Atencion;
use App\Models\Sigh\Patient;
use App\Support\Caja\HistoriaClinicaProvisioner;
use App\Support\Caja\LegacyIdGenerator;
use App\Support\Caja\RequestSheets;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Nuevo cobro')] class extends Component {
    public string $patientQuery = '';

    public ?string $patientId = null;

    public ?string $patientLabel = null;

    public ?string $patientDocLabel = null;

    public ?string $patientMeta = null;

    public ?string $patientHc = null;

    public ?string $patientHcNumber = null;

    /** Historia_clinica.IdPaciente: el cruce hacia SIGH para leer sus atenciones. */
    public ?int $patientSighId = null;

    /** true cuando la HC se acaba de registrar en Caja a partir de SIGH. */
    public bool $patientJustRegistered = false;

    /** @var array<int, string> Codigos elegidos al recorrer el arbol de formas de pago. */
    public array $paymentPath = [];

    public ?string $paymentMethodCode = null;

    public string $itemQuery = '';

    public ?string $categoryFilter = null;

    /** 'buscar' = buscador libre; 'hoja' = formato de solicitud tipo el papel de Admision. */
    public string $catalogMode = 'buscar';

    public string $sheetKey = 'rayos-x';

    public string $sheetFilter = '';

    /** Resumen del re-tarifado tras cambiar de forma de pago: retirados y cambios de precio. */
    public ?array $repriceNotice = null;

    /** @var array<int, array{cod_precio: string, cod_nomen_caja: string, descripcion: string, cantidad: float, precio: float}> */
    public array $cart = [];

    public function mount(): void
    {
        abort_unless($this->currentSession, 403, 'Debes abrir un turno de caja antes de registrar cobros.');
    }

    #[Computed]
    public function currentSession(): ?CashSession
    {
        return CashSession::query()
            ->open()
            ->where('cod_usu', LegacyIdGenerator::legacyUserCode(Auth::user()))
            ->first();
    }

    /**
     * Busqueda en dos fuentes.
     *
     * Historia_clinica (SISGESH_BD) es la tabla a la que apunta la FK del comprobante,
     * pero NO es un espejo completo del maestro de pacientes: tiene ~85 mil filas
     * frente a las ~389 mil de SIGH.Pacientes. Un paciente que Admision registro en
     * SIGH y que nunca paso por caja no aparecia aqui y no se podia cobrar.
     *
     * Por eso se consultan las dos: primero las HC que ya existen en Caja y luego los
     * pacientes de SIGH que aun no estan registrados aqui (marcados aparte en la
     * lista). Al elegir uno de estos ultimos se crea su HC en Caja reusando el numero
     * que SIGH ya le asigno.
     */
    #[Computed]
    public function patientResults()
    {
        if (mb_strlen(trim($this->patientQuery)) < 2) {
            return collect();
        }

        $historias = LegacyHistoriaClinica::query()
            ->search($this->patientQuery)
            ->orderBy('ape_pat')
            ->orderBy('ape_mat')
            ->limit(8)
            ->get();

        $enCaja = $historias->map(fn ($historia) => [
            'source' => 'caja',
            'key' => $historia->id_hc,
            'nombre' => $historia->full_name,
            'hc' => $historia->historia_number,
            'documento' => trim((string) $historia->dni),
            'edad' => $historia->age,
            'sexo' => $historia->sex_label,
        ]);

        $yaRegistrados = $historias->pluck('IdPaciente')->filter()->values()->all();

        try {
            $enSigh = Patient::query()
                ->search($this->patientQuery)
                ->when($yaRegistrados, fn ($query) => $query->whereNotIn('IdPaciente', $yaRegistrados))
                ->orderBy('ApellidoPaterno')
                ->orderBy('ApellidoMaterno')
                ->limit(8)
                ->get()
                ->map(fn ($paciente) => [
                    'source' => 'sigh',
                    'key' => (string) $paciente->IdPaciente,
                    'nombre' => $paciente->full_name,
                    'hc' => (string) $paciente->NroHistoriaClinica,
                    'documento' => trim((string) $paciente->NroDocumento),
                    'edad' => $paciente->age,
                    'sexo' => $paciente->sex_label,
                ]);
        } catch (\Throwable $e) {
            // Si SIGH no responde, la caja sigue trabajando con lo que ya tiene.
            report($e);
            $enSigh = collect();
        }

        // Un mismo paciente puede venir por las dos fuentes con distinto IdPaciente
        // nulo; el numero de HC es el desempate visible para el cajero.
        $hcEnCaja = $enCaja->pluck('hc')->filter()->all();

        return $enCaja->concat(
            $enSigh->reject(fn ($p) => in_array($p['hc'], $hcEnCaja, true))
        )->values();
    }

    /**
     * Nodos de forma de pago disponibles en el nivel actual del recorrido: raiz si
     * paymentPath esta vacio, o hijos del ultimo codigo elegido.
     */
    #[Computed]
    public function paymentOptions()
    {
        $parent = end($this->paymentPath) ?: '0';

        return PaymentMethod::query()
            ->where('relacion_forma_pago', $parent)
            ->orderBy('nom_forma_pago')
            ->get();
    }

    #[Computed]
    public function paymentBreadcrumb()
    {
        if (empty($this->paymentPath)) {
            return collect();
        }

        return PaymentMethod::query()
            ->whereIn('cod_jerar_forma_pago', $this->paymentPath)
            ->get()
            ->sortBy(fn ($m) => array_search($m->cod_jerar_forma_pago, $this->paymentPath))
            ->values();
    }

    /**
     * Categorias reales del catalogo (GRUPO_NOMENCLATURA_ATENCION_MH), solo las que
     * tienen items cargados.
     */
    #[Computed]
    public function categories()
    {
        $gruposConItems = BillableItem::query()->whereNotNull('grupo')->distinct()->pluck('grupo');

        return ItemCategory::query()
            ->whereIn('codigo_grupo', $gruposConItems)
            ->orderByRaw("CASE codigo_grupo WHEN 'CJ' THEN 0 ELSE 1 END")
            ->orderBy('nombre_grupo_nomen')
            ->get();
    }

    #[Computed]
    public function itemResults()
    {
        if (! $this->paymentMethodCode) {
            return collect();
        }

        $query = Price::query()
            ->where('cod_jerar_forma_pago', $this->paymentMethodCode)
            ->with('billableItem');

        if (mb_strlen(trim($this->itemQuery)) >= 2) {
            $query->whereHas('billableItem', fn ($q) => $q->search($this->itemQuery));
        } else {
            // Sin busqueda: muestra un listado navegable por defecto en vez de dejar
            // el catalogo vacio hasta que el cajero escriba algo.
            $query->whereHas('billableItem', fn ($q) => $q->where('estado_nomenclatura', true)
                ->orWhereNull('estado_nomenclatura'));
        }

        if ($this->categoryFilter) {
            $query->whereHas('billableItem', fn ($q) => $q->where('grupo', $this->categoryFilter));
        }

        // "Mas solicitados": frecuencia real segun el historial de ventas (Detalle +
        // Cabecera vigentes), no una lista inventada. Se muestra primero lo mas vendido.
        $frequency = DB::connection('caja')->table('Detalle_documento_MH as d')
            ->join('Cabecera_documento_MH as c', 'c.id_documento', '=', 'd.id_documento')
            ->join('Precio_MH as pf', 'pf.cod_precio', '=', 'd.cod_precio')
            ->where('c.estado_doc', ChargeDocument::ESTADO_EMITIDO)
            ->selectRaw('pf.cod_nomen_caja as cod_nomen_caja, COUNT(*) as veces')
            ->groupBy('pf.cod_nomen_caja');

        return $query
            ->join('Nomenclatura_caja_MH as nc', 'nc.cod_nomen_caja', '=', 'Precio_MH.cod_nomen_caja')
            ->leftJoinSub($frequency, 'freq', 'freq.cod_nomen_caja', '=', 'Precio_MH.cod_nomen_caja')
            ->orderByDesc('freq.veces')
            ->orderBy('nc.descripcion_nomen_tipo')
            ->select('Precio_MH.*')
            ->limit(30)
            ->get();
    }

    #[Computed]
    public function sheets(): array
    {
        return RequestSheets::all();
    }

    /**
     * Arma la hoja de solicitud: cruza los codigos del formato con el catalogo y el
     * precio de la forma de pago elegida. Un codigo del papel que no exista en el
     * catalogo se muestra igual, marcado como no disponible, en vez de desaparecer
     * silenciosamente (el cajero tiene el papel en la mano y debe poder ubicarlo).
     */
    #[Computed]
    public function sheet(): array
    {
        $meta = RequestSheets::find($this->sheetKey);

        if (! $meta || ! $this->paymentMethodCode) {
            return ['meta' => $meta, 'sections' => []];
        }

        $catalog = Price::query()
            ->where('Precio_MH.cod_jerar_forma_pago', $this->paymentMethodCode)
            ->join('Nomenclatura_caja_MH as nc', 'nc.cod_nomen_caja', '=', 'Precio_MH.cod_nomen_caja')
            ->where('nc.grupo', $meta['grupo'])
            ->when($meta['like'] ?? null, fn ($q, $like) => $q->where('nc.descripcion_nomen_tipo', 'like', $like))
            ->select('Precio_MH.cod_precio', 'Precio_MH.precio', 'nc.nomen_caja', 'nc.descripcion_nomen_tipo')
            ->get()
            ->keyBy(fn ($row) => trim((string) $row->nomen_caja));

        $filter = mb_strtolower(trim($this->sheetFilter));
        $matches = fn (string $code, string $desc) => $filter === ''
            || str_contains(mb_strtolower($desc), $filter)
            || str_contains(mb_strtolower($code), $filter);

        $sections = [];

        if (! empty($meta['sections'])) {
            // Rayos X: se respeta el orden y agrupacion del formato impreso.
            foreach ($meta['sections'] as $sectionName => $codes) {
                $rows = [];

                foreach ($codes as $code => $paper) {
                    $item = $catalog->get($code);
                    $desc = $item->descripcion_nomen_tipo ?? 'No disponible en el catálogo para esta forma de pago';

                    if (! $matches($code, $desc)) {
                        continue;
                    }

                    $rows[] = [
                        'codigo' => $code,
                        'descripcion' => $desc,
                        'cod_precio' => $item->cod_precio ?? null,
                        'precio' => $item?->precio,
                        'placas' => $paper['placas'],
                        'medidas' => $paper['medidas'],
                    ];
                }

                // Una seccion sin coincidencias no se muestra al filtrar.
                if ($rows) {
                    $sections[$sectionName] = $rows;
                }
            }

            return ['meta' => $meta, 'sections' => $sections];
        }

        // Resto de hojas: se agrupan por prefijo de codigo del catalogo.
        $labels = $meta['prefix_sections'] ?? [];

        foreach ($catalog->sortBy(fn ($row) => trim((string) $row->nomen_caja)) as $code => $item) {
            if (! $matches((string) $code, (string) $item->descripcion_nomen_tipo)) {
                continue;
            }

            $prefix = substr($code, 0, 2);
            $sectionName = $labels[$prefix] ?? ($meta['label']);

            $sections[$sectionName][] = [
                'codigo' => $code,
                'descripcion' => $item->descripcion_nomen_tipo,
                'cod_precio' => $item->cod_precio,
                'precio' => $item->precio,
                'placas' => null,
                'medidas' => null,
            ];
        }

        return ['meta' => $meta, 'sections' => $sections];
    }

    #[Computed]
    public function selectedPrices(): array
    {
        return collect($this->cart)->pluck('cod_precio')->all();
    }

    #[Computed]
    public function subtotal(): float
    {
        return collect($this->cart)->sum(fn ($line) => $line['precio'] * $line['cantidad']);
    }

    #[Computed]
    public function cartCount(): int
    {
        return (int) collect($this->cart)->sum('cantidad');
    }

    /**
     * @param  string  $source  'caja' = ya tiene HC aqui; 'sigh' = hay que registrarla.
     */
    public function selectPatient(string $key, string $source = 'caja'): void
    {
        if ($source === 'sigh') {
            $paciente = Patient::query()->findOrFail($key);

            try {
                $historia = HistoriaClinicaProvisioner::ensureFromSigh($paciente);
            } catch (\Throwable $e) {
                report($e);
                $this->addError('patientId', $e->getMessage());

                return;
            }

            $this->patientJustRegistered = true;
        } else {
            $historia = LegacyHistoriaClinica::query()->findOrFail($key);
            $this->patientJustRegistered = false;
        }

        $this->resetErrorBag('patientId');
        $this->patientId = $historia->id_hc;
        $this->patientLabel = $historia->full_name;
        $this->patientDocLabel = trim((string) $historia->dni) !== '' ? 'DNI '.trim($historia->dni) : 'Sin documento';
        $this->patientMeta = collect([
            $historia->age !== null ? $historia->age.' años' : null,
            $historia->sex_label,
        ])->filter()->implode(' · ');
        $this->patientHc = $historia->id_hc;
        $this->patientHcNumber = $historia->historia_number;
        $this->patientSighId = $historia->IdPaciente ? (int) $historia->IdPaciente : null;
        $this->patientQuery = '';
        unset($this->patientResults, $this->patientAtenciones);
    }

    public function clearPatient(): void
    {
        $this->patientId = null;
        $this->patientLabel = null;
        $this->patientDocLabel = null;
        $this->patientMeta = null;
        $this->patientHc = null;
        $this->patientHcNumber = null;
        $this->patientSighId = null;
        $this->patientJustRegistered = false;
        $this->patientQuery = '';
        unset($this->patientAtenciones);
    }

    /**
     * Citas/atenciones que Admision ya registro en SIGH para este paciente: es lo que
     * el paciente trae al mostrador. Si SIGH no responde no se bloquea el cobro,
     * simplemente no se muestra la tarjeta.
     */
    #[Computed]
    public function patientAtenciones()
    {
        if (! $this->patientSighId) {
            return collect();
        }

        try {
            return Atencion::query()
                ->forPatientWithDetails($this->patientSighId)
                ->limit(5)
                ->get();
        } catch (\Throwable $e) {
            report($e);

            return collect();
        }
    }

    public function choosePaymentOption(string $code): void
    {
        $hasChildren = PaymentMethod::query()->where('relacion_forma_pago', $code)->exists();

        if ($hasChildren) {
            $this->paymentPath[] = $code;

            return;
        }

        $this->setFinalPaymentMethod($code);
    }

    public function backPaymentPath(): void
    {
        array_pop($this->paymentPath);
        $this->paymentMethodCode = null;
        $this->itemQuery = '';
    }

    /**
     * Vuelve al arbol de formas de pago SIN vaciar la venta: los servicios se
     * conservan y se re-tarifan cuando se elija la nueva forma de pago.
     */
    public function changePaymentMethod(): void
    {
        $this->paymentPath = [];
        $this->paymentMethodCode = null;
        $this->itemQuery = '';
        $this->repriceNotice = null;
    }

    private function setFinalPaymentMethod(string $code): void
    {
        $previous = $this->paymentMethodCode;

        $this->paymentPath[] = $code;
        $this->paymentMethodCode = $code;
        $this->resetErrorBag('paymentMethodCode');

        if ($previous !== $code && ! empty($this->cart)) {
            $this->repriceCart();
        }
    }

    /**
     * Al cambiar de forma de pago los precios cambian (Precio_MH es por item + forma
     * de pago). Se re-tarifa lo que exista en la nueva forma de pago y se retira lo
     * que no, informando exactamente que paso en vez de vaciar la venta en silencio.
     */
    private function repriceCart(): void
    {
        $prices = Price::query()
            ->where('cod_jerar_forma_pago', $this->paymentMethodCode)
            ->whereIn('cod_nomen_caja', collect($this->cart)->pluck('cod_nomen_caja')->all())
            ->get()
            ->keyBy('cod_nomen_caja');

        $kept = [];
        $removed = [];
        $changed = [];

        foreach ($this->cart as $line) {
            $new = $prices->get($line['cod_nomen_caja']);

            if (! $new) {
                $removed[] = $line['descripcion'];

                continue;
            }

            $newPrice = (float) $new->precio;

            if (abs($newPrice - $line['precio']) > 0.0001) {
                $changed[] = [
                    'descripcion' => $line['descripcion'],
                    'antes' => $line['precio'],
                    'ahora' => $newPrice,
                ];
            }

            $line['cod_precio'] = $new->cod_precio;
            $line['precio'] = $newPrice;
            $kept[] = $line;
        }

        $this->cart = array_values($kept);

        $this->repriceNotice = ($removed || $changed)
            ? ['removed' => $removed, 'changed' => $changed]
            : null;
    }

    public function dismissRepriceNotice(): void
    {
        $this->repriceNotice = null;
    }

    public function setCategoryFilter(?string $code): void
    {
        $this->categoryFilter = $code;
    }

    /**
     * Un click en el catalogo agrega el servicio a la venta; un click de nuevo sobre
     * el mismo lo quita (para sumar cantidad de un mismo servicio se usa el +/- del
     * resumen de venta, no clicks repetidos aqui).
     */
    public function toggleItem(string $codPrecio): void
    {
        foreach ($this->cart as $index => $line) {
            if ($line['cod_precio'] === $codPrecio) {
                $this->removeItem($index);

                return;
            }
        }

        $this->addItem($codPrecio);
    }

    public function addItem(string $codPrecio): void
    {
        $price = Price::query()->with('billableItem')->findOrFail($codPrecio);

        foreach ($this->cart as $index => $line) {
            if ($line['cod_precio'] === $codPrecio) {
                $this->cart[$index]['cantidad']++;

                return;
            }
        }

        $this->cart[] = [
            'cod_precio' => $price->cod_precio,
            'cod_nomen_caja' => $price->cod_nomen_caja,
            'descripcion' => $price->billableItem?->descripcion_nomen_tipo ?? $price->cod_nomen_caja,
            'cantidad' => 1,
            'precio' => (float) $price->precio,
        ];
    }

    public function updateQuantity(int $index, int $cantidad): void
    {
        if (! isset($this->cart[$index])) {
            return;
        }

        if ($cantidad < 1) {
            $this->removeItem($index);

            return;
        }

        $this->cart[$index]['cantidad'] = $cantidad;
    }

    public function removeItem(int $index): void
    {
        unset($this->cart[$index]);
        $this->cart = array_values($this->cart);
    }

    /**
     * Paso previo al cobro: valida y abre el modal de confirmacion. Se valida ANTES
     * de mostrarlo para que el cajero no confirme una venta que va a fallar.
     */
    public function openConfirm(): void
    {
        abort_unless(Auth::user()->hasPermission('caja.charge.create') || Auth::user()->hasRole('administrador'), 403);

        $this->validate([
            'patientId' => ['required'],
            'paymentMethodCode' => ['required'],
        ], [
            'patientId.required' => 'Busca y selecciona un paciente.',
            'paymentMethodCode.required' => 'Selecciona una forma de pago.',
        ]);

        if (empty($this->cart)) {
            $this->addError('cart', 'Agrega al menos un servicio al carrito.');

            return;
        }

        if (! $this->currentSession) {
            $this->addError('cart', 'No tienes un turno de caja abierto.');

            return;
        }

        $this->modal('confirmar-cobro')->show();
    }

    public function submit(): void
    {
        abort_unless(Auth::user()->hasPermission('caja.charge.create') || Auth::user()->hasRole('administrador'), 403);

        $session = $this->currentSession;

        $this->validate([
            'patientId' => ['required'],
            'paymentMethodCode' => ['required'],
        ], [
            'patientId.required' => 'Busca y selecciona un paciente.',
            'paymentMethodCode.required' => 'Selecciona una forma de pago.',
        ]);

        if (empty($this->cart)) {
            $this->addError('cart', 'Agrega al menos un servicio al carrito.');

            return;
        }

        abort_unless($session, 403, 'No tienes un turno de caja abierto.');

        $documentType = DocumentType::query()->find('TD01');
        $serie = '999';

        $document = DB::connection('caja')->transaction(function () use ($session, $documentType, $serie) {
            $subtotal = $this->subtotal;

            $document = ChargeDocument::query()->create([
                'id_documento' => LegacyIdGenerator::nextDocumentId(),
                'serie_documento' => $serie,
                'num_documento' => LegacyIdGenerator::nextDocumentNumber($serie),
                'cod_tipo_documento' => $documentType?->cod_tipo_documento ?? 'TD01',
                'cliente' => mb_substr($this->patientLabel ?? 'PACIENTE', 0, 40),
                'cod_usu' => LegacyIdGenerator::legacyUserCode(Auth::user()),
                'Dependencia' => null,
                'Descuento' => 0,
                'sub_total_doc' => $subtotal,
                'igv_doc' => 0,
                'total_doc' => $subtotal,
                'cod_jerar_forma_pago' => $this->paymentMethodCode,
                'id_hc' => $this->patientHc ?? '0',
                'fecha_actu' => now()->format('d/m/Y'),
                'hora_actu' => now()->format('H:i:s'),
                'estado_doc' => ChargeDocument::ESTADO_EMITIDO,
                'estado_pago' => 'S',
                'cod_motiv_anu' => 'MA001',
                'nom_pc' => 'GESTIONCAJAHSJ',
                'cod_aper_cierre_caja' => $session->cod_aper_cierre_caja,
                'TIPO_DOC' => 'GESTIONCAJAHSJ',
            ]);

            foreach ($this->cart as $line) {
                ChargeDocumentItem::query()->create([
                    'id_cod_det' => LegacyIdGenerator::nextDocumentItemId(),
                    'id_documento' => $document->id_documento,
                    'cod_precio' => $line['cod_precio'],
                    'nom_consultorio_citas' => '',
                    'cantidad_detalle' => $line['cantidad'],
                    'precio_detalle' => $line['precio'],
                    'total_detalle' => $line['precio'] * $line['cantidad'],
                    'fecha_aten' => now()->format('d/m/Y'),
                    'cod_usu' => LegacyIdGenerator::legacyUserCode(Auth::user()),
                    'fecha_detalle' => now()->format('d/m/Y'),
                    'hora_detalle' => now()->format('H:i:s'),
                    'paquete_nomen' => 'N',
                    'nom_pc' => 'GESTIONCAJAHSJ',
                ]);
            }

            return $document;
        });

        // print=1 hace que el detalle abra el dialogo de impresion apenas carga, para
        // que el cajero entregue la boleta sin un clic extra. Es una redireccion
        // completa (no wire:navigate) para que el navegador tenga la pagina lista.
        $this->redirect(route('caja.charges.show', ['documentId' => $document->id_documento, 'print' => 1]));
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex items-center justify-between">
        <flux:heading size="xl">Nuevo cobro</flux:heading>
        <flux:badge color="green">Turno {{ $this->currentSession?->cod_aper_cierre_caja }}</flux:badge>
    </div>

    @if (session('status'))
        <flux:callout variant="warning" heading="{{ session('status') }}" class="mb-4" />
    @endif

    @error('cart')
        <flux:callout variant="danger" heading="{{ $message }}" class="mb-4" />
    @enderror

    @if ($repriceNotice)
        <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-500/30 dark:bg-amber-400/10">
            <div class="flex items-start justify-between gap-4">
                <div class="flex items-start gap-3">
                    <flux:icon.exclamation-triangle class="mt-0.5 size-5 shrink-0 text-amber-600 dark:text-amber-400" />
                    <div class="space-y-1.5">
                        <flux:text class="font-medium text-amber-900 dark:text-amber-300">
                            Se actualizó la venta por el cambio de forma de pago
                        </flux:text>

                        @if ($repriceNotice['removed'])
                            <div>
                                <flux:text class="text-sm font-medium text-amber-900 dark:text-amber-300">
                                    Retirados por no tener precio en esta forma de pago:
                                </flux:text>
                                <ul class="ms-4 list-disc text-sm text-amber-800 dark:text-amber-400/80">
                                    @foreach ($repriceNotice['removed'] as $desc)
                                        <li>{{ $desc }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @if ($repriceNotice['changed'])
                            <div>
                                <flux:text class="text-sm font-medium text-amber-900 dark:text-amber-300">Precios actualizados:</flux:text>
                                <ul class="ms-4 list-disc text-sm text-amber-800 dark:text-amber-400/80">
                                    @foreach ($repriceNotice['changed'] as $c)
                                        <li>
                                            {{ \Illuminate\Support\Str::limit($c['descripcion'], 70) }}:
                                            S/ {{ number_format($c['antes'], 2) }} → <strong>S/ {{ number_format($c['ahora'], 2) }}</strong>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    </div>
                </div>
                <flux:button size="sm" variant="ghost" wire:click="dismissRepriceNotice">Entendido</flux:button>
            </div>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            {{-- 1 y 2 en una sola fila: paciente y forma de pago --}}
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <flux:card class="space-y-3">
                <flux:subheading>1. Paciente</flux:subheading>

                @if ($patientId)
                    <div class="flex items-center justify-between rounded-lg bg-indigo-50 p-3 dark:bg-indigo-400/10">
                        <div>
                            <flux:text class="text-base font-medium">{{ $patientLabel }}</flux:text>
                            <flux:text class="text-sm text-zinc-500">
                                {{ $patientDocLabel }} · HC {{ $patientHcNumber }}
                                @if ($patientMeta)
                                    · {{ $patientMeta }}
                                @endif
                            </flux:text>
                        </div>
                        <flux:button size="sm" variant="ghost" wire:click="clearPatient">Cambiar</flux:button>
                    </div>

                    @if ($patientJustRegistered)
                        <div class="flex items-start gap-2 rounded-lg border border-sky-200 bg-sky-50 p-2 dark:border-sky-500/30 dark:bg-sky-400/10">
                            <flux:icon.information-circle class="mt-0.5 size-4 shrink-0 text-sky-600 dark:text-sky-400" />
                            <flux:text class="text-xs text-sky-800 dark:text-sky-300">
                                Este paciente existía en SIGH pero no en Caja. Se registró su historia clínica
                                {{ $patientHcNumber }} para poder emitir el comprobante.
                            </flux:text>
                        </div>
                    @endif

                    {{-- Citas/atenciones que Admision registro en SIGH: es el papel que
                         el paciente trae al mostrador, asi el cajero sabe que va a cobrar. --}}
                    @if ($this->patientAtenciones->isNotEmpty())
                        <div class="rounded-lg border border-zinc-200 dark:border-white/10">
                            <div class="flex items-center gap-2 border-b border-zinc-200 px-3 py-2 dark:border-white/10">
                                <flux:icon.calendar-days class="size-4 text-zinc-400" />
                                <flux:text class="text-xs font-medium">Atenciones registradas en Admisión (SIGH)</flux:text>
                            </div>

                            <div class="divide-y dark:divide-white/10">
                                @foreach ($this->patientAtenciones as $atencion)
                                    <div class="px-3 py-2">
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <flux:text class="text-sm font-medium">
                                                {{ $atencion->servicio ?: 'Servicio no indicado' }}
                                            </flux:text>
                                            <flux:badge size="sm" color="zinc">{{ $atencion->estado ?: 'Sin estado' }}</flux:badge>
                                        </div>
                                        <div class="mt-0.5 text-xs text-zinc-500">
                                            {{ optional($atencion->FechaIngreso)->format('d/m/Y') }}
                                            @if (trim((string) $atencion->HoraIngreso) !== '')
                                                · {{ trim($atencion->HoraIngreso) }}
                                            @endif
                                            @if ($atencion->tipo_servicio)
                                                · {{ $atencion->tipo_servicio }}
                                            @endif
                                        </div>
                                        @if ($atencion->medico || $atencion->especialidad)
                                            <div class="text-xs text-zinc-500">
                                                {{ collect([$atencion->medico, $atencion->especialidad])->filter()->implode(' · ') }}
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @elseif ($patientSighId)
                        <flux:text class="text-xs text-zinc-500">
                            Este paciente no tiene atenciones registradas en SIGH.
                        </flux:text>
                    @endif
                @else
                    <flux:input
                        wire:model.live.debounce.400ms="patientQuery"
                        placeholder="Nombres, apellidos, N° de documento o historia clínica..."
                        icon="magnifying-glass"
                        autofocus
                    />
                    <flux:text class="text-xs text-zinc-500">
                        Escribe cualquier combinación: apellido y nombre en cualquier orden, DNI, carnet de extranjería u otro documento, o el número de historia clínica.
                    </flux:text>
                    @error('patientId') <flux:text class="text-sm text-red-600">{{ $message }}</flux:text> @enderror

                    <div wire:loading wire:target="patientQuery" class="text-sm text-zinc-500">Buscando...</div>

                    <div wire:loading.remove wire:target="patientQuery">
                        @if (mb_strlen(trim($patientQuery)) >= 2 && $this->patientResults->isEmpty())
                            <flux:text class="text-sm text-zinc-500">
                                No se encontraron pacientes con "{{ $patientQuery }}". Verifica el nombre, documento o historia clínica.
                            </flux:text>
                        @elseif ($this->patientResults->isNotEmpty())
                            <div class="divide-y rounded-lg border dark:border-zinc-700">
                                @foreach ($this->patientResults as $result)
                                    <button
                                        type="button"
                                        wire:click="selectPatient('{{ $result['key'] }}', '{{ $result['source'] }}')"
                                        class="w-full px-3 py-2 text-left hover:bg-zinc-100 dark:hover:bg-zinc-800"
                                    >
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="font-medium">{{ $result['nombre'] }}</span>
                                            @if ($result['source'] === 'sigh')
                                                {{-- Existe en SIGH pero todavia no en Caja: al elegirlo se registra su HC. --}}
                                                <flux:badge size="sm" color="sky">Solo en SIGH</flux:badge>
                                            @endif
                                        </div>
                                        <div class="text-sm text-zinc-500">
                                            HC {{ $result['hc'] }}
                                            @if ($result['documento'] !== '')
                                                · DNI {{ $result['documento'] }}
                                            @endif
                                            @if ($result['edad'] !== null)
                                                · {{ $result['edad'] }} años
                                            @endif
                                            @if ($result['sexo'])
                                                · {{ $result['sexo'] }}
                                            @endif
                                        </div>
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif
            </flux:card>

            {{-- 2. Forma de pago --}}
            <flux:card class="space-y-3">
                <flux:subheading>2. Forma de pago</flux:subheading>

                @if ($paymentMethodCode)
                    <div class="flex items-center justify-between rounded-lg bg-indigo-50 p-3 dark:bg-indigo-400/10">
                        <flux:text class="font-medium">
                            {{ $this->paymentBreadcrumb->pluck('nom_forma_pago')->implode(' › ') }}
                        </flux:text>
                        <flux:button size="sm" variant="ghost" wire:click="changePaymentMethod">Cambiar</flux:button>
                    </div>
                @else
                    @if ($this->paymentBreadcrumb->isNotEmpty())
                        <div class="flex items-center gap-2 text-sm text-zinc-500">
                            <flux:button size="sm" variant="ghost" wire:click="backPaymentPath" icon="arrow-left">Atrás</flux:button>
                            <span>{{ $this->paymentBreadcrumb->pluck('nom_forma_pago')->implode(' › ') }}</span>
                        </div>
                    @endif

                    @if (! empty($cart))
                        <flux:text class="text-xs text-amber-600 dark:text-amber-400">
                            Tienes {{ $this->cartCount }} {{ Str::plural('servicio', $this->cartCount) }} en la venta: al elegir otra forma de pago se re-tarifan, y los que no tengan precio ahí se retirarán.
                        </flux:text>
                    @endif

                    <div class="flex flex-wrap gap-2">
                        @foreach ($this->paymentOptions as $option)
                            <button
                                type="button"
                                wire:click="choosePaymentOption('{{ $option->cod_jerar_forma_pago }}')"
                                class="rounded-full border border-zinc-300 px-3 py-1.5 text-sm font-medium hover:border-indigo-500 hover:bg-indigo-50 dark:border-zinc-600 dark:hover:bg-indigo-400/10"
                            >
                                {{ $option->nom_forma_pago }}
                            </button>
                        @endforeach
                    </div>
                @endif
                @error('paymentMethodCode') <flux:text class="text-sm text-red-600">{{ $message }}</flux:text> @enderror
            </flux:card>
            </div>

            {{-- 3. Catalogo --}}
            <flux:card class="space-y-3">
                <flux:subheading>3. Catálogo de servicios</flux:subheading>

                @if (! $paymentMethodCode)
                    <flux:text class="text-zinc-500">Selecciona primero una forma de pago para ver el catálogo con el precio correcto.</flux:text>
                @else
                    {{-- Modo: buscador libre vs hoja de solicitud (formato de Admisión) --}}
                    <div class="inline-flex rounded-lg border border-zinc-300 p-0.5 dark:border-zinc-600">
                        <button type="button" wire:click="$set('catalogMode', 'buscar')"
                            class="rounded-md px-3 py-1.5 text-sm font-medium {{ $catalogMode === 'buscar' ? 'bg-indigo-500 text-white' : 'text-zinc-600 dark:text-zinc-300' }}">
                            Búsqueda rápida
                        </button>
                        <button type="button" wire:click="$set('catalogMode', 'hoja')"
                            class="rounded-md px-3 py-1.5 text-sm font-medium {{ $catalogMode === 'hoja' ? 'bg-indigo-500 text-white' : 'text-zinc-600 dark:text-zinc-300' }}">
                            Hoja de solicitud
                        </button>
                    </div>

                    @if ($catalogMode === 'hoja')
                        <div class="flex flex-wrap gap-2">
                            @foreach ($this->sheets as $key => $s)
                                <button type="button" wire:click="$set('sheetKey', '{{ $key }}')"
                                    class="rounded-full border px-3 py-1.5 text-sm font-medium {{ $sheetKey === $key ? 'border-indigo-500 bg-indigo-50 text-indigo-700 dark:bg-indigo-400/10 dark:text-indigo-400' : 'border-zinc-300 hover:border-indigo-500 dark:border-zinc-600' }}">
                                    {{ $s['label'] }}
                                </button>
                            @endforeach
                        </div>

                        <flux:input
                            wire:model.live.debounce.300ms="sheetFilter"
                            placeholder="Filtrar dentro de la hoja por código o nombre del examen..."
                            icon="funnel"
                            clearable
                        />

                        @php $sheet = $this->sheet; @endphp

                        @if ($sheet['meta']['note'] ?? null)
                            <flux:text class="text-xs text-zinc-500">{{ $sheet['meta']['note'] }}</flux:text>
                        @endif

                        <div class="max-h-[36rem] space-y-5 overflow-y-auto rounded-lg border p-3 dark:border-zinc-700">
                            @forelse ($sheet['sections'] as $sectionName => $rows)
                                <div>
                                    <div class="mb-2 border-b pb-1 text-xs font-semibold tracking-wide text-zinc-500 uppercase dark:border-zinc-700">
                                        {{ $sectionName }}
                                    </div>

                                    <table class="w-full table-fixed text-sm">
                                        <colgroup>
                                            <col class="w-8">
                                            <col class="w-20">
                                            <col class="w-auto">
                                            @if ($sheet['meta']['shows_plates'] ?? false)
                                                <col class="w-14">
                                                <col class="w-20">
                                            @endif
                                            <col class="w-24">
                                        </colgroup>
                                        <thead>
                                            <tr class="text-xs text-zinc-500">
                                                <th></th>
                                                <th class="px-2 py-1 text-start">Código</th>
                                                <th class="px-2 py-1 text-start">Examen</th>
                                                @if ($sheet['meta']['shows_plates'] ?? false)
                                                    <th class="px-2 py-1 text-center">Placas</th>
                                                    <th class="px-2 py-1 text-center">Medidas</th>
                                                @endif
                                                <th class="px-2 py-1 text-end">Precio</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($rows as $row)
                                                @php $sel = $row['cod_precio'] && in_array($row['cod_precio'], $this->selectedPrices, true); @endphp
                                                <tr class="{{ $sel ? 'bg-indigo-50 dark:bg-indigo-400/10' : '' }} {{ $row['cod_precio'] ? 'cursor-pointer hover:bg-zinc-50 dark:hover:bg-white/5' : 'opacity-50' }}"
                                                    @if ($row['cod_precio']) wire:click="toggleItem('{{ $row['cod_precio'] }}')" @endif>
                                                    <td class="px-2 py-1.5 text-center">
                                                        @if ($sel)
                                                            <flux:icon.check-circle class="size-4 text-indigo-500" />
                                                        @elseif ($row['cod_precio'])
                                                            <span class="inline-block size-3.5 rounded border border-zinc-400"></span>
                                                        @else
                                                            <flux:icon.minus class="size-4 text-zinc-300" />
                                                        @endif
                                                    </td>
                                                    <td class="px-2 py-1.5 font-mono text-xs whitespace-nowrap text-zinc-500">{{ $row['codigo'] }}</td>
                                                    <td class="px-2 py-1.5 break-words whitespace-normal {{ $sel ? 'font-medium text-indigo-700 dark:text-indigo-400' : '' }}">
                                                        {{ $row['descripcion'] }}
                                                    </td>
                                                    @if ($sheet['meta']['shows_plates'] ?? false)
                                                        <td class="px-2 py-1.5 text-center text-xs text-zinc-500">{{ $row['placas'] }}</td>
                                                        <td class="px-2 py-1.5 text-center text-xs text-zinc-500">{{ $row['medidas'] }}</td>
                                                    @endif
                                                    <td class="px-2 py-1.5 text-end whitespace-nowrap">
                                                        @if ($row['precio'] !== null)
                                                            S/ {{ number_format($row['precio'], 2) }}
                                                        @else
                                                            <span class="text-xs text-zinc-400">—</span>
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @empty
                                <flux:text class="text-sm text-zinc-500">
                                    @if (trim($sheetFilter) !== '')
                                        Ningún examen de esta hoja coincide con "{{ $sheetFilter }}".
                                    @else
                                        Esta hoja no tiene exámenes con precio para la forma de pago seleccionada.
                                    @endif
                                </flux:text>
                            @endforelse
                        </div>
                    @else
                    @if ($this->categories->isNotEmpty())
                        <div class="flex flex-wrap gap-2">
                            <button
                                type="button"
                                wire:click="setCategoryFilter(null)"
                                class="rounded-full border px-3 py-1.5 text-sm font-medium {{ ! $categoryFilter ? 'border-indigo-500 bg-indigo-50 text-indigo-700 dark:bg-indigo-400/10 dark:text-indigo-400' : 'border-zinc-300 hover:border-indigo-500 dark:border-zinc-600' }}"
                            >
                                Todos
                            </button>
                            @foreach ($this->categories as $cat)
                                <button
                                    type="button"
                                    wire:click="setCategoryFilter('{{ $cat->codigo_grupo }}')"
                                    class="rounded-full border px-3 py-1.5 text-sm font-medium {{ $categoryFilter === $cat->codigo_grupo ? 'border-indigo-500 bg-indigo-50 text-indigo-700 dark:bg-indigo-400/10 dark:text-indigo-400' : 'border-zinc-300 hover:border-indigo-500 dark:border-zinc-600' }}"
                                >
                                    {{ ucfirst(mb_strtolower($cat->nombre_grupo_nomen)) }}
                                </button>
                            @endforeach
                        </div>
                    @endif

                    <flux:input wire:model.live.debounce.400ms="itemQuery" placeholder="Buscar dentro de esta categoría (opcional)..." icon="magnifying-glass" />

                    <flux:text class="text-xs text-zinc-500">Ordenado por lo más solicitado según el historial de ventas. Click para agregar, click de nuevo para quitar.</flux:text>

                    <div class="max-h-96 divide-y overflow-y-auto rounded-lg border dark:divide-zinc-700 dark:border-zinc-700">
                        @forelse ($this->itemResults as $price)
                            @php $isSelected = in_array($price->cod_precio, $this->selectedPrices, true); @endphp
                            <button
                                type="button"
                                wire:click="toggleItem('{{ $price->cod_precio }}')"
                                class="flex w-full items-center justify-between gap-4 px-3 py-2.5 text-left {{ $isSelected ? 'bg-indigo-50 dark:bg-indigo-400/10' : 'hover:bg-zinc-100 dark:hover:bg-zinc-800' }}"
                            >
                                <span class="text-sm {{ $isSelected ? 'font-medium text-indigo-700 dark:text-indigo-400' : '' }}">{{ $price->billableItem?->descripcion_nomen_tipo }}</span>
                                <span class="flex shrink-0 items-center gap-2">
                                    <span class="font-medium whitespace-nowrap">S/ {{ number_format($price->precio, 2) }}</span>
                                    @if ($isSelected)
                                        <flux:icon.check-circle class="size-5 text-indigo-500" />
                                    @else
                                        <flux:icon.plus-circle class="size-5 text-zinc-400" />
                                    @endif
                                </span>
                            </button>
                        @empty
                            <div class="px-3 py-4 text-sm text-zinc-500">
                                No hay servicios con precio para esta forma de pago{{ $itemQuery !== '' ? ' que coincidan con "'.$itemQuery.'"' : '' }}.
                            </div>
                        @endforelse
                    </div>
                    @endif
                @endif
            </flux:card>
        </div>

        {{-- Vista previa de la venta, fija a la derecha en escritorio --}}
        <div class="lg:sticky lg:top-6 lg:h-fit">
            <flux:card class="space-y-4">
                <div class="flex items-center justify-between">
                    <flux:subheading>4. Vista previa de la venta</flux:subheading>
                    @if ($this->cartCount > 0)
                        <flux:badge color="zinc">{{ $this->cartCount }} {{ Str::plural('ítem', $this->cartCount) }}</flux:badge>
                    @endif
                </div>

                @if (empty($cart))
                    <flux:text class="text-sm text-zinc-500">Aún no agregas servicios. Elige uno del catálogo a la izquierda.</flux:text>
                @else
                    <div class="max-h-80 space-y-3 overflow-y-auto">
                        @foreach ($cart as $index => $line)
                            <div class="flex items-start justify-between gap-2 border-b pb-3 last:border-0 dark:border-zinc-700">
                                <div class="min-w-0 flex-1">
                                    <flux:text class="line-clamp-2 text-sm font-medium">{{ $line['descripcion'] }}</flux:text>
                                    <div class="mt-1 flex items-center gap-2">
                                        <button type="button" wire:click="updateQuantity({{ $index }}, {{ $line['cantidad'] - 1 }})" class="flex size-6 items-center justify-center rounded-full border text-sm dark:border-zinc-600">−</button>
                                        <span class="w-6 text-center text-sm">{{ $line['cantidad'] }}</span>
                                        <button type="button" wire:click="updateQuantity({{ $index }}, {{ $line['cantidad'] + 1 }})" class="flex size-6 items-center justify-center rounded-full border text-sm dark:border-zinc-600">+</button>
                                        <span class="text-xs text-zinc-500">× S/ {{ number_format($line['precio'], 2) }}</span>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <flux:text class="font-medium whitespace-nowrap">S/ {{ number_format($line['precio'] * $line['cantidad'], 2) }}</flux:text>
                                    <button type="button" wire:click="removeItem({{ $index }})" class="block text-xs text-red-600 hover:underline dark:text-red-400">Quitar</button>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="flex items-center justify-between border-t pt-3 dark:border-zinc-700">
                        <flux:text class="text-zinc-500">Total a cobrar</flux:text>
                        <flux:heading size="xl">S/ {{ number_format($this->subtotal, 2) }}</flux:heading>
                    </div>
                @endif

                <flux:button variant="primary" wire:click="openConfirm" :disabled="empty($cart)" class="w-full">
                    Confirmar y emitir boleta
                </flux:button>
            </flux:card>
        </div>
    </div>

    {{-- Confirmacion antes de emitir: el cobro se escribe en la base recien al aceptar --}}
    <flux:modal name="confirmar-cobro" class="max-w-xl">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Confirmar cobro</flux:heading>
                <flux:subheading>Revisa los datos antes de emitir. Al aceptar se registra la boleta y se abrirá la impresión.</flux:subheading>
            </div>

            <div class="grid grid-cols-2 gap-3 rounded-lg border border-zinc-200 p-3 text-sm dark:border-white/10">
                <div>
                    <flux:text class="text-xs text-zinc-500">Paciente</flux:text>
                    <flux:text class="font-medium">{{ $patientLabel }}</flux:text>
                    <flux:text class="block text-xs text-zinc-500">{{ $patientDocLabel }} · HC {{ $patientHcNumber }}</flux:text>
                </div>
                <div>
                    <flux:text class="text-xs text-zinc-500">Forma de pago</flux:text>
                    <flux:text class="font-medium">{{ $this->paymentBreadcrumb->pluck('nom_forma_pago')->implode(' › ') }}</flux:text>
                    <flux:text class="block text-xs text-zinc-500">Turno {{ $this->currentSession?->cod_aper_cierre_caja }}</flux:text>
                </div>
            </div>

            <div class="max-h-56 space-y-2 overflow-y-auto rounded-lg border border-zinc-200 p-3 dark:border-white/10">
                @foreach ($cart as $line)
                    <div class="flex items-start justify-between gap-3 text-sm">
                        <span class="min-w-0 flex-1">
                            <span class="text-zinc-500">{{ (int) $line['cantidad'] }}×</span>
                            {{ \Illuminate\Support\Str::limit($line['descripcion'], 80) }}
                        </span>
                        <span class="shrink-0 font-medium whitespace-nowrap">S/ {{ number_format($line['precio'] * $line['cantidad'], 2) }}</span>
                    </div>
                @endforeach
            </div>

            <div class="flex items-center justify-between rounded-lg bg-indigo-50 px-4 py-3 dark:bg-indigo-400/10">
                <flux:text class="font-medium">Total a cobrar</flux:text>
                <flux:heading size="lg" class="text-indigo-700! dark:text-indigo-400!">S/ {{ number_format($this->subtotal, 2) }}</flux:heading>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="submit" icon="printer">Emitir e imprimir</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
