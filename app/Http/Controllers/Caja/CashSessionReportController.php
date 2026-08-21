<?php

namespace App\Http\Controllers\Caja;

use App\Http\Controllers\Controller;
use App\Models\Caja\CashSession;
use App\Models\Caja\ChargeDocument;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CashSessionReportController extends Controller
{
    /**
     * Reporte general del turno a nivel de cuentas contables.
     *
     * El plan de cuentas ya existe en el sistema legado: cada concepto facturable
     * (Nomenclatura_caja_MH) apunta a una cuenta de septimo nivel via `id_cuenta7`,
     * y esa cuenta cuelga de la jerarquia Cuenta_1..Cuenta_7. El detalle del
     * comprobante llega a la nomenclatura a traves del precio cobrado
     * (Detalle_documento_MH.cod_precio -> Precio_MH.cod_nomen_caja), asi que el
     * importe recaudado se puede clasificar por cuenta y, dentro de ella, por el
     * servicio que lo genero (SERVICIO_JERARQUIA_3).
     *
     * No se inventa ninguna clasificacion: la que se imprime es la que ya esta
     * registrada en la base.
     */
    public function __invoke(string $sessionCode): View
    {
        $session = CashSession::query()->findOrFail($sessionCode);

        $cashier = DB::connection('caja')
            ->table('Usuario')
            ->where('cod_usu', $session->cod_usu)
            ->first();

        // Formas de pago con movimiento en el turno; alimentan el selector del reporte.
        $availablePaymentMethods = DB::connection('caja')
            ->table('Cabecera_documento_MH as d')
            ->leftJoin('Jerarquia_Forma_Pago_MH as pm', 'pm.cod_jerar_forma_pago', '=', 'd.cod_jerar_forma_pago')
            ->where('d.cod_aper_cierre_caja', $sessionCode)
            ->where('d.estado_doc', ChargeDocument::ESTADO_EMITIDO)
            ->selectRaw("
                COALESCE(RTRIM(pm.fp_padre), 'OTROS') as grupo,
                COUNT(*) as boletas,
                SUM(d.total_doc) as total
            ")
            ->groupBy('pm.fp_padre')
            ->orderBy('grupo')
            ->get();

        // Un reporte por forma de pago: contabilidad los rinde por separado, asi que
        // el filtro no es cosmetico — cuando esta activo NO se emite un total mezclado.
        $grupo = strtoupper(trim((string) request()->query('grupo', 'all')));
        $grupo = ($grupo === '' || $grupo === 'ALL') ? 'all' : $grupo;

        $filterByGroup = fn ($query) => $grupo === 'all'
            ? $query
            : $query->whereRaw("COALESCE(RTRIM(pm.fp_padre), 'OTROS') = ?", [$grupo]);

        $rows = DB::connection('caja')
            ->table('Cabecera_documento_MH as d')
            ->join('Detalle_documento_MH as dd', 'dd.id_documento', '=', 'd.id_documento')
            ->leftJoin('Precio_MH as p', 'p.cod_precio', '=', 'dd.cod_precio')
            ->leftJoin('Nomenclatura_caja_MH as n', 'n.cod_nomen_caja', '=', 'p.cod_nomen_caja')
            ->leftJoin('Cuenta_7 as c7', 'c7.Id_cuenta7', '=', 'n.id_cuenta7')
            ->leftJoin('SERVICIO_JERARQUIA_3 as s3', 's3.cod_nivel_servicio_3', '=', 'n.cod_nivel_servicio_3')
            ->leftJoin('Jerarquia_Forma_Pago_MH as pm', 'pm.cod_jerar_forma_pago', '=', 'd.cod_jerar_forma_pago')
            ->where('d.cod_aper_cierre_caja', $sessionCode)
            ->where('d.estado_doc', ChargeDocument::ESTADO_EMITIDO)
            ->tap($filterByGroup)
            ->selectRaw("
                COALESCE(RTRIM(c7.Cuenta_7), 'SIN CUENTA') as cuenta,
                COALESCE(RTRIM(c7.descripcion_7), 'Conceptos sin cuenta contable asignada') as cuenta_descripcion,
                COALESCE(RTRIM(s3.nom_ser_3), 'Sin servicio') as servicio,
                COUNT(*) as items,
                SUM(dd.cantidad_detalle) as cantidad,
                SUM(dd.total_detalle) as total
            ")
            ->groupBy('c7.Cuenta_7', 'c7.descripcion_7', 's3.nom_ser_3')
            ->orderBy('c7.Cuenta_7')
            ->orderByDesc(DB::raw('SUM(dd.total_detalle)'))
            ->get();

        // Cada cuenta contable con sus servicios; el orden ya viene de la consulta.
        $accounts = $rows
            ->groupBy('cuenta')
            ->map(fn ($group) => [
                'cuenta' => $group->first()->cuenta,
                'descripcion' => $group->first()->cuenta_descripcion,
                'servicios' => $group,
                'cantidad' => $group->sum('cantidad'),
                'total' => (float) $group->sum('total'),
            ])
            ->values();

        $byPaymentMethod = DB::connection('caja')
            ->table('Cabecera_documento_MH as d')
            ->leftJoin('Jerarquia_Forma_Pago_MH as pm', 'pm.cod_jerar_forma_pago', '=', 'd.cod_jerar_forma_pago')
            ->where('d.cod_aper_cierre_caja', $sessionCode)
            ->where('d.estado_doc', ChargeDocument::ESTADO_EMITIDO)
            ->tap($filterByGroup)
            ->selectRaw("
                COALESCE(pm.nom_forma_pago, 'Sin forma de pago') as forma,
                COALESCE(pm.fp_padre, 'OTROS') as grupo,
                COUNT(*) as boletas,
                SUM(d.total_doc) as total
            ")
            ->groupBy('pm.nom_forma_pago', 'pm.fp_padre')
            ->orderByDesc('total')
            ->get();

        $documentTotals = DB::connection('caja')
            ->table('Cabecera_documento_MH as d')
            ->leftJoin('Jerarquia_Forma_Pago_MH as pm', 'pm.cod_jerar_forma_pago', '=', 'd.cod_jerar_forma_pago')
            ->where('d.cod_aper_cierre_caja', $sessionCode)
            ->tap($filterByGroup)
            ->selectRaw("
                SUM(CASE WHEN d.estado_doc = ? THEN 1 ELSE 0 END) as emitidos,
                SUM(CASE WHEN d.estado_doc = ? THEN d.total_doc ELSE 0 END) as recaudado,
                SUM(CASE WHEN d.estado_doc = ? THEN 1 ELSE 0 END) as anulados,
                SUM(CASE WHEN d.estado_doc = ? THEN d.total_doc ELSE 0 END) as anulado_monto
            ", [
                ChargeDocument::ESTADO_EMITIDO,
                ChargeDocument::ESTADO_EMITIDO,
                ChargeDocument::ESTADO_ANULADO,
                ChargeDocument::ESTADO_ANULADO,
            ])
            ->first();

        $logo = config('ticket.logo');
        $logoPath = $logo ? public_path($logo) : null;

        return view('caja.session-report', [
            'session' => $session,
            'cashier' => $cashier,
            'accounts' => $accounts,
            'byPaymentMethod' => $byPaymentMethod,
            'documentTotals' => $documentTotals,
            // El total de las cuentas sale del detalle; el de cabeceras, de los
            // comprobantes. Se imprimen los dos para que el cuadre sea evidente.
            'totalCuentas' => (float) $rows->sum('total'),
            'hospital' => config('ticket.hospital'),
            'unidad' => config('ticket.unidad'),
            'ruc' => config('ticket.ruc'),
            'logoUrl' => ($logoPath && is_file($logoPath)) ? asset($logo) : null,
            'printedAt' => now(),
            'printedByName' => Auth::user()?->name,
            'autoPrint' => request()->boolean('imprimir'),
            'grupo' => $grupo,
            'availablePaymentMethods' => $availablePaymentMethods,
            // Un turno abierto sigue recibiendo cobros: las cifras son provisionales y
            // el papel tiene que decirlo, no el que lo entrega.
            'sessionIsOpen' => $session->isOpen(),
        ]);
    }
}
