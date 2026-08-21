# Handoff: Rediseño UI — Sistema de Caja/Ventas HSJ

## Overview
Rediseño visual del sistema de caja (Laravel + Livewire + Flux UI + Tailwind v4, repo `FreddyxDD/SistemaCajaHSJ`). Cubre: login, panel, turno, nuevo cobro, comprobantes, anulaciones, cajeros, reportes, administración (usuarios + cajeros legado) y perfil, con modo claro/oscuro y un color de marca elegido por el usuario.

## About the design file
`Sistema de Caja HSJ.dc.html` en la raíz de este proyecto es un **prototipo HTML de referencia**, no código para copiar tal cual. La tarea es **recrear este diseño dentro del stack real**: Blade + Livewire + Flux UI (`flux:*` components) + Tailwind CSS v4 (`@theme` tokens en `resources/css/app.css`), reemplazando las vistas Blade existentes (`resources/views/pages/**`) y sus layouts (`resources/views/layouts/app/sidebar.blade.php`, `header.blade.php`). No sirvas el HTML del prototipo directamente.

## Fidelity
**Alta fidelidad** para: color de marca, radios de borde, efecto acrílico/blur, animación de entrada, estructura de tarjetas y tablas. **Media** para tipografía — el prototipo usa Archivo/heading tokens de un design system genérico; el repo real usa **Instrument Sans** (ya configurado vía `bunny('Instrument Sans')` en `vite.config.js`) — mantener Instrument Sans, no introducir Archivo.

## Color de marca elegido
El usuario probó varias opciones y confirmó **`#4c5fd5`** (índigo) como color final de acento.

Mapeo a los tokens reales del repo (`resources/css/app.css`, bloque `@theme`):

```css
@theme {
  --color-accent: #4c5fd5;              /* antes: var(--color-neutral-800) */
  --color-accent-content: #4c5fd5;
  --color-accent-foreground: #ffffff;
}
@layer theme {
  .dark {
    --color-accent: #7b87e6;            /* variante clara del índigo para fondo oscuro, ~62% mezclado a blanco */
    --color-accent-content: #7b87e6;
    --color-accent-foreground: #14171a;
  }
}
```
Esto respeta el mecanismo `@custom-variant dark (&:where(.dark, .dark *))` ya existente — no se toca la clase `.dark`, solo el valor de `--color-accent*`.

## Modo claro / oscuro
El repo ya trae Tailwind `dark:` + clase `.dark` en `<html>` (ver `app.css`, sección print ya contempla `html.dark`). Falta: (1) un toggle visible en el header (icono sol/luna, ver prototipo `toggleTheme`), que alterne la clase `dark` en `<html>` y persista en `localStorage`; (2) exponer el mismo toggle en la pantalla de Perfil, como radio Claro/Oscuro.

## Screens / Views
| Pantalla | Vista Blade real | Notas de layout del rediseño |
| --- | --- | --- |
| Login | (nueva — no existía) | Tarjeta centrada 380px, campo correo institucional + contraseña, botón primario ancho completo |
| Panel | `pages/⚡dashboard.blade.php` | Sin cambios estructurales grandes; aplicar radios/blur/acento nuevos a KPIs y tarjetas |
| Turno | `pages/caja/⚡sessions.blade.php`, `⚡session-show.blade.php` | Tarjeta de apertura/cierre con código de turno, boletas, recaudado |
| Nuevo cobro | `pages/caja/⚡new-charge.blade.php` | Buscador de servicio + carrito + forma de pago (segmented control) |
| Comprobantes | `pages/caja/⚡charges.blade.php`, `⚡charge-show.blade.php` | Tabla + búsqueda; detalle en panel lateral o página |
| Anulaciones | `pages/caja/⚡void-requests.blade.php` | Tabla de solicitudes con tags de estado |
| Cajeros | `pages/caja/⚡cashiers.blade.php` | Supervisión de cajeros/turnos activos |
| Reportes | `pages/caja/⚡reports.blade.php` | Igual estructura, aplicar nuevos tokens |
| Administración | `pages/admin/⚡users.blade.php`, `⚡legacy-cashiers.blade.php` | Tabs (segmented) Usuarios/Cajeros del sistema + diálogo "Nuevo usuario" (nombre, correo, rol por radio) |
| Perfil | (nueva) | Datos personales, seguridad (cambio de contraseña), apariencia (tema claro/oscuro) |

### Componentes y valores exactos (tomados del prototipo)
- **Radios**: `--radius-sm: 6px; --radius-md: 10px; --radius-lg: 18px;` — tarjetas/inputs/botones/tags = `md`, diálogos = `lg`.
- **Acrílico (estilo Windows 11)**:
  - `.card`: `background: color-mix(in srgb, var(--color-surface) 72%, transparent); backdrop-filter: blur(22px) saturate(160%); border: 1px solid color-mix(in srgb, var(--color-text) 8%, transparent);`
  - `.dialog`: mismo patrón con `80%`/`blur(28px)`.
  - `.dialog-backdrop`: `backdrop-filter: blur(3px)`.
  - `.nav`: `background: color-mix(in srgb, var(--color-bg) 75%, transparent); backdrop-filter: blur(20px) saturate(160%); position: sticky; top:0;`
- **Animación de entrada**: `@keyframes win11-in { from { opacity:0; transform: translateY(10px) scale(0.98); } to { opacity:1; transform:none; } }` aplicada a `.card`, `.dialog` y a los hijos directos de `<main>`, `0.4–0.5s cubic-bezier(0.16,1,0.3,1)`.
- **Rampa de acento**: todos los pasos (`100`…`900`) derivan de `--color-accent` con `color-mix()` — en Tailwind v4 esto se puede replicar igual dentro de `@theme` con `color-mix(in srgb, var(--color-accent) X%, white/black)`.

## Interactions & Behavior
- **Toggle de tema**: botón sol/luna en el header, alterna `dark` en `<html>`, persiste en `localStorage`.
- **Menú de usuario**: click en avatar/nombre abre dropdown (Configuración → Perfil, Cerrar sesión); click fuera cierra.
- **Nuevo usuario**: botón abre diálogo modal (backdrop blur 3px + diálogo acrílico); campos nombre/correo + rol (radio Cajero/Supervisor/Administrador); botón "Crear usuario" deshabilitado si nombre o correo vacíos.
- **Perfil**: guardar cambios muestra mensaje de confirmación inline; selector de tema con segmented control.

## State Management
- `theme` ('light'|'dark') — persistir en `localStorage`, aplicar como clase en `<html>`.
- `userMenuOpen` (bool) — estado UI del dropdown.
- `showNewUserDialog` (bool) + campos de formulario del nuevo usuario.
- `profileNombre`, `profileEmail`, `profileSaved` (bool, temporal tras guardar).

## Design Tokens
- **Acento**: `#4c5fd5` (claro) / `#7b87e6` (oscuro, ~62% mezclado a blanco).
- **Radios**: 6 / 10 / 18px (sm/md/lg).
- **Fuente**: Instrument Sans (ya en el repo, no cambiar).
- **Fondo oscuro**: `#14171a` (bg) / `#1e2226` (surface) / texto `#eef1f3`.

## Assets
Ningún asset externo nuevo — solo iconos inline SVG (sol/luna, flecha, usuario, logout) estilo Lucide/outline, ya en el prototipo.

## Files
- `Sistema de Caja HSJ.dc.html` — prototipo de referencia completo (todas las pantallas, tweak de color de marca).
