<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reporte diario — {{ $cajero?->nom_usu ?? $codUsu }}</title>
    <style>
        /* Mismo contenido que el A4, reordenado para 80 mm: en un rollo no caben
           cinco columnas, asi que cada servicio ocupa dos lineas (descripcion arriba,
           cifras abajo) en vez de encogerse hasta ser ilegible. */
        @page { size: {{ $anchoMm }}mm auto; margin: 0; }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 4mm 3mm 8mm;
            width: {{ $anchoMm }}mm;
            font-family: "Consolas", "Courier New", monospace;
            font-size: 10px;
            line-height: 1.35;
            color: #000;
            background: #fff;
        }

        .centro { text-align: center; }
        .fuerte { font-weight: bold; }

        .logo { display: block; margin: 0 auto 3px; max-width: 26mm; max-height: 18mm; object-fit: contain; }

        .hospital { font-size: 11px; font-weight: bold; line-height: 1.25; }
        .sub { font-size: 9px; }

        hr { border: 0; border-top: 1px dashed #000; margin: 5px 0; }
        hr.solida { border-top: 1px solid #000; }

        h1 { font-size: 11px; text-align: center; margin: 5px 0; letter-spacing: .04em; }

        .par { display: flex; justify-content: space-between; gap: 6px; font-size: 9.5px; }
        .par span:first-child { flex: none; }
        .par span:last-child { text-align: right; word-break: break-word; }

        .forma {
            margin-top: 7px;
            text-align: center;
            font-weight: bold;
            font-size: 10.5px;
            border-top: 1px solid #000;
            border-bottom: 1px solid #000;
            padding: 2px 0;
        }

        .cuenta {
            display: flex;
            justify-content: space-between;
            gap: 6px;
            font-weight: bold;
            font-size: 9.5px;
            margin-top: 5px;
            border-bottom: 1px dotted #000;
        }
        .cuenta span:first-child { flex: 1; }

        .servicio { margin: 3px 0 0; }
        .servicio .nombre { font-size: 9px; word-break: break-word; }
        .servicio .cifras {
            display: flex;
            justify-content: space-between;
            font-size: 9px;
            padding-left: 4px;
        }

        .subtotal {
            display: flex;
            justify-content: space-between;
            font-size: 9.5px;
            font-weight: bold;
            margin-top: 3px;
            padding-top: 2px;
            border-top: 1px dotted #000;
        }

        .total {
            display: flex;
            justify-content: space-between;
            font-size: 10px;
            padding: 1px 0;
        }
        .total.general { font-size: 12px; font-weight: bold; border-top: 1px solid #000; border-bottom: 1px solid #000; padding: 3px 0; margin-top: 2px; }

        .firma { margin-top: 16px; text-align: center; font-size: 9px; }
        .firma .linea { border-top: 1px solid #000; margin: 22px 6mm 3px; }

        .aviso { margin-top: 8px; text-align: center; font-size: 8px; font-weight: bold; line-height: 1.3; }

        .pie { margin-top: 6px; text-align: center; font-size: 8px; }

        .barra { margin: 0 0 8px; display: flex; gap: 6px; flex-wrap: wrap; }
        .barra button, .barra a {
            font: inherit; font-size: 10px; padding: 5px 9px; border: 1px solid #111;
            border-radius: 4px; background: #111; color: #fff; cursor: pointer; text-decoration: none;
        }
        .barra a.alt { background: #fff; color: #111; }

        @media print { .barra { display: none; } }
    </style>
</head>
<body>
    <div class="barra">
        <button type="button" onclick="window.print()">Imprimir</button>
        <a class="alt" href="{{ request()->fullUrlWithQuery(['formato' => 'a4', 'imprimir' => null]) }}">Ver A4</a>
    </div>

    @if ($logoUrl)
        <img src="{{ $logoUrl }}" alt="" class="logo">
    @endif

    <div class="centro hospital">{{ $hospital }}</div>
    @if ($unidad)<div class="centro sub">{{ $unidad }}</div>@endif
    @if ($ruc)<div class="centro sub">R.U.C. {{ $ruc }}</div>@endif
    @if ($direccion)<div class="centro sub">{{ $direccion }}</div>@endif

    <h1>REPORTE RECAUDACIÓN CAJA</h1>
    <hr class="solida">

    <div class="par"><span>Cajero:</span><span>{{ trim($cajero?->nom_usu ?? '') ?: $codUsu }}</span></div>
    <div class="par"><span>Código:</span><span>{{ trim($codUsu) }}</span></div>
    <div class="par"><span>Desde:</span><span>{{ $desde->format('d/m/Y') }}</span></div>
    <div class="par"><span>Hasta:</span><span>{{ $hasta->format('d/m/Y') }}</span></div>
    <div class="par"><span>Doc.:</span><span>C (Cancelado)</span></div>
    <div class="par"><span>Comprob.:</span><span>{{ $comprobantes['emitidos'] }} emit. / {{ $comprobantes['anulados'] }} anul.</span></div>

    @forelse ($formasPago as $forma)
        <div class="forma">{{ $forma['nombre'] }}</div>

        @foreach ($forma['cuentas'] as $cuenta)
            <div class="cuenta">
                <span>{{ $cuenta['cuenta'] }} {{ $cuenta['descripcion'] }}</span>
                <span>{{ number_format($cuenta['total'], 2) }}</span>
            </div>

            @foreach ($cuenta['servicios'] as $s)
                <div class="servicio">
                    <div class="nombre">{{ $s->servicio }}</div>
                    <div class="cifras">
                        <span>{{ number_format($s->cantidad, 2) }} x {{ number_format($s->precio, 2) }}@if ($s->descuento > 0) · Dsto {{ number_format($s->descuento, 2) }}@endif</span>
                        <span>{{ number_format($s->total, 2) }}</span>
                    </div>
                </div>
            @endforeach
        @endforeach

        <div class="subtotal">
            <span>Subtotal {{ $forma['nombre'] }}</span>
            <span>{{ number_format($forma['total'], 2) }}</span>
        </div>
    @empty
        <hr>
        <div class="centro">Sin recaudación en el periodo.</div>
    @endforelse

    <hr class="solida">

    <div class="total"><span>TOTAL VENTAS</span><span>{{ number_format($totalVentas, 2) }}</span></div>
    <div class="total"><span>DEPÓSITOS PACIENTE</span><span>{{ number_format($depositos, 2) }}</span></div>
    <div class="total general"><span>TOTAL GENERAL</span><span>{{ number_format($totalGeneral, 2) }}</span></div>

    <div class="firma">
        <div class="linea"></div>
        Recaudador
        <div class="linea"></div>
        Cajero General
    </div>

    <p class="aviso">* ESTE INFORME ES NULO SIN LA FIRMA DEL RECAUDADOR Y LA FIRMA DEL CAJERO GENERAL</p>

    <div class="pie">
        Impreso por {{ $impresoPor ?? '—' }}<br>
        {{ $impresoEn->format('d/m/Y H:i:s') }}
    </div>

    @if ($autoPrint)
        <script>window.addEventListener('load', () => window.print());</script>
    @endif
</body>
</html>
