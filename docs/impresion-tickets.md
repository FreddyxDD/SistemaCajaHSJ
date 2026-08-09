# Impresión de boletas (ticketera)

## Cómo funciona

La boleta **no** se imprime desde la pantalla de detalle. Al emitir un cobro (o al
pulsar "Imprimir/Reimprimir boleta") se carga en un **iframe oculto** la ruta
`caja/cobros/{id}/ticket`, que renderiza el comprobante en formato de impresora
térmica y llama a `window.print()` apenas carga. Así el papel sale solo con el
ticket, sin el menú ni la cabecera del aplicativo.

Cada carga de esa ruta queda registrada en `receipt_prints` (base propia `HSJ_Caja`):

- La **primera** impresión es el original.
- Las **siguientes** salen marcadas como `*** REIMPRESIÓN ***`, con la leyenda
  "COPIA DEL ORIGINAL — NO VÁLIDA COMO COMPROBANTE DE PAGO", el número de impresión,
  y la fecha/hora exacta de esa reimpresión.

El pie del ticket siempre lleva: cajero, turno, fecha/hora de impresión y quién
imprimió — precisamente para poder distinguir una copia del original.

## Impresión inmediata, sin diálogo (importante)

Por seguridad, **ningún navegador permite que una página web imprima en silencio**:
`window.print()` siempre abre el diálogo de impresión. No es una limitación del
aplicativo ni algo que se pueda resolver con código de la aplicación.

Para que la boleta salga directa a la impresora (que es el comportamiento requerido
en caja), el navegador del puesto debe iniciarse en **modo kiosk-printing**. Con ese
modo activo, `window.print()` envía el trabajo a la impresora predeterminada sin
mostrar ningún diálogo — el flujo ya está preparado para eso y no requiere cambios.

### Configurar el acceso directo del navegador

**Microsoft Edge**

```
msedge.exe --kiosk-printing http://<servidor>/caja/cobros/nuevo
```

**Google Chrome**

```
chrome.exe --kiosk-printing http://<servidor>/caja/cobros/nuevo
```

Pasos en el equipo de caja:

1. Crear un acceso directo al navegador.
2. Clic derecho → Propiedades → en **Destino**, agregar ` --kiosk-printing` al final
   de la ruta del ejecutable.
3. Configurar la **ticketera como impresora predeterminada** de Windows (el modo
   kiosk imprime siempre en la predeterminada, no pregunta cuál usar).
4. En las preferencias de la impresora, fijar el tamaño de papel del rollo (80 mm) y
   márgenes en 0.

Sin ese flag el sistema sigue funcionando igual, solo que el cajero verá el diálogo
de impresión y deberá confirmar.

## Configuración del ticket

Se edita en `config/ticket.php` o por variables de entorno, sin tocar la plantilla:

| Variable | Descripción | Valor por defecto |
|---|---|---|
| `TICKET_HOSPITAL` | Nombre en la cabecera | HOSPITAL SAN JOSÉ DE CHINCHA |
| `TICKET_UNIDAD` | Segunda línea de la cabecera | Unidad Ejecutora 401 Salud Chincha |
| `TICKET_DIRECCION` | Dirección (opcional) | vacío |
| `TICKET_RUC` | RUC (opcional) | vacío |
| `TICKET_LOGO` | Ruta del logo dentro de `public/` | `img/logo-hospital.png` |
| `TICKET_ANCHO_MM` | Ancho del papel | `80` |
| `TICKET_PIE` | Texto de pie | Gracias por su preferencia |

### Logo

Colocar el logo del hospital en `public/img/logo-hospital.png` (PNG o JPG,
preferentemente en blanco y negro o alto contraste: las ticketeras térmicas imprimen
solo en un tono). Se recomienda un ancho de ~300 px.

**Si el archivo no existe, el ticket imprime solo el nombre del hospital** en vez de
mostrar una imagen rota. No se incluye ningún logo por defecto porque no se dispone
del archivo oficial del hospital.
