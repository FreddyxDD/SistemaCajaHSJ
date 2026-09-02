<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reporte Recaudación Caja — {{ $cajero?->nom_usu ?? $codUsu }}</title>
    <style>
        /* Formato A4 del reporte que el cajero entrega firmado al cajero central.
           Estilos propios: es un documento para archivo, no una pantalla. */
        @page { size: A4 portrait; margin: 12mm 10mm; }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11px;
            color: #000;
            background: #f3f4f6;
        }

        .hoja {
            width: 190mm;
            margin: 16px auto;
            padding: 12mm 10mm;
            background: #fff;
            box-shadow: 0 2px 12px rgba(0, 0, 0, .12);
        }

        /* Cabecera institucional */
        .sistema {
            display: flex;
            justify-content: space-between;
            font-size: 9px;
            letter-spacing: .04em;
            border-bottom: 1px solid #000;
            padding-bottom: 3px;
        }

        .establecimiento { text-align: center; margin: 10px 0 14px; line-height: 1.45; }
        .establecimiento .nombre { font-size: 14px; font-weight: bold; }
        .establecimiento div { font-size: 10.5px; }

        h1 {
            font-size: 13px;
            font-weight: bold;
            margin: 0 0 10px;
            padding-bottom: 4px;
            border-bottom: 1px solid #000;
        }

        /* Datos del reporte */
        .datos { display: grid; grid-template-columns: repeat(2, 1fr); gap: 2px 24px; margin-bottom: 14px; }
        .datos .par { display: grid; grid-template-columns: 130px 1fr; font-size: 10.5px; }
        .datos .par span:first-child { font-weight: bold; }

        /* Bloque por forma de pago */
        .forma { margin-top: 16px; page-break-inside: auto; }
        .forma > .titulo {
            text-align: center;
            font-weight: bold;
            font-size: 12px;
            letter-spacing: .08em;
            padding: 4px 0;
            border-top: 1px solid #000;
            border-bottom: 1px solid #000;
            margin-bottom: 6px;
        }

        table { width: 100%; border-collapse: collapse; }
        thead th {
            font-size: 9.5px;
            text-align: left;
            border-bottom: 1px solid #000;
            padding: 3px 4px;
            font-weight: bold;
        }
        th.n, td.n { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }

        tr.cuenta td {
            font-weight: bold;
            font-size: 10.5px;
            padding: 6px 4px 3px;
            border-bottom: 1px solid #999;
        }

        tr.servicio td {
            font-size: 10px;
            padding: 2px 4px;
            vertical-align: top;
            border-bottom: 1px dotted #ccc;
        }
        tr.servicio td.desc { padding-left: 10px; }

        tr.subtotal td {
            font-size: 10px;
            font-weight: bold;
            padding: 3px 4px 8px;
            text-align: right;
        }

        /* Totales */
        .totales { margin-top: 18px; margin-left: auto; width: 62%; }
        .totales div {
            display: flex;
            justify-content: space-between;
            font-size: 11px;
            padding: 3px 4px;
        }
        .totales .venta { border-top: 1px solid #000; font-weight: bold; }
        .totales .general {
            border-top: 2px solid #000;
            border-bottom: 2px solid #000;
            font-size: 13px;
            font-weight: bold;
        }

        /* Firmas */
        .firmas { display: flex; gap: 60px; margin-top: 46px; }
        .firma { flex: 1; text-align: center; font-size: 10px; padding-top: 5px; border-top: 1px solid #000; }

        .aviso {
            margin-top: 18px;
            text-align: center;
            font-size: 9.5px;
            font-weight: bold;
            letter-spacing: .03em;
        }

        footer {
            margin-top: 16px;
            padding-top: 6px;
            border-top: 1px solid #999;
            display: flex;
            justify-content: space-between;
            font-size: 8.5px;
            color: #444;
        }

        .vacio { padding: 24px 0; text-align: center; font-size: 11px; }

        .barra { width: 190mm; margin: 12px auto 0; display: flex; gap: 8px; justify-content: flex-end; }
        .barra button, .barra a {
            font: inherit; padding: 7px 14px; border: 1px solid #111; border-radius: 5px;
            background: #111; color: #fff; cursor: pointer; text-decoration: none;
        }
        .barra a.alt { background: #fff; color: #111; }

        @media print {
            body { background: #fff; }
            .hoja { width: auto; margin: 0; padding: 0; box-shadow: none; }
            .barra { display: none; }
            tr, .forma > .titulo, .totales, .firmas { page-break-inside: avoid; }
            thead { display: table-header-group; }
        }
    </style>
</head>
<body>
    <div class="barra">
        <a class="alt" href="{{ request()->fullUrlWithQuery(['formato' => 'ticket', 'imprimir' => null]) }}">Ver en ticketera</a>
        <button type="button" onclick="window.print()">Imprimir A4</button>
    </div>

    <div class="hoja">
        <div class="sistema">
            <span>GESTIÓN DE CAJA HSJ</span>
            <span>{{ $unidad }}</span>
        </div>

        <div class="establecimiento">
            <div class="nombre">{{ $hospital }}</div>
            @if ($unidad)<div>{{ $unidad }}</div>@endif
            @if ($ruc)<div>R.U.C. {{ $ruc }}</div>@endif
            @if ($direccion)<div>{{ $direccion }}</div>@endif
        </div>

        <h1>Reporte Recaudación Caja</h1>

        <div class="datos">
            <div class="par"><span>Fecha Inicial :</span><span>{{ $desde->format('d/m/Y') }}</span></div>
            <div class="par"><span>Fecha Impresión :</span><span>{{ $impresoEn->format('d/m/Y') }}</span></div>
            <div class="par"><span>Fecha Final :</span><span>{{ $hasta->format('d/m/Y') }}</span></div>
            <div class="par"><span>Hora Impresión :</span><span>{{ $impresoEn->format('H:i:s') }}</span></div>
            <div class="par"><span>Usuario :</span><span>{{ trim($cajero?->nom_usu ?? '') ?: $codUsu }}</span></div>
            <div class="par"><span>Doc. :</span><span>C (Cancelado)</span></div>
            <div class="par"><span>Código :</span><span>{{ trim($codUsu) }}</span></div>
            <div class="par"><span>Comprobantes :</span><span>{{ $comprobantes['emitidos'] }} emitidos · {{ $comprobantes['anulados'] }} anulados</span></div>
        </div>

        @forelse ($formasPago as $forma)
            <div class="forma">
                <div class="titulo">{{ $forma['nombre'] }}</div>

                <table>
                    <thead>
                        <tr>
                            <th style="width:52%">Característica D.C.I.</th>
                            <th class="n" style="width:10%">Can</th>
                            <th class="n" style="width:12%">Prec</th>
                            <th class="n" style="width:12%">Dsto</th>
                            <th class="n" style="width:14%">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($forma['cuentas'] as $cuenta)
                            <tr class="cuenta">
                                <td>{{ $cuenta['cuenta'] }} {{ $cuenta['descripcion'] }}</td>
                                <td colspan="3"></td>
                                <td class="n">{{ number_format($cuenta['total'], 2) }}</td>
                            </tr>

                            @foreach ($cuenta['servicios'] as $s)
                                <tr class="servicio">
                                    <td class="desc">{{ $s->servicio }}</td>
                                    <td class="n">{{ number_format($s->cantidad, 2) }}</td>
                                    <td class="n">{{ number_format($s->precio, 2) }}</td>
                                    <td class="n">{{ number_format($s->descuento, 2) }}</td>
                                    <td class="n">{{ number_format($s->total, 2) }}</td>
                                </tr>
                            @endforeach
                        @endforeach

                        <tr class="subtotal">
                            <td colspan="4">Subtotal {{ $forma['nombre'] }}</td>
                            <td class="n">{{ number_format($forma['total'], 2) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        @empty
            <div class="vacio">Sin recaudación registrada en el periodo para este cajero.</div>
        @endforelse

        <div class="totales">
            <div class="venta"><span>TOTAL VENTAS</span><span>{{ number_format($totalVentas, 2) }}</span></div>
            <div><span>DEPÓSITOS PACIENTE</span><span>{{ number_format($depositos, 2) }}</span></div>
            <div class="general"><span>TOTAL GENERAL</span><span>{{ number_format($totalGeneral, 2) }}</span></div>
        </div>

        <div class="firmas">
            <div class="firma">Recaudador</div>
            <div class="firma">Cajero General</div>
        </div>

        <p class="aviso">* ESTE INFORME ES NULO SIN LA FIRMA DEL RECAUDADOR Y LA FIRMA DEL CAJERO GENERAL</p>

        <footer>
            <span>Impreso por {{ $impresoPor ?? '—' }}</span>
            <span>{{ $impresoEn->format('d/m/Y H:i:s') }}</span>
        </footer>
    </div>

    @if ($autoPrint)
        <script>window.addEventListener('load', () => window.print());</script>
    @endif
</body>
</html>
