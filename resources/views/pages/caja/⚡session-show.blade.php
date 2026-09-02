<?php

use App\Models\Caja\CashSession;
use App\Models\Caja\ChargeDocument;
use App\Support\Caja\PaymentMethodPalette;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Detalle de turno')] class extends Component {
    public string $sessionCode;

    /** Busca por numero de comprobante, HC, documento o nombres del paciente. */
    #[Url(as: 'q', except: '')]
    public string $q = '';

    /** Filtro por grupo de forma de pago (fp_padre) o 'all'. */
    public string $grupo = 'all';

    public function mount(string $sessionCode): void
    {
        $this->sessionCode = $sessionCode;
    }

    #[Computed]
    public function session(): CashSession
    {
        return CashSession::query()->findOrFail($this->sessionCode);
    }

    #[Computed]
    public function cashier()
    {
        return DB::connection('caja')->table('Usuario')->where('cod_usu', $this->session->cod_usu)->first();
    }

    /** Todos los comprobantes del turno: es la base de los totales, sin filtrar. */
    #[Computed]
    public function allDocuments()
    {
        return ChargeDocument::query()
            ->with(['paymentMethod', 'historiaClinica'])
            ->where('cod_aper_cierre_caja', $this->sessionCode)
            ->orderByDesc('id_documento')
            ->get();
    }

    /**
     * Los comprobantes que se listan. El filtrado es en memoria a proposito: un turno
     * tiene decenas de boletas, ya estan cargadas para el cuadre y asi la busqueda
     * alcanza tambien la HC y el DNI, que viven en la tabla relacionada.
     */
    #[Computed]
    public function documents()
    {
        $term = mb_strtolower(trim($this->q));

        return $this->allDocuments
            ->when($this->grupo !== 'all', fn ($docs) => $docs->filter(
                fn ($doc) => strtoupper(trim((string) ($doc->paymentMethod?->fp_padre ?? ''))) === $this->grupo
            ))
            ->when($term !== '', fn ($docs) => $docs->filter(function ($doc) use ($term) {
                $hc = $doc->historiaClinica;

                $haystack = mb_strtolower(implode(' ', array_filter([
                    $doc->num_documento,
                    $doc->cliente,
                    $hc?->historia_number,
                    $hc?->dni,
                    $hc?->full_name,
                ])));

                // Cada palabra debe aparecer: "ramos 14501" encuentra al paciente
                // Ramos en ese comprobante, sin importar el orden.
                foreach (preg_split('/\s+/', $term, -1, PREG_SPLIT_NO_EMPTY) as $word) {
                    if (! str_contains($haystack, $word)) {
                        return false;
                    }
                }

                return true;
            }))
            ->values();
    }

    #[Computed]
    public function totals(): array
    {
        $docs = $this->allDocuments;
        $vigentes = $docs->where('estado_doc', ChargeDocument::ESTADO_EMITIDO);

        return [
            'recaudado' => (float) $vigentes->sum('total_doc'),
            'boletas' => $vigentes->count(),
            'anuladas' => $docs->where('estado_doc', ChargeDocument::ESTADO_ANULADO)->count(),
            'perdido' => (float) $docs->where('estado_doc', ChargeDocument::ESTADO_ANULADO)->sum('total_doc'),
        ];
    }

    /** Cuadre del turno: cuanto entro por cada forma de pago. */
    #[Computed]
    public function byPaymentMethod()
    {
        return DB::connection('caja')
            ->table('Cabecera_documento_MH as d')
            ->leftJoin('Jerarquia_Forma_Pago_MH as pm', 'pm.cod_jerar_forma_pago', '=', 'd.cod_jerar_forma_pago')
            ->where('d.cod_aper_cierre_caja', $this->sessionCode)
            ->where('d.estado_doc', ChargeDocument::ESTADO_EMITIDO)
            ->selectRaw("COALESCE(pm.nom_forma_pago, 'Sin forma de pago') as forma, COALESCE(pm.fp_padre, 'Otros') as grupo, COUNT(*) as boletas, SUM(d.total_doc) as total")
            ->groupBy('pm.nom_forma_pago', 'pm.fp_padre')
            ->orderByDesc('total')
            ->get();
    }

    /**
     * Recaudacion clasificada por cuenta contable, tal como ya la define el sistema
     * legado: Nomenclatura_caja_MH.id_cuenta7 -> Cuenta_7. El detalle llega a la
     * nomenclatura por el precio cobrado. Es el mismo criterio que se imprime en el
     * reporte del turno.
     */
    #[Computed]
    public function byAccount()
    {
        return DB::connection('caja')
            ->table('Cabecera_documento_MH as d')
            ->join('Detalle_documento_MH as dd', 'dd.id_documento', '=', 'd.id_documento')
            ->leftJoin('Precio_MH as p', 'p.cod_precio', '=', 'dd.cod_precio')
            ->leftJoin('Nomenclatura_caja_MH as n', 'n.cod_nomen_caja', '=', 'p.cod_nomen_caja')
            ->leftJoin('Cuenta_7 as c7', 'c7.Id_cuenta7', '=', 'n.id_cuenta7')
            ->where('d.cod_aper_cierre_caja', $this->sessionCode)
            ->where('d.estado_doc', ChargeDocument::ESTADO_EMITIDO)
            ->selectRaw("
                COALESCE(RTRIM(c7.Cuenta_7), 'SIN CUENTA') as cuenta,
                COALESCE(RTRIM(c7.descripcion_7), 'Sin cuenta contable asignada') as descripcion,
                COUNT(*) as items,
                SUM(dd.total_detalle) as total
            ")
            ->groupBy('c7.Cuenta_7', 'c7.descripcion_7')
            ->orderByDesc(DB::raw('SUM(dd.total_detalle)'))
            ->get();
    }
}; ?>

<section class="w-full space-y-6">
    <div>
        <flux:button href="{{ route('caja.cashiers.index') }}" wire:navigate variant="ghost" size="sm" icon="arrow-left">Volver a cajeros</flux:button>
    </div>

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">Turno {{ $this->session->cod_aper_cierre_caja }}</flux:heading>
            <div class="mt-1 flex items-center gap-2">
                <flux:icon.user class="size-4 text-zinc-400" />
                <flux:text class="font-medium">{{ $this->cashier?->nom_usu ?? 'Sin nombre' }}</flux:text>
                <flux:text class="text-xs text-zinc-400">{{ $this->session->cod_usu }}</flux:text>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <flux:badge :color="$this->session->isOpen() ? 'amber' : 'zinc'">
                {{ $this->session->isOpen() ? 'Turno abierto' : 'Turno cerrado' }}
            </flux:badge>

            {{-- Reporte contable en A4: se abre en otra pestaña y lanza la impresion. --}}
            <flux:button
                href="{{ route('caja.sessions.report', [$this->sessionCode, 'imprimir' => 1]) }}"
                target="_blank"
                variant="primary"
                size="sm"
                icon="printer"
            >
                Imprimir reporte del turno
            </flux:button>
        </div>
    </div>

    {{-- Reporte diario del cajero: es el del dia completo, no el de este turno, porque
         un cajero puede abrir varios turnos en la jornada y eso es lo que entrega
         firmado al cajero central. --}}
    @php $diaDelTurno = $this->session->openedAt()?->format('Y-m-d'); @endphp

    @if ($diaDelTurno)
        <flux:card class="space-y-3">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <flux:subheading>Reporte diario del cajero</flux:subheading>
                    <flux:text class="mt-1 text-sm text-zinc-500">
                        Recaudación de <b>{{ trim($this->cashier?->nom_usu ?? '') ?: $this->session->cod_usu }}</b>
                        del {{ $this->session->openedAt()->format('d/m/Y') }} —
                        el día completo, agrupado por forma de pago y cuenta contable.
                    </flux:text>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <flux:button
                    href="{{ route('caja.daily-report', ['cajero' => trim($this->session->cod_usu), 'desde' => $diaDelTurno, 'imprimir' => 1]) }}"
                    target="_blank"
                    variant="filled"
                    size="sm"
                    icon="document-text"
                >
                    Imprimir A4
                </flux:button>

                <flux:button
                    href="{{ route('caja.daily-report', ['cajero' => trim($this->session->cod_usu), 'desde' => $diaDelTurno, 'formato' => 'ticket', 'imprimir' => 1]) }}"
                    target="_blank"
                    variant="filled"
                    size="sm"
                    icon="receipt-percent"
                >
                    Imprimir en ticketera
                </flux:button>

                <flux:button
                    href="{{ route('caja.daily-report', ['cajero' => trim($this->session->cod_usu), 'desde' => $diaDelTurno]) }}"
                    target="_blank"
                    variant="ghost"
                    size="sm"
                >
                    Ver sin imprimir
                </flux:button>
            </div>
        </flux:card>
    @endif

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="acrilico p-5">
            <flux:text class="text-sm text-zinc-500">Recaudado en el turno</flux:text>
            <div class="mt-1 text-2xl font-semibold text-accent">S/ {{ number_format($this->totals['recaudado'], 2) }}</div>
        </div>
        <div class="acrilico p-5">
            <flux:text class="text-sm text-zinc-500">Comprobantes</flux:text>
            <div class="mt-1 text-2xl font-semibold">{{ $this->totals['boletas'] }}</div>
        </div>
        <div class="acrilico p-5">
            <flux:text class="text-sm text-zinc-500">Anulados</flux:text>
            <div class="mt-1 text-2xl font-semibold {{ $this->totals['anuladas'] > 0 ? 'text-red-600 dark:text-red-400' : '' }}">{{ $this->totals['anuladas'] }}</div>
            <flux:text class="text-xs text-zinc-500">S/ {{ number_format($this->totals['perdido'], 2) }}</flux:text>
        </div>
        <div class="acrilico p-5">
            <flux:text class="text-sm text-zinc-500">Apertura</flux:text>
            <div class="mt-1 text-sm font-medium">{{ $this->session->fecha_apertura }}</div>
            <flux:text class="text-xs text-zinc-500">{{ $this->session->hora_apertura }}</flux:text>
            @if (! $this->session->isOpen())
                <flux:text class="mt-2 block text-xs text-zinc-500">Cierre: {{ $this->session->fecha_cierre }} {{ $this->session->hora_cierre }}</flux:text>
            @endif
        </div>
    </div>

    {{-- Cuadre por forma de pago --}}
    <div class="acrilico p-5">
        <flux:subheading class="mb-4">Cuadre por forma de pago</flux:subheading>

        @if ($this->byPaymentMethod->isEmpty())
            <flux:text class="text-sm text-zinc-500">Este turno no tiene cobros registrados.</flux:text>
        @else
            @php $maxPm = $this->byPaymentMethod->max('total') ?: 1; @endphp
            <div class="space-y-3">
                @foreach ($this->byPaymentMethod as $pm)
                    <div>
                        <div class="flex items-center justify-between text-sm">
                            <span class="flex items-center gap-2">
                                <span class="size-2 rounded-full {{ \App\Support\Caja\PaymentMethodPalette::dot($pm->grupo) }}"></span>
                                {{ $pm->forma }} <span class="text-xs text-zinc-400">({{ $pm->grupo }})</span>
                            </span>
                            <span class="font-medium">S/ {{ number_format($pm->total, 2) }} <span class="text-xs text-zinc-500">· {{ $pm->boletas }} bol.</span></span>
                        </div>
                        <div class="mt-1 h-2 w-full rounded-full bg-zinc-100 dark:bg-white/10">
                            <div class="h-2 rounded-full {{ \App\Support\Caja\PaymentMethodPalette::bar($pm->grupo) }}" style="width: {{ max(4, round($pm->total / $maxPm * 100)) }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Recaudacion por cuenta contable: el mismo criterio del reporte impreso. --}}
    <div class="acrilico p-5">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <flux:subheading>Recaudación por cuenta contable</flux:subheading>
            <flux:link
                href="{{ route('caja.sessions.report', $this->sessionCode) }}"
                target="_blank"
                class="text-xs"
            >
                Ver reporte con detalle por servicio
            </flux:link>
        </div>

        {{-- Cada forma de pago se imprime por separado; contabilidad las rinde asi. --}}
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <flux:text class="text-xs text-zinc-500">Imprimir solo:</flux:text>
            @foreach ($this->byPaymentMethod->pluck('grupo')->unique()->sort() as $grupoPago)
                <flux:button
                    href="{{ route('caja.sessions.report', [$this->sessionCode, 'grupo' => $grupoPago, 'imprimir' => 1]) }}"
                    target="_blank"
                    size="xs"
                    variant="ghost"
                    icon="printer"
                >
                    {{ $grupoPago }}
                </flux:button>
            @endforeach
        </div>

        @if ($this->byAccount->isEmpty())
            <flux:text class="text-sm text-zinc-500">Este turno no tiene cobros registrados.</flux:text>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 text-left text-xs text-zinc-500 dark:border-white/10">
                            <th class="py-2 pe-3 font-medium">Cuenta</th>
                            <th class="py-2 pe-3 font-medium">Descripción</th>
                            <th class="py-2 pe-3 text-right font-medium">Ítems</th>
                            <th class="py-2 text-right font-medium">Importe</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y dark:divide-white/10">
                        @foreach ($this->byAccount as $cuenta)
                            <tr>
                                <td class="py-2 pe-3 font-mono text-xs whitespace-nowrap">{{ $cuenta->cuenta }}</td>
                                <td class="py-2 pe-3">{{ $cuenta->descripcion }}</td>
                                <td class="py-2 pe-3 text-right text-zinc-500">{{ $cuenta->items }}</td>
                                <td class="py-2 text-right font-medium">S/ {{ number_format($cuenta->total, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-zinc-300 dark:border-white/20">
                            <td class="py-2 font-semibold" colspan="3">Total</td>
                            <td class="py-2 text-right font-semibold">S/ {{ number_format($this->byAccount->sum('total'), 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </div>

    {{-- Boletas del turno --}}
    <div class="overflow-hidden acrilico">
        <div class="space-y-3 border-b border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-white/10 dark:bg-white/5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <flux:subheading>Comprobantes emitidos en este turno</flux:subheading>
                <flux:text class="text-xs text-zinc-500">
                    Mostrando {{ $this->documents->count() }} de {{ $this->allDocuments->count() }}
                </flux:text>
            </div>

            <flux:input
                wire:model.live.debounce.300ms="q"
                icon="magnifying-glass"
                placeholder="Buscar por comprobante, HC, documento o nombres..."
                clearable
            />

            {{-- Filtro rapido por grupo de forma de pago, con el mismo color de las barras. --}}
            <div class="flex flex-wrap gap-2">
                @php
                    $grupos = $this->allDocuments
                        ->groupBy(fn ($doc) => strtoupper(trim((string) ($doc->paymentMethod?->fp_padre ?? 'OTROS'))) ?: 'OTROS');
                @endphp

                <button
                    type="button"
                    wire:click="$set('grupo', 'all')"
                    class="rounded-full border px-3 py-1 text-xs font-medium {{ $grupo === 'all' ? 'border-accent bg-accent/10 text-accent' : 'border-zinc-300 hover:border-accent dark:border-zinc-600' }}"
                >
                    Todas <span class="opacity-60">{{ $this->allDocuments->count() }}</span>
                </button>

                @foreach ($grupos as $nombreGrupo => $docsGrupo)
                    <button
                        type="button"
                        wire:click="$set('grupo', '{{ $nombreGrupo }}')"
                        class="flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-medium {{ $grupo === $nombreGrupo ? 'border-accent bg-accent/10 text-accent' : 'border-zinc-300 hover:border-accent dark:border-zinc-600' }}"
                    >
                        <span class="size-2 rounded-full {{ \App\Support\Caja\PaymentMethodPalette::dot($nombreGrupo) }}"></span>
                        {{ $nombreGrupo }} <span class="opacity-60">{{ $docsGrupo->count() }}</span>
                    </button>
                @endforeach
            </div>
        </div>

        <div class="max-h-[32rem] divide-y overflow-y-auto dark:divide-white/10">
            @forelse ($this->documents as $doc)
                @php
                    $hc = $doc->historiaClinica;
                    $grupoPago = $doc->paymentMethod?->fp_padre;
                @endphp
                <a
                    href="{{ route('caja.charges.show', $doc->id_documento) }}"
                    wire:navigate
                    class="flex items-center gap-4 px-4 py-3 hover:bg-zinc-50 dark:hover:bg-white/5"
                >
                    {{-- Franja de color: identifica la forma de pago sin leer el texto. --}}
                    <span class="h-10 w-1 shrink-0 rounded-full {{ \App\Support\Caja\PaymentMethodPalette::dot($grupoPago) }}"></span>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium">{{ $doc->num_documento }}</span>

                            @if ($hc?->historia_number)
                                <flux:badge color="zinc" size="sm">HC {{ $hc->historia_number }}</flux:badge>
                            @endif

                            <flux:badge :color="\App\Support\Caja\PaymentMethodPalette::badge($grupoPago)" size="sm">
                                {{ $doc->paymentMethod?->nom_forma_pago ?? 'Sin forma de pago' }}
                            </flux:badge>

                            @if ($doc->isVoided())
                                <flux:badge color="red" size="sm">Anulado</flux:badge>
                            @endif
                        </div>

                        <div class="mt-0.5 truncate text-sm">
                            {{ $hc?->full_name ?: $doc->cliente }}
                        </div>

                        <div class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-zinc-500">
                            <span class="inline-flex items-center gap-1">
                                <flux:icon.clock class="size-3.5" />{{ $doc->hora_actu }}
                            </span>
                            @if ($hc?->dni)
                                <span class="inline-flex items-center gap-1">
                                    <flux:icon.identification class="size-3.5" />DNI {{ trim($hc->dni) }}
                                </span>
                            @endif
                            @if ($hc?->sex_label || $hc?->age !== null)
                                <span>{{ collect([$hc?->sex_label, $hc?->age !== null ? $hc->age.' años' : null])->filter()->implode(' · ') }}</span>
                            @endif
                        </div>
                    </div>

                    <div class="shrink-0 text-right">
                        <div class="font-semibold {{ $doc->isVoided() ? 'text-zinc-400 line-through' : '' }}">
                            S/ {{ number_format($doc->total_doc, 2) }}
                        </div>
                    </div>

                    <flux:icon.chevron-right class="size-4 shrink-0 text-zinc-400" />
                </a>
            @empty
                <div class="px-4 py-8 text-center text-sm text-zinc-500">
                    @if (trim($q) !== '' || $grupo !== 'all')
                        Ningún comprobante de este turno coincide con la búsqueda.
                    @else
                        Este turno aún no tiene comprobantes.
                    @endif
                </div>
            @endforelse
        </div>
    </div>
</section>
