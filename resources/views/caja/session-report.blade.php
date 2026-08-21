<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reporte contable — Turno {{ $session->cod_aper_cierre_caja }}</title>
    <style>
        /* Reporte en A4 vertical: es un documento para archivo contable, no una
           pantalla; por eso lleva sus propios estilos y no el CSS de la app. */
        @page { size: A4 portrait; margin: 14mm 12mm; }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: 'Segoe UI', Arial, sans-serif;
            font-size: 11px;
            color: #111;
            background: #f3f4f6;
        }

        .hoja {
            width: 190mm;
            margin: 16px auto;
            padding: 14mm 12mm;
            background: #fff;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.12);
        }

        header { display: flex; align-items: center; gap: 12px; border-bottom: 2px solid #111; padding-bottom: 10px; }
        header img { width: 54px; height: 54px; object-fit: contain; }
        .hospital { font-size: 15px; font-weight: 700; letter-spacing: 0.02em; }
        .unidad { font-size: 11px; color: #444; }
        .doc-titulo { margin-left: auto; text-align: right; }
        .doc-titulo strong { display: block; font-size: 13px; }
        .doc-titulo span { font-size: 10px; color: #444; }

        .datos { display: flex; flex-wrap: wrap; gap: 6px 28px; margin: 12px 0 16px; }
        .dato { font-size: 11px; }
        .dato b { display: block; font-size: 9px; text-transform: uppercase; letter-spacing: 0.06em; color: #666; font-weight: 600; }

        h2 { font-size: 12px; text-transform: uppercase; letter-spacing: 0.06em; margin: 18px 0 6px; padding-bottom: 3px; border-bottom: 1px solid #bbb; }

        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 9px; text-transform: uppercase; letter-spacing: 0.05em; color: #555; border-bottom: 1px solid #999; padding: 4px 6px; }
        td { padding: 4px 6px; border-bottom: 1px solid #eee; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }

        tr.cuenta td { background: #f0f1f3; font-weight: 700; border-top: 1px solid #999; border-bottom: 1px solid #ccc; }
        tr.servicio td:first-child { padding-left: 18px; color: #333; }
        tr.total td { border-top: 2px solid #111; border-bottom: none; font-weight: 700; font-size: 12px; padding-top: 8px; }

        .cuadre { display: flex; gap: 20px; align-items: flex-start; }
        .cuadre > div { flex: 1; }

        footer { margin-top: 22px; padding-top: 8px; border-top: 1px solid #bbb; font-size: 9px; color: #555; display: flex; justify-content: space-between; gap: 12px; }

        .firmas { display: flex; gap: 40px; margin-top: 34px; }
        .firma { flex: 1; text-align: center; font-size: 10px; padding-top: 6px; border-top: 1px solid #111; }

        .barra-acciones { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; width: 190mm; margin: 12px auto 0; }
        .barra-acciones button { font: inherit; padding: 8px 16px; border: 1px solid #111; background: #111; color: #fff; border-radius: 6px; cursor: pointer; }
        .barra-acciones .etiqueta { font-size: 11px; color: #444; margin-right: 2px; }
        .barra-acciones a { font-size: 11px; text-decoration: none; color: #111; border: 1px solid #bbb; border-radius: 999px; padding: 5px 12px; background: #fff; }
        .barra-acciones a.activo { border-color: #111; background: #111; color: #fff; }
        .barra-acciones .separador { flex: 1; }

        .aviso-abierto {
            margin-bottom: 12px;
            padding: 8px 12px;
            border: 2px solid #111;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .aviso-abierto span { display: block; font-weight: 400; text-transform: none; letter-spacing: 0; margin-top: 3px; }

        .marca-provisional {
            position: fixed;
            top: 45%;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 62px;
            font-weight: 800;
            letter-spacing: 0.08em;
            color: rgba(17, 17, 17, 0.08);
            transform: rotate(-22deg);
            pointer-events: none;
            z-index: 0;
        }

        .alcance { margin: 0 0 10px; font-size: 11px; padding: 6px 10px; background: #f0f1f3; border-left: 3px solid #111; }

        @media print {
            body { background: #fff; }
            .hoja { width: auto; margin: 0; padding: 0; box-shadow: none; }
            .barra-acciones { display: none; }
            tr { break-inside: avoid; }
        }
    </style>
</head>
<body>
    {{-- Selector de alcance: cada forma de pago se imprime por separado porque
         contabilidad las rinde por separado. --}}
    <div class="barra-acciones">
        <span class="etiqueta">Forma de pago:</span>
        <a href="{{ route('caja.sessions.report', [$session->cod_aper_cierre_caja]) }}" class="{{ $grupo === 'all' ? 'activo' : '' }}">
            Todas (consolidado)
        </a>
        @foreach ($availablePaymentMethods as $fp)
            <a
                href="{{ route('caja.sessions.report', [$session->cod_aper_cierre_caja, 'grupo' => $fp->grupo]) }}"
                class="{{ $grupo === $fp->grupo ? 'activo' : '' }}"
            >
                {{ $fp->grupo }} ({{ $fp->boletas }})
            </a>
        @endforeach

        <span class="separador"></span>
        <button type="button" onclick="window.print()">Imprimir</button>
    </div>

    <div class="hoja">
        @if ($sessionIsOpen)
            <div class="marca-provisional">PROVISIONAL</div>
        @endif
        <header>
            @if ($logoUrl)
                <img src="{{ $logoUrl }}" alt="">
            @endif
            <div>
                <div class="hospital">{{ $hospital }}</div>
                <div class="unidad">{{ $unidad }}@if ($ruc) · RUC {{ $ruc }}@endif</div>
            </div>
            <div class="doc-titulo">
                <strong>Reporte contable de turno</strong>
                <span>
                    Recaudación por cuenta contable y servicio ·
                    {{ $grupo === 'all' ? 'Todas las formas de pago' : 'Solo '.$grupo }}
                </span>
            </div>
        </header>

        @if ($sessionIsOpen)
            <div class="aviso-abierto">
                Turno NO cerrado — cifras provisionales
                <span>
                    Este turno sigue abierto y puede seguir recibiendo cobros. El reporte refleja lo registrado hasta
                    el {{ $printedAt->format('d/m/Y') }} a las {{ $printedAt->format('H:i:s') }} y no reemplaza al
                    reporte de cierre.
                </span>
            </div>
        @endif

        <p class="alcance">
            @if ($grupo === 'all')
                <b>Alcance:</b> todas las formas de pago del turno. El total incluye la suma de todas ellas.
            @else
                <b>Alcance:</b> únicamente los comprobantes de la forma de pago <b>{{ $grupo }}</b>. Los importes de
                otras formas de pago no se incluyen ni se suman en este reporte.
            @endif
        </p>

        <div class="datos">
            <div class="dato"><b>Turno</b>{{ $session->cod_aper_cierre_caja }}</div>
            <div class="dato"><b>Cajero</b>{{ $cashier?->nom_usu ?? 'Sin nombre' }} ({{ trim($session->cod_usu) }})</div>
            <div class="dato"><b>Apertura</b>{{ $session->fecha_apertura }} {{ $session->hora_apertura }}</div>
            <div class="dato">
                <b>Cierre</b>
                {{ $session->isOpen() ? 'Turno abierto' : $session->fecha_cierre.' '.$session->hora_cierre }}
            </div>
            <div class="dato"><b>Comprobantes</b>{{ (int) $documentTotals->emitidos }} emitidos · {{ (int) $documentTotals->anulados }} anulados</div>
        </div>

        <h2>Recaudación por cuenta contable</h2>

        <table>
            <thead>
                <tr>
                    <th>Cuenta / Servicio</th>
                    <th class="num">Ítems</th>
                    <th class="num">Cantidad</th>
                    <th class="num">Importe S/</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($accounts as $account)
                    <tr class="cuenta">
                        <td>{{ $account['cuenta'] }} — {{ $account['descripcion'] }}</td>
                        <td class="num">{{ $account['servicios']->sum('items') }}</td>
                        <td class="num">{{ (int) $account['cantidad'] }}</td>
                        <td class="num">{{ number_format($account['total'], 2) }}</td>
                    </tr>
                    @foreach ($account['servicios'] as $servicio)
                        <tr class="servicio">
                            <td>{{ $servicio->servicio }}</td>
                            <td class="num">{{ $servicio->items }}</td>
                            <td class="num">{{ (int) $servicio->cantidad }}</td>
                            <td class="num">{{ number_format($servicio->total, 2) }}</td>
                        </tr>
                    @endforeach
                @empty
                    <tr><td colspan="4">Este turno no registra comprobantes vigentes.</td></tr>
                @endforelse

                <tr class="total">
                    <td>{{ $grupo === 'all' ? 'Total recaudado' : 'Total recaudado — '.$grupo }}</td>
                    <td></td>
                    <td></td>
                    <td class="num">S/ {{ number_format($totalCuentas, 2) }}</td>
                </tr>
            </tbody>
        </table>

        <div class="cuadre">
            <div>
                <h2>{{ $grupo === 'all' ? 'Cuadre por forma de pago' : 'Cuadre de '.$grupo }}</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Forma de pago</th>
                            <th>Grupo</th>
                            <th class="num">Bol.</th>
                            <th class="num">Importe S/</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($byPaymentMethod as $pm)
                            <tr>
                                <td>{{ $pm->forma }}</td>
                                <td>{{ $pm->grupo }}</td>
                                <td class="num">{{ $pm->boletas }}</td>
                                <td class="num">{{ number_format($pm->total, 2) }}</td>
                            </tr>
                        @endforeach
                        <tr class="total">
                            <td>{{ $grupo === 'all' ? 'Total comprobantes' : 'Total '.$grupo }}</td>
                            <td></td>
                            <td class="num">{{ (int) $documentTotals->emitidos }}</td>
                            <td class="num">S/ {{ number_format((float) $documentTotals->recaudado, 2) }}</td>
                        </tr>
                    </tbody>
                </table>

                @if (round($totalCuentas, 2) !== round((float) $documentTotals->recaudado, 2))
                    {{-- Si el detalle y las cabeceras no coinciden hay que verlo en el papel,
                         no descubrirlo despues en contabilidad. --}}
                    <p style="margin-top:6px;font-size:10px;">
                        <b>Diferencia detalle vs. comprobantes:</b>
                        S/ {{ number_format($totalCuentas - (float) $documentTotals->recaudado, 2) }}
                    </p>
                @endif

                @if ((int) $documentTotals->anulados > 0)
                    <p style="margin-top:6px;font-size:10px;">
                        Anulados en el turno: {{ (int) $documentTotals->anulados }} comprobante(s) por
                        S/ {{ number_format((float) $documentTotals->anulado_monto, 2) }} (no forman parte de la recaudación).
                    </p>
                @endif
            </div>
        </div>

        <div class="firmas">
            <div class="firma">Cajero</div>
            <div class="firma">Cajero central</div>
            <div class="firma">Jefe de la Unidad de Economía</div>
        </div>

        <footer>
            <span>Impreso por {{ $printedByName ?? '—' }} el {{ $printedAt->format('d/m/Y') }} a las {{ $printedAt->format('H:i:s') }}</span>
            <span>Turno {{ $session->cod_aper_cierre_caja }} · Sistema de Gestión de Caja HSJ</span>
        </footer>
    </div>

    @if ($autoPrint)
        <script>
            // Se abrio con ?imprimir=1 desde el detalle del turno.
            window.addEventListener('load', () => window.print());
        </script>
    @endif
</body>
</html>
