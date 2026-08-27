# Prompt para Claude Code — Correcciones de fidelidad del rediseño UI

Repo: `FreddyxDD/SistemaCajaHSJ` (branch `main`). El rediseño ya está implementado en su mayoría
(landing `welcome.blade.php`, `x-hospital-logo`, `x-app-footer`, acento índigo, radios W11,
animaciones en `resources/css/app.css`). Quedan 5 defectos de fidelidad. Corrígelos sin rediseñar
nada más y sin tocar la lógica PHP/Livewire (queries, `#[Computed]`, permisos).

---

## 1. Las tarjetas anulan el efecto acrílico (defecto visible principal)

En `resources/views/pages/⚡dashboard.blade.php` cada panel se escribe así:

```blade
<div class="relative overflow-hidden acrilico rounded-xl border border-zinc-200 bg-white p-5 dark:border-white/10 dark:bg-white/5">
```

`bg-white`, `border-zinc-200` y `rounded-xl` se aplican **después** de `.acrilico` y sobreescriben
sus tres propiedades (`background` semitransparente, `border-color` tenue, `border-radius: var(--radius-lg)`).
Resultado: las tarjetas salen opacas y planas, sin el blur ni las esquinas de 18px.

**Arreglo**: en todos los paneles de esa vista deja solo `acrilico` + utilidades de layout/espaciado.
Elimina de esos `div` las clases `bg-white`, `dark:bg-white/5`, `border`, `border-zinc-200`,
`dark:border-white/10` y `rounded-xl` (`.acrilico` ya trae fondo, borde y radio).

```blade
{{-- antes --}}
<div class="acrilico rounded-xl border border-zinc-200 bg-white p-5 dark:border-white/10 dark:bg-white/5">
{{-- después --}}
<div class="acrilico p-5">
```

Aplica el mismo criterio en el resto de vistas que combinen `acrilico` con `bg-white`/`rounded-xl`/`border-zinc-200`:
`resources/views/pages/caja/⚡sessions.blade.php`, `⚡session-show.blade.php`, `⚡cashiers.blade.php`,
`⚡charges.blade.php`, `⚡charge-show.blade.php`, `⚡new-charge.blade.php`, `⚡reports.blade.php`,
`⚡void-requests.blade.php`, `⚡price-lookup.blade.php`, `pages/admin/*`. Búscalas con
`rg 'acrilico' resources/views` y revisa cada coincidencia.

## 2. El color de marca está hardcodeado como `indigo-500/600`, no como token de acento

En el dashboard (y en `⚡reports.blade.php` línea ~214) las barras, cifras y acentos usan
`bg-indigo-500 dark:bg-indigo-400` y `text-indigo-600 dark:text-indigo-400`. Eso ignora
`--color-accent` del `@theme`, así que cambiar el color de marca en `app.css` no se propaga.

**Arreglo**: reemplaza esas utilidades por las del token:
- `bg-indigo-500 dark:bg-indigo-400` → `bg-accent`
- `text-indigo-600 dark:text-indigo-400` → `text-accent`
- `bg-indigo-500/10` → `bg-accent/10`

`.dark` ya redefine `--color-accent` a `#7b87e6`, así que la variante oscura sale sola: no dejes
variantes `dark:` de color de acento. **Excepción**: los colores semánticos se quedan como están
(ámbar de anulaciones pendientes, rojo de anulados, verde del badge de turno) — no son el acento.

## 3. Las barras del gráfico de 7 días no usan la animación existente

`app.css` define `.bar-grow { transition: height 0.8s cubic-bezier(0.16,1,0.3,1); }` pero ninguna
vista la aplica; el dashboard usa `transition-all` (que también anima el color y produce un
parpadeo al re-renderizar Livewire).

**Arreglo** en `⚡dashboard.blade.php` (~línea 251) y `⚡reports.blade.php` (~línea 214):

```blade
<div class="bar-grow w-full rounded-md bg-accent" style="height: {{ $day['pct'] }}%"></div>
```

Quita `transition-all` y las variantes `dark:` de color. El riel de fondo (`bg-zinc-100 dark:bg-white/10`)
se queda igual: es correcto que se vea como pista bajo la barra.

## 4. Falta el hover de elevación en las tarjetas del panel

`.lift` está definido en `app.css` pero solo lo usa la landing. Las tarjetas del dashboard deben
levantarse en hover como en el prototipo.

**Arreglo**: añade `lift` a las 4 tarjetas de KPI y a las tarjetas de cajero de "Cajeros hoy".
No lo pongas en los paneles grandes de gráficos/listas (mover un panel de media página en hover
se siente mal); solo en tarjetas pequeñas y clicables.

## 5. La pantalla de apariencia sigue en inglés y suelta

`resources/views/pages/settings/⚡appearance.blade.php` sigue con los textos por defecto del starter
("Appearance settings", "Light"/"Dark"/"System").

**Arreglo**: traduce los textos visibles al español, coherente con el resto del sistema
(`Apariencia`, `Actualiza la apariencia de tu cuenta`, `Claro` / `Oscuro` / `Sistema`).
Mantén `flux:radio.group variant="segmented"` con `x-model="$flux.appearance"` — ese es el
mecanismo correcto de Flux, no lo reemplaces por estado propio.

## 6. Vistas móviles / tablet (pendiente completo)

El shell ya es responsive y **no hay que tocarlo**: `layouts/app/sidebar.blade.php` y
`layouts/app/header.blade.php` usan `flux:sidebar collapsible="mobile"` + `flux:sidebar.toggle class="lg:hidden"`,
y la landing y auth ya tienen breakpoints (`sm:`/`lg:`). El problema está en las **vistas de contenido**:
en todo `resources/views/pages/` solo hay 3 usos de `overflow-x-auto`/`sm:hidden`, así que las tablas
y los layouts de columnas se desbordan o se aplastan en teléfono.

Contexto de uso real: el cajero atiende de pie en ventanilla, muchas veces en tablet; el supervisor
revisa desde el teléfono. Prioridad: **`⚡new-charge` (tablet) > tablas de listado (teléfono) > paneles**.

Aplica, vista por vista:

**a) Tablas — nunca comprimir, elegir uno de dos patrones.**
Para tablas anchas (`⚡charges`, `⚡void-requests`, `⚡cashiers`, `admin/⚡users`, `admin/⚡legacy-cashiers`,
`⚡price-lookup`, `⚡catalog`): envuelve la tabla en `<div class="-mx-5 overflow-x-auto px-5">` (el margen
negativo deja que el scroll llegue al borde de la tarjeta) **y** oculta en móvil las columnas secundarias
con `class="max-sm:hidden"` en el `<th>` y su `<td>` — deja siempre visibles identificador, monto y estado.
No reduzcas el tamaño de fuente por debajo de 14px para que quepa.

**b) Grids de columnas — colapsar a una.**
Revisa cada `grid-cols-2` / `grid-cols-3` / `lg:col-span-2` sin prefijo de breakpoint y conviértelo a
`grid-cols-1` con el ancho mayor en `sm:`/`lg:` (el dashboard ya lo hace bien: úsalo como patrón).
Crítico en `⚡session-show`, `⚡charge-show` y `⚡reports`.

**c) `⚡new-charge` (pantalla principal del cajero, 62KB) — la más importante.**
Es un layout de dos paneles (búsqueda de servicios + carrito/total). En móvil debe apilarse:
búsqueda arriba, carrito abajo, y el bloque de total + botón de cobro **fijo al fondo de la ventana**
(`max-lg:sticky max-lg:bottom-0` con fondo `acrilico`), para que el cajero no tenga que hacer scroll
para cobrar. Los `max-h-[36rem] overflow-y-auto` de las listas deben pasar a `max-h-[60vh]` en móvil
para no crear scroll dentro de scroll.

**d) Objetivos táctiles y encabezados.**
Botones y filas clicables: mínimo 44px de alto en móvil (`max-sm:min-h-11`). Las cabeceras de página
con título a la izquierda y acción a la derecha (`flex items-center justify-between`) deben llevar
`flex-wrap gap-3` para que el botón baje de línea en vez de aplastar el título.

**e) El gráfico de 7 días en móvil.**
`⚡reports` ya usa `overflow-x-auto`; aplica lo mismo en el dashboard y da a cada columna un
`min-w-10` para que las 7 barras no se vuelvan hilos en pantalla estrecha.

No introduzcas un layout móvil separado ni componentes duplicados: son las mismas vistas con
breakpoints de Tailwind.

## 7. `⚡new-charge`: usar todo el ancho y mostrar los datos completos del paciente

### 7a. El ancho ya está disponible — la vista se lo quita a sí misma

`layouts/app.blade.php` ya sirve a pantalla completa (`flux:main class="w-full max-w-none! p-4!"`),
así que **no hay que tocar el layout**. El estrangulamiento está dentro de la vista: en
`resources/views/pages/caja/⚡new-charge.blade.php` línea ~793 todo el contenido entra en
`grid grid-cols-1 gap-4 lg:grid-cols-3`, y dentro de esa columna de 1/3 los campos se vuelven a
partir en `sm:grid-cols-2` (línea ~796). Con descripciones de servicio largas eso deja el texto
en columnas de pocos caracteres, y por eso aparecen `line-clamp-2` (línea ~1214) recortando
nombres de servicio que el cajero necesita leer completos.

**Arreglo**:
- Reparte el ancho a favor del trabajo real: panel de búsqueda/servicios ancho y carrito estrecho,
  con `xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]` (o `lg:grid-cols-3` + `lg:col-span-2` en el panel
  de búsqueda). El carrito nunca necesita más de un tercio.
- En pantallas grandes aprovecha el ancho extra: `2xl:grid-cols-[minmax(0,3fr)_minmax(0,1fr)]`.
- Baja el padding interno de las tarjetas de esta vista a `p-4` (en vez de `p-5`/`p-6`) y usa
  `gap-4`; es una pantalla de trabajo, no una página de lectura.
- **Quita `line-clamp-2` de las descripciones de servicio** (línea ~1214) y deja que el texto
  envuelva: `class="text-sm font-medium text-pretty"`. Con el panel ancho ya cabe. Igual criterio
  para cualquier `truncate` sobre descripciones de servicio o nombres de paciente en esta vista
  (en tablas de listado el `truncate` sí puede quedarse).

### 7b. Datos del paciente incompletos

En el buscador (líneas ~128-135) cada resultado se proyecta con solo 6 campos:
`key`, `nombre`, `hc`, `documento`, `edad`, `sexo`. El modelo `App\Models\Sigh\Patient` expone más,
y hoy no se muestran:

- `FechaNacimiento` (cast a `date`) — mostrar la fecha, no solo la edad derivada.
- `documentType` (relación `belongsTo DocumentType` vía `IdDocIdentidad`) — el **tipo** de documento;
  hoy se muestra el número sin decir si es DNI, CE, pasaporte, etc.
- Los componentes del nombre por separado (`ApellidoPaterno`, `ApellidoMaterno`, `PrimerNombre`,
  `SegundoNombre`) — `full_name` ya los concatena y recorta; úsalo para el título, pero ten en
  cuenta que las columnas son `char` de ancho fijo: **cualquier campo crudo que muestres necesita
  `trim()`**, o sale con decenas de espacios.
- `IdPaciente` — identificador SIGH, útil para soporte.

**Arreglo**:
1. Añade esos campos a la proyección del buscador (`fecha_nacimiento`, `tipo_documento`, `id_paciente`),
   con `->with('documentType')` en la consulta para no provocar N+1 por cada resultado.
2. Una vez seleccionado el paciente, muéstralo en una **ficha completa** dentro del panel ancho — no
   en una línea. Un `grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-3 lg:grid-cols-4` con
   pares etiqueta/valor: Nombre completo, Historia clínica, Tipo y número de documento,
   Fecha de nacimiento, Edad, Sexo, Id SIGH. Etiqueta pequeña en `text-zinc-500` sobre el valor.
   El nombre completo puede ocupar toda la fila (`col-span-full` o `sm:col-span-2`).
3. En la lista de resultados de búsqueda, mantén la fila compacta pero completa la identificación:
   nombre en negrita, y debajo `HC {{ hc }} · {{ tipo_documento }} {{ documento }} · {{ edad }} años · {{ sexo }}`.
   Sin `truncate` en el nombre.
4. En el modal de confirmación (`flux:modal name="confirmar-cobro"`, línea ~1244, hoy `max-w-xl` con
   `grid-cols-2`): súbelo a `max-w-3xl` y repite ahí la identificación completa del paciente — es la
   última pantalla antes de emitir el comprobante y es donde un dato equivocado cuesta una anulación.

No cambies la lógica de búsqueda (`scopeSearch`), ni el provisionamiento de historia clínica
(`HistoriaClinicaProvisioner`), ni el cálculo de precios. Es trabajo de presentación únicamente.
La conexión `sigh` es de **solo lectura**: no escribas en `Pacientes`.

---

## Verificación antes de terminar

1. `npm run build` (o `npm run dev`) sin errores de compilación de Tailwind.
2. Panel en tema claro: las tarjetas deben verse translúcidas, con blur del contenido de fondo y
   esquinas de 18px — no blancas planas.
3. Cambia `--color-accent` en `app.css` a un valor de prueba (ej. `#2f8f83`) y recarga: **todas**
   las barras, cifras destacadas y botones primarios deben cambiar de color. Si algo sigue índigo,
   quedó un `indigo-*` hardcodeado. Revierte a `#4c5fd5` al terminar.
4. Alterna claro/oscuro desde el header y desde Ajustes → Apariencia: sin destellos ni texto ilegible.
5. Con "reducir movimiento" activado en el SO, nada debe animarse (ya está cubierto por el bloque
   `@media (prefers-reduced-motion: reduce)`; solo confirma que no rompiste nada).
6. Con el navegador a 390px de ancho (teléfono) recorre panel, comprobantes, nuevo cobro, anulaciones,
   cajeros, reportes y administración: **cero scroll horizontal de la página** (el scroll horizontal
   solo puede existir dentro del contenedor de una tabla o del gráfico), ningún texto cortado y el
   botón de cobro alcanzable sin scroll en `new-charge`. Repite a 820px (tablet).
7. En `new-charge` a 1920px: el panel de búsqueda debe ocupar ~2/3 del ancho, ninguna descripción de
   servicio debe salir recortada con …, y la ficha del paciente seleccionado debe mostrar nombre
   completo, HC, tipo + número de documento, fecha de nacimiento, edad y sexo — sin espacios de
   relleno de las columnas `char` del SIGH.
