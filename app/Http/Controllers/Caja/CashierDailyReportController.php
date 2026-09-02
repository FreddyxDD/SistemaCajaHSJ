<?php

namespace App\Http\Controllers\Caja;

use App\Http\Controllers\Controller;
use App\Models\Caja\ChargeDocument;
use App\Support\Caja\LegacyDate;
use App\Support\Caja\LegacyIdGenerator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Reporte diario del cajero — «Reporte Recaudación Caja».
 *
 * Reproduce el reporte que hoy emite SISGESH M-CAJA y que el cajero entrega firmado
 * al cajero central: agrupado por forma de pago, dentro de ella por cuenta contable, y
 * dentro de la cuenta el detalle por servicio con cantidad, precio, descuento y total.
 *
 * Se emite por rango de fechas y por cajero (no por turno): un cajero puede abrir mas
 * de un turno en el dia y el reporte que se entrega es el del dia completo.
 *
 * Dos formatos, mismo contenido: A4 para el expediente firmado, y ticketera para
 * cerrar la caja en la ventanilla sin ir a una impresora de oficina.
 */
class CashierDailyReportController extends Controller
{
    public function __invoke(Request $request): View
    {
        $desde = $this->parseDate($request->query('desde')) ?? Carbon::today();
        $hasta = $this->parseDate($request->query('hasta')) ?? $desde;

        // Un rango invertido no es un error del usuario que valga la pena bloquear:
        // se ordena y sigue.
        if ($hasta->lt($desde)) {
            [$desde, $hasta] = [$hasta, $desde];
        }

        $codUsu = trim((string) $request->query('cajero')) ?: LegacyIdGenerator::legacyUserCode(Auth::user());

        // El cajero imprime lo suyo; ver lo de otro cajero exige supervision.
        abort_unless(
            Auth::user()?->canDo('caja.cashiers.view')
                || $codUsu === LegacyIdGenerator::legacyUserCode(Auth::user()),
            403,
            'Solo puedes imprimir tu propio reporte diario.',
        );

        $cajero = DB::connection('caja')->table('Usuario')->where('cod_usu', $codUsu)->first();

        $lineas = $this->lineas($codUsu, $desde, $hasta);

        // forma de pago -> cuenta contable -> servicios, respetando el orden de la consulta.
        $formasPago = $lineas
            ->groupBy('forma')
            ->map(fn ($deLaForma) => [
                'nombre' => $deLaForma->first()->forma,
                'total' => (float) $deLaForma->sum('total'),
                'cuentas' => $deLaForma
                    ->groupBy('cuenta')
                    ->map(fn ($deLaCuenta) => [
                        'cuenta' => $deLaCuenta->first()->cuenta,
                        'descripcion' => $deLaCuenta->first()->cuenta_descripcion,
                        'total' => (float) $deLaCuenta->sum('total'),
                        'servicios' => $deLaCuenta->values(),
                    ])
                    ->values(),
            ])
            ->values();

        $totalVentas = (float) $lineas->sum('total');
        $depositos = $this->depositos($codUsu, $desde, $hasta);

        $logo = config('ticket.logo');
        $logoPath = $logo ? public_path($logo) : null;

        $datos = [
            'desde' => $desde,
            'hasta' => $hasta,
            'cajero' => $cajero,
            'codUsu' => $codUsu,
            'formasPago' => $formasPago,
            'totalVentas' => $totalVentas,
            'depositos' => $depositos,
            'totalGeneral' => $totalVentas + $depositos,
            'comprobantes' => $this->conteoComprobantes($codUsu, $desde, $hasta),
            'hospital' => config('ticket.hospital'),
            'unidad' => config('ticket.unidad'),
            'direccion' => config('ticket.direccion'),
            'ruc' => config('ticket.ruc'),
            'anchoMm' => config('ticket.ancho_mm'),
            'logoUrl' => ($logoPath && is_file($logoPath)) ? asset($logo) : null,
            'impresoPor' => Auth::user()?->name,
            'impresoEn' => now(),
            'autoPrint' => $request->boolean('imprimir'),
        ];

        return view(
            $request->query('formato') === 'ticket' ? 'caja.daily-report-ticket' : 'caja.daily-report',
            $datos,
        );
    }

    /**
     * Detalle recaudado del periodo. Se agrupa tambien por precio unitario: el mismo
     * servicio cobrado a dos tarifas distintas son dos lineas en el reporte, tal como
     * las imprime el sistema actual.
     */
    private function lineas(string $codUsu, Carbon $desde, Carbon $hasta)
    {
        $fecha = LegacyDate::sqlToDate('d.fecha_actu');

        return DB::connection('caja')
            ->table('Cabecera_documento_MH as d')
            ->join('Detalle_documento_MH as dd', 'dd.id_documento', '=', 'd.id_documento')
            ->leftJoin('Precio_MH as p', 'p.cod_precio', '=', 'dd.cod_precio')
            ->leftJoin('Nomenclatura_caja_MH as n', 'n.cod_nomen_caja', '=', 'p.cod_nomen_caja')
            ->leftJoin('Cuenta_7 as c7', 'c7.Id_cuenta7', '=', 'n.id_cuenta7')
            ->leftJoin('Jerarquia_Forma_Pago_MH as fp', 'fp.cod_jerar_forma_pago', '=', 'd.cod_jerar_forma_pago')
            ->where('d.cod_usu', $codUsu)
            ->where('d.estado_doc', ChargeDocument::ESTADO_EMITIDO)
            ->whereRaw("{$fecha} between ? and ?", [$desde->format('Y-m-d'), $hasta->format('Y-m-d')])
            ->selectRaw("
                COALESCE(RTRIM(fp.nom_forma_pago), 'SIN FORMA DE PAGO') as forma,
                COALESCE(RTRIM(c7.Cuenta_7), 'SIN CUENTA') as cuenta,
                COALESCE(RTRIM(c7.descripcion_7), 'Sin cuenta contable asignada') as cuenta_descripcion,
                COALESCE(RTRIM(n.descripcion_nomen_tipo), 'Servicio no identificado') as servicio,
                SUM(dd.cantidad_detalle) as cantidad,
                dd.precio_detalle as precio,
                SUM(dd.descu_exo_detalle) as descuento,
                SUM(dd.total_detalle) as total
            ")
            ->groupBy('fp.nom_forma_pago', 'c7.Cuenta_7', 'c7.descripcion_7', 'n.descripcion_nomen_tipo', 'dd.precio_detalle')
            ->orderBy('forma')
            ->orderBy('cuenta')
            ->orderByDesc('total')
            ->get();
    }

    /**
     * Depositos de pacientes del periodo. La tabla existe en el legado y hoy esta
     * vacia; el reporte la imprime igual porque el formato oficial la contempla.
     */
    private function depositos(string $codUsu, Carbon $desde, Carbon $hasta): float
    {
        $fecha = LegacyDate::sqlToDate('fecha_deposito');

        return (float) DB::connection('caja')
            ->table('DEPOSITOS_PACIENTE_MH')
            ->where('cod_usu', $codUsu)
            ->whereRaw("{$fecha} between ? and ?", [$desde->format('Y-m-d'), $hasta->format('Y-m-d')])
            ->sum('monto_deposito');
    }

    /** @return array{emitidos: int, anulados: int} */
    private function conteoComprobantes(string $codUsu, Carbon $desde, Carbon $hasta): array
    {
        $fecha = LegacyDate::sqlToDate('fecha_actu');

        $fila = DB::connection('caja')
            ->table('Cabecera_documento_MH')
            ->where('cod_usu', $codUsu)
            ->whereRaw("{$fecha} between ? and ?", [$desde->format('Y-m-d'), $hasta->format('Y-m-d')])
            ->selectRaw('
                SUM(CASE WHEN estado_doc = ? THEN 1 ELSE 0 END) as emitidos,
                SUM(CASE WHEN estado_doc = ? THEN 1 ELSE 0 END) as anulados
            ', [ChargeDocument::ESTADO_EMITIDO, ChargeDocument::ESTADO_ANULADO])
            ->first();

        return [
            'emitidos' => (int) ($fila->emitidos ?? 0),
            'anulados' => (int) ($fila->anulados ?? 0),
        ];
    }

    private function parseDate(?string $valor): ?Carbon
    {
        if (! $valor) {
            return null;
        }

        try {
            return Carbon::parse($valor)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
