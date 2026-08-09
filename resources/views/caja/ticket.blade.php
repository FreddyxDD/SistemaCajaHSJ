{{--
    Ticket de boleta para impresora termica. Es una pagina independiente (sin layout
    de la aplicacion) porque se imprime desde un iframe oculto: asi el papel sale
    solo con el ticket, sin menu ni cabecera del aplicativo.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Boleta {{ $document->num_documento }}</title>
    <style>
        @page {
            /* Rollo continuo: el alto lo define el contenido, no una hoja fija. */
            size: {{ $anchoMm }}mm auto;
            margin: 0;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 3mm;
            width: {{ $anchoMm }}mm;
            font-family: "Courier New", ui-monospace, monospace;
            font-size: 10px;
            line-height: 1.35;
            color: #000;
            background: #fff;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .center { text-align: center; }
        .right  { text-align: right; }
        .bold   { font-weight: bold; }
        .upper  { text-transform: uppercase; }
        .small  { font-size: 9px; }
        .muted  { color: #333; }

        .logo {
            max-width: 28mm;
            max-height: 18mm;
            margin: 0 auto 2mm;
            display: block;
        }

        .hospital {
            font-size: 12px;
            font-weight: bold;
            line-height: 1.2;
        }

        hr {
            border: 0;
            border-top: 1px dashed #000;
            margin: 2mm 0;
        }

        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 0; vertical-align: top; }

        .items th {
            text-align: left;
            border-bottom: 1px solid #000;
            padding-bottom: 1mm;
            font-size: 9px;
        }
        .items td { padding: 0.8mm 0; }
        .items .desc { word-break: break-word; }

        .total-row td {
            border-top: 1px solid #000;
            padding-top: 1.5mm;
            font-size: 13px;
            font-weight: bold;
        }

        .datos td { padding: 0.3mm 0; }
        .datos .k { width: 18mm; }

        /* Marca de copia: debe ser evidente en papel termico (solo negro), por eso
           es un recuadro con texto y no una marca de agua tenue en color. */
        .reimpresion {
            border: 2px solid #000;
            padding: 1.5mm;
            margin: 2mm 0;
            text-align: center;
            font-weight: bold;
            font-size: 12px;
            letter-spacing: 1px;
        }

        .anulado {
            border: 2px solid #000;
            padding: 2mm;
            margin: 2mm 0;
            text-align: center;
            font-weight: bold;
            font-size: 14px;
            letter-spacing: 2px;
        }
    </style>
</head>
<body>

    <div class="center">
        @if ($logoUrl)
            <img src="{{ $logoUrl }}" alt="" class="logo">
        @endif

        <div class="hospital">{{ $hospital }}</div>

        @if ($unidad)
            <div class="small">{{ $unidad }}</div>
        @endif
        @if ($direccion)
            <div class="small">{{ $direccion }}</div>
        @endif
        @if ($ruc)
            <div class="small">RUC {{ $ruc }}</div>
        @endif
    </div>

    <hr>

    <div class="center bold upper">
        {{ $document->documentType?->tipo_documento ?? 'Comprobante' }}
    </div>
    <div class="center bold" style="font-size: 13px;">{{ $document->num_documento }}</div>

    @if ($isReprint)
        <div class="reimpresion">
            *** REIMPRESIÓN ***<br>
            <span class="small" style="letter-spacing:0">COPIA DEL ORIGINAL — NO VÁLIDA COMO COMPROBANTE DE PAGO</span>
        </div>
    @endif

    @if ($document->isVoided())
        <div class="anulado">*** ANULADO ***</div>
    @endif

    <hr>

    <table class="datos small">
        <tr>
            <td class="k">Fecha</td>
            <td>: {{ $document->fecha_actu }} {{ $document->hora_actu }}</td>
        </tr>
        <tr>
            <td class="k">Paciente</td>
            <td>: {{ $document->cliente }}</td>
        </tr>
        <tr>
            <td class="k">H. Clínica</td>
            <td>: {{ $document->historiaClinica?->historia_number ?? '—' }}</td>
        </tr>
        @if ($documento = trim((string) $document->historiaClinica?->dni))
            <tr>
                <td class="k">Documento</td>
                <td>: {{ $documento }}</td>
            </tr>
        @endif
        <tr>
            <td class="k">F. de pago</td>
            <td>: {{ $document->paymentMethod?->nom_forma_pago }}</td>
        </tr>
    </table>

    <hr>

    <table class="items">
        <thead>
            <tr>
                <th style="width: 7mm;">Cant</th>
                <th>Descripción</th>
                <th class="right" style="width: 16mm;">Importe</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($document->items as $item)
                <tr>
                    <td>{{ (int) $item->cantidad_detalle }}</td>
                    <td class="desc">
                        {{ $item->price?->billableItem?->descripcion_nomen_tipo }}
                        <br><span class="small muted">P.U. {{ number_format($item->precio_detalle, 2) }}</span>
                    </td>
                    <td class="right">{{ number_format($item->total_detalle, 2) }}</td>
                </tr>
            @endforeach

            <tr class="total-row">
                <td colspan="2">TOTAL S/</td>
                <td class="right">{{ number_format($document->total_doc, 2) }}</td>
            </tr>
        </tbody>
    </table>

    <hr>

    {{-- Datos del cajero al pie, con la fecha/hora de ESTA impresion para poder
         distinguir una reimpresion del original. --}}
    <table class="datos small">
        <tr>
            <td class="k">Cajero</td>
            <td>: {{ $document->cashier?->nom_usu ?? $document->cod_usu }}</td>
        </tr>
        <tr>
            <td class="k">Turno</td>
            <td>: {{ $document->cod_aper_cierre_caja }}</td>
        </tr>
        <tr>
            <td class="k">Impreso</td>
            <td>: {{ $printedAt->format('d/m/Y H:i:s') }}</td>
        </tr>
        @if ($printedByName)
            <tr>
                <td class="k">Por</td>
                <td>: {{ $printedByName }}</td>
            </tr>
        @endif
        @if ($printNumber > 1)
            <tr>
                <td class="k">Impresión</td>
                <td>: N° {{ $printNumber }} de este comprobante</td>
            </tr>
        @endif
    </table>

    <hr>

    <div class="center small">{{ $pie }}</div>

    <script>
        // Impresion inmediata al cargar. Con el navegador en modo kiosk-printing
        // (ver docs/impresion-tickets.md) sale directo a la impresora predeterminada
        // sin dialogo; sin ese modo el navegador mostrara el dialogo estandar.
        window.addEventListener('load', function () {
            setTimeout(function () { window.print(); }, 250);
        });
    </script>
</body>
</html>
