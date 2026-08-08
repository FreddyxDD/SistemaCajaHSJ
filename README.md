# Sistema de Gestion de Caja HSJ

Aplicacion web del Hospital San Jose para gestionar turnos de caja, cobros,
comprobantes, anulaciones, reportes y auditoria. Esta construida con Laravel,
Livewire, SQL Server y Vite.

## Base de datos requerida

La aplicacion debe conectarse a **Microsoft SQL Server**. Utiliza cuatro conexiones
logicas, que pueden estar alojadas en la misma instancia o en servidores distintos:

| Conexion Laravel | Base predeterminada | Uso | Acceso |
| --- | --- | --- | --- |
| `sqlsrv` (principal) | `HSJ_Caja` | Migraciones propias, auditoria, notificaciones y solicitudes de anulacion | Lectura/escritura |
| `identity` | `HSJ_Identity` | Usuarios, aplicaciones, roles y permisos centrales | Lectura/escritura controlada |
| `caja` | `SISGESH_BD` | Turnos, catalogo, precios y comprobantes del sistema legado | Lectura/escritura |
| `sigh` | `SIGH` | Historia clinica y datos de pacientes | Solo lectura |

La instancia local usada durante el desarrollo no forma parte del repositorio. Cada
entorno debe copiar `.env.example` a `.env` y definir sus hosts, nombres de base,
usuarios y contrasenas. Nunca se debe versionar el archivo `.env`.

El equipo que ejecute PHP necesita las extensiones `sqlsrv` y `pdo_sqlsrv`, ademas
del controlador ODBC de Microsoft para SQL Server. La cuenta configurada debe tener
los permisos indicados en la tabla anterior.

## Puesta en marcha

Requisitos: PHP compatible con el `composer.json`, Composer, Node.js/npm y acceso a
las cuatro bases SQL Server.

```bash
cp .env.example .env
composer install
php artisan key:generate
npm install
php artisan migrate
php artisan db:seed
npm run build
php artisan serve
```

En Windows PowerShell, el primer comando equivalente es:

```powershell
Copy-Item .env.example .env
```

Antes de migrar o sembrar, revise cuidadosamente que `DB_DATABASE` apunte a
`HSJ_Caja`: las migraciones propias no deben ejecutarse sobre `SISGESH_BD`,
`HSJ_Identity` ni `SIGH`.

La descripcion detallada del esquema legado y del flujo entre bases esta en
[`docs/mapeo-base-datos-caja.md`](docs/mapeo-base-datos-caja.md).

## Verificacion

```bash
composer test
npm run build
```

# Mapeo de base de datos — Módulo de Caja (SISGESH_BD)

Documentación de las tablas de `SISGESH_BD` (esquema legado, conexión Laravel `caja`,
`localhost\SQLEXPRESS02`) que usa o podría usar el módulo de Gestión de Caja, más las
fuentes externas de datos de paciente y personal. Basado en inspección directa del
esquema real (`sys.foreign_keys`, `INFORMATION_SCHEMA.COLUMNS`) y de los datos, no en
documentación previa del sistema legado (no existe).

Convención de esta base: casi todas las columnas de fecha/hora son **texto**
(`varchar` en formato `DD/MM/YYYY` y `HH:MM:SS`), no tipos `date`/`datetime` reales.
No se pueden ordenar ni comparar como texto para rangos — ver `App\Support\Caja\LegacyDate`.

## 1. Flujo general

```
Paciente (SIGH)  ──sincroniza──>  Historia_clinica (SISGESH_BD)
                                          │
Usuario cajero ──abre──> CAJA_APERTURA_CIERRE (turno)
                                          │
Forma de pago (Jerarquia_Forma_Pago_MH) ──┤
Servicio (Nomenclatura_caja_MH) + precio ─┤
según forma de pago (Precio_MH) ──────────┤
                                          ▼
                          Cabecera_documento_MH (venta / boleta)
                                          │
                          Detalle_documento_MH (líneas, 1 por servicio)
                                          │
                          (opcional, no implementado) TRF_FACTURACION_SUNAT_ENVIO
```

## 2. Tablas núcleo de Caja

### `CAJA_APERTURA_CIERRE` — turno de caja

Registra la apertura y cierre de un turno de trabajo de un cajero. No tiene FK hacia
otras tablas (validado en `sys.foreign_keys`).

| Columna | Tipo | Descripción |
|---|---|---|
| `cod_aper_cierre_caja` (PK) | varchar(10) | Correlativo `AP########` |
| `cod_usu` | varchar(7) | Cajero que abrió el turno (FK lógica a `Usuario`, sin constraint declarado aquí) |
| `fecha_apertura` / `hora_apertura` | texto | Apertura |
| `fecha_cierre` / `hora_cierre` | texto | Cierre. `00/00/0000` / `00:00:00` mientras está abierto |
| `estado_aper_cierre_caja` | char(1) | `P` = abierto (pendiente de cierre), `C` = cerrado |

Un cajero solo debería tener **un turno `P` a la vez** — el módulo nuevo lo valida en
`CashSessionController`/página de turno, la BD no lo obliga por sí sola.

### `PERFIL_CAJA_MH` — perfil de punto de cobro

Configuración de cada punto de cobro físico/lógico: qué tipo de documento emite, con
qué forma de pago por defecto, en qué serie.

| Columna | Descripción |
|---|---|
| `cod_perfil_caja` (PK) | Código del perfil |
| `nom_pc_mh` | Nombre del punto de cobro |
| `cod_tipo_documento` | FK → `TIPO_DOCUMENTO_MH` |
| `cod_jerar_forma_pago` | FK → `Jerarquia_Forma_Pago_MH` (forma de pago por defecto) |
| `serie_caja` | Serie de comprobante asociada |

**No usado aún** por el módulo nuevo — el flujo actual usa una serie fija (`999`) y no
lee `PERFIL_CAJA_MH`. Pendiente si se quiere administrar puntos de cobro reales.

### `HORARIO_CAJA_MH` — turnos/horarios de caja

Catálogo de turnos de trabajo de caja (mañana/tarde/noche). No usado aún por el módulo
nuevo.

### `NUMERACION_BOLETA` — correlativo de comprobante por PC

`id_numeracion_boleta`, `nombre_pc`, `serie`, `numero_documento`. En la práctica los
valores observados **no coinciden** con el máximo real de `Cabecera_documento_MH` para
la misma serie (ver memoria del proyecto) — parece ser un contador por estación de
trabajo física del cliente de escritorio antiguo, no una fuente confiable de
correlativo global. El módulo nuevo genera su propio correlativo
(`App\Support\Caja\LegacyIdGenerator::nextDocumentNumber()`) leyendo el máximo real de
`Cabecera_documento_MH.num_documento` para su propia serie (`999`), evitando depender
de esta tabla.

### `Nomenclatura_caja_MH` — catálogo de conceptos facturables

El "catálogo de productos/servicios" del hospital: consultas, procedimientos,
depósitos, etc.

| Columna | Descripción |
|---|---|
| `cod_nomen_caja` (PK) | Código del ítem |
| `descripcion_nomen_tipo` | Descripción larga (lo que ve el cajero) |
| `nomen_caja` | Nombre corto |
| `cod_nivel_servicio_3` | Servicio/área asociada |
| `id_cuenta7` | Cuenta contable |
| `tipo_nomen` | Tipo de concepto |
| `estado_nomenclatura` | Activo/inactivo |

**Importante:** esta tabla **no tiene precio**. El precio real vive en `Precio_MH`.

### `Precio_MH` — precio real por ítem **y** forma de pago

| Columna | Descripción |
|---|---|
| `cod_precio` (PK) | Código de precio |
| `cod_nomen_caja` | FK → `Nomenclatura_caja_MH` |
| `cod_jerar_forma_pago` | FK → `Jerarquia_Forma_Pago_MH` |
| `precio` | Monto |

El mismo servicio tiene **precios distintos según la forma de pago** (ej. una consulta
cuesta diferente para PARTICULAR que para SIS). Por eso en el flujo de cobro se elige
primero la forma de pago y luego se busca en el catálogo: la búsqueda de catálogo en
realidad busca en `Precio_MH` filtrando por `cod_jerar_forma_pago`, no directamente en
`Nomenclatura_caja_MH`.

### `Jerarquia_Forma_Pago_MH` — formas de pago

Jerarquía padre/hijo de formas de pago (42 filas): `CONTADO` (incluye `PARTICULAR`),
`SIS - CREDITOS` (incluye `SIS`, `SOAT` y sus aseguradoras, `CONVENIO` que incluye
`ESSALUD`, `CREDITO HOSPITALARIO`, etc.), `PPR`, `COVID19`, etc.

| Columna | Descripción |
|---|---|
| `cod_jerar_forma_pago` (PK) | Código |
| `nom_forma_pago` | Nombre |
| `relacion_forma_pago` | **Código real del padre en el árbol** (`0` si es raíz de nivel 0) |
| `nivel_forma_pago` | `0` = raíz, `1`/`2` = hijos |
| `fp_padre` | Etiqueta plana de agrupación (`CONTADO`, `SIS`, `SOAT`, `CONVENIO`, `CREDITO`, `PROGRAMA`) — **no es el código del padre**, es una etiqueta de reporte que ya trae cada fila (incluidos los nietos). Se usa para agrupar recaudación en el dashboard/reportes sin recorrer el árbol. |

⚠️ Es fácil confundir `relacion_forma_pago` (FK real) con `fp_padre` (etiqueta de
reporte) — ya se corrigió un bug de esto en `App\Models\Caja\PaymentMethod`.

### `TIPO_DOCUMENTO_MH` — tipos de comprobante

`TD01` RECIBO (sin IGV), `TD02` BOLETA (con IGV), `TD03` FACTURA (con IGV), `TD04`
PROCESOS, `TD05`/`TD06` uso interno (impresión HC / código de barras). El módulo nuevo
usa `TD01` fijo por ahora (sin selector en la UI).

### `Cabecera_documento_MH` — cabecera de la venta/boleta

La tabla central de una venta. **FKs reales** (`sys.foreign_keys`):

| Columna | FK hacia | Nota |
|---|---|---|
| `id_hc` | `Historia_clinica.id_hc` | Ver sección 4 — **no** es directamente `SIGH.Pacientes.NroHistoriaClinica` |
| `cod_tipo_documento` | `TIPO_DOCUMENTO_MH` | |
| `cod_motiv_anu` | `Motivo_Anulacion_MH` | Obligatorio incluso si no está anulado (`MA001` = "SIN ANULACION") |
| `cod_usu` | `Usuario` | Ver sección 5 |

Otras columnas relevantes:

| Columna | Descripción |
|---|---|
| `id_documento` (PK) | Correlativo `CD` + 18 dígitos |
| `serie_documento` / `num_documento` | Serie y número visible del comprobante |
| `cliente` | Nombre del cliente (texto libre, no FK) |
| `cod_jerar_forma_pago` | FK lógica a `Jerarquia_Forma_Pago_MH` (sin constraint declarado) |
| `sub_total_doc` / `igv_doc` / `total_doc` | Montos |
| `estado_doc` | **`S`** = vigente/emitido, **`N`** = anulado (nombres poco intuitivos — confirmado por conteo real: 105,938 `S` vs 369 `N`) |
| `estado_pago` | Estado de pago (`S` observado en la mayoría) |
| `cod_aper_cierre_caja` | FK lógica al turno de caja (sin constraint declarado) |
| `nom_pc` | Nombre del punto de cobro que emitió — el módulo nuevo usa `'GESTIONCAJAHSJ'` como marcador para diferenciarse del cliente de escritorio legado |
| `CORRELATIVO_SUNAT` / `ESTADO_FACTURACION` | Campos para facturación electrónica — **no usados aún** |

### `Detalle_documento_MH` — líneas de la venta

| Columna | FK hacia |
|---|---|
| `id_documento` | `Cabecera_documento_MH` |
| `cod_precio` | `Precio_MH` (no directo a `Nomenclatura_caja_MH`) |

Más: `cantidad_detalle`, `precio_detalle`, `total_detalle`, `fecha_detalle`/`hora_detalle`.

### `Motivo_Anulacion_MH` — catálogo de motivos de anulación

15 filas (`MA001`..`MA015`), ej. `MA001` "SIN ANULACION" (default/no-anulado),
`MA002` "ERROR DIGITACION", `MA006` "NUMERO HC Y DATOS PACIENTE EQUIVOCADOS", etc. El
módulo nuevo hoy solo usa `MA001` al crear y no pide motivo real al anular
(`ChargeDocumentController`/página de detalle) — **pendiente**: pedir motivo real de la
lista al anular en vez de dejarlo implícito.

### `Usuario` / `Tipo_Usuario` — usuarios del sistema legado

`Usuario.cod_usu` (PK, varchar 7) tiene FK real desde `Cabecera_documento_MH.cod_usu` y
(sin FK declarada pero mismo patrón) desde `CAJA_APERTURA_CIERRE.cod_usu`. Columnas:
`cod_tipo` (FK → `Tipo_Usuario`, ej. `T000005` = "CAJA"), `nom_usu`, `usu_sis`
(login), `contraseña` (**texto plano** — legado, no replicar este patrón en nada
nuevo), `estado_usuario`.

El módulo nuevo **no reutiliza usuarios reales** de esta tabla: por cada usuario
central (Laravel/`HSJ_Identity`) que actúa, crea (una sola vez, idempotente) una fila
sintética `W######` vía `App\Support\Caja\LegacyIdGenerator::legacyUserCode()`. Esto
atribuye todas las acciones de un mismo usuario central a un único `cod_usu` legado —
correcto para satisfacer la FK, pero **no** es trazabilidad real por operador a nivel
de la tabla `Usuario`; para eso se debe usar `audit_events` (base propia de la app,
`HSJ_Caja`), que hoy **no está conectado** todavía a las acciones de caja — pendiente.

### `TRF_FACTURACION_SUNAT_ENVIO` — envío a SUNAT (facturación electrónica)

Log de envíos a SUNAT: `NUM_DOCU`, `FEC_ENVI`, `ESTADO_FACTURA_E`, `CODIGO_QR`,
`CODIGO_HASH`, etc. **No implementado en el módulo nuevo** — fuera de alcance del
primer corte (ver plan aprobado). Si se implementa, esta es la tabla objetivo.

## 3. Insumos de caja (flujo secundario, no integrado)

`CAJA_INSUMOS`, `CAJA_INSUMOS_INGRESOS`, `CAJA_INSUMOS_MOVIMIENTOS`,
`CAJA_EXAMENES_INSUMOS`: control de insumos físicos asociados a caja (ej. materiales
para exámenes). Muy poco volumen de datos (1-264 filas) — parece un submódulo poco
usado del sistema legado. **No integrado, pendiente evaluar si hace falta.**

## 4. Origen de datos del paciente

Dos fuentes, **en dos bases distintas**:

1. **`SIGH.Pacientes`** (conexión Laravel `sigh`, hoy `SIGH_202607_LOCAL`) — la fuente
   real de datos del paciente: nombres, apellidos, documento, `NroHistoriaClinica`,
   sexo, fecha de nacimiento. Es de donde el módulo nuevo busca y muestra al paciente
   (`App\Models\Sigh\Patient`).
2. **`SISGESH_BD.Historia_clinica`** (conexión `caja`) — copia local sincronizada
   *desde* SIGH, indexada por su propio `id_hc` (`HC` + 18 dígitos) con una columna
   `IdPaciente` que hace de puente hacia `SIGH.Pacientes.IdPaciente`. Es la tabla que
   `Cabecera_documento_MH.id_hc` **realmente** referencia (FK real). Un paciente que
   existe en SIGH pero **no** tiene fila sincronizada aquí no puede recibir un cobro —
   el módulo nuevo lo detecta y bloquea con un mensaje claro
   (`App\Models\Caja\LegacyHistoriaClinica`, validado en `selectPatient()` de la
   pantalla de nuevo cobro).

No se conoce el mecanismo real de sincronización SIGH → `Historia_clinica` (no hay
acceso al proceso original) — se asume que es un proceso externo al alcance de este
módulo, no algo que este módulo deba replicar.

## 5. Control de personal / asistencia — encontrado, pero **inactivo**

Existe un submódulo completo de control de personal dentro de `SISGESH_BD`, pero al
revisar las fechas reales de los datos, **está descontinuado desde ~2014-2017** (no
hay actividad reciente en ninguna de estas tablas, comparado con el resto de la base
que tiene datos hasta 2026). Documentado tal cual se encontró, marcado como
**pendiente / no recomendado integrar sin confirmar con el hospital** si todavía se usa
por otra vía (otro sistema, u otra base no incluida en este backup).

| Tabla | Filas | Última actividad observada | Propósito |
|---|---|---|---|
| `Personal` | — | — | Maestro de empleados: nombres, DNI, cargo, tipo de trabajador, foto |
| `MARCACION_HISTORIAL` | 621,439 | **31/12/2014** | Log crudo de marcaciones biométricas (huella digital). `datos_marcacion` codifica `DNI-DDMMYYYYHHMMSSx` |
| `DISPOSITIVO` | 3 | 22/10/2014 (alta) | Relojes biométricos físicos (`DT001`-`DT003`, IP `192.168.3.x`, HSJCH) |
| `Huella_digital` | — | — | Plantillas de huella digital enroladas por dispositivo |
| `PERSONAL_EN_DISPOSITIVO` | 751 | — | Qué empleados están enrolados en qué dispositivo |
| `Asistencia_Personal` | **0** | — | Tabla de asistencia procesada (vacía — nunca se usó o se vació) |
| `Turnos_Per` | 4,828 | hasta 2016 | Catálogo de turnos de trabajo del personal (ej. "TARDE GINECOLOGIA IV") |
| `Rol_Per` | 2,243 | hasta 2015 | Rol de pago / planilla mensual por asignación |
| `Rol_Detalle_Actividades` | 39 | — | Detalle de actividades del rol |
| `Asignacion_Per` | 530 | — | Asignación de personal a servicio |
| `Configuracion_Turno_Sistema` | 166 | — | Configuración de turnos |
| `DETALLE_TURNO_REFRIGERIO` | 4,791 | — | Horario de refrigerio por turno |
| `Cargo` | 115 | — | Catálogo de cargos/puestos |
| `Tipo_Trabajador` | 7 | — | Catálogo de tipo de trabajador |

**Conclusión:** el dato existe y el esquema está mapeado arriba por si se decide
retomarlo, pero no se debe construir nada nuevo sobre `MARCACION_HISTORIAL`/`Rol_Per`
como si fueran una fuente viva — primero habría que confirmar con el hospital si el
control de asistencia actual vive en otro sistema.

## 6. Casos de uso — tablas tocadas paso a paso

### Emitir un cobro (venta)

1. Cajero abre turno → INSERT `CAJA_APERTURA_CIERRE` (`estado_aper_cierre_caja='P'`).
   Antes: find-or-create en `Usuario` (código sintético `W######`).
2. Cajero busca paciente → SELECT `SIGH.Pacientes` (otra conexión/BD).
3. Selección de paciente → SELECT `Historia_clinica` por `IdPaciente` (obtiene `id_hc`
   real; bloquea si no existe).
4. Cajero elige forma de pago → SELECT `Jerarquia_Forma_Pago_MH` (lista completa).
5. Cajero busca servicio → SELECT `Precio_MH` JOIN `Nomenclatura_caja_MH`, filtrado por
   `cod_jerar_forma_pago` elegido.
6. Confirmar cobro (transacción):
   - INSERT `Cabecera_documento_MH` (`cod_tipo_documento='TD01'`, `cod_motiv_anu='MA001'`,
     `estado_doc='S'`, `cod_aper_cierre_caja` del turno abierto, `id_hc` de (3),
     `cod_usu` sintético).
   - INSERT `Detalle_documento_MH` (una fila por ítem del carrito, `cod_precio` de (5)).
7. Redirige a detalle del comprobante (SELECT `Cabecera_documento_MH` +
   `Detalle_documento_MH` + `Precio_MH` + `Nomenclatura_caja_MH` + `Jerarquia_Forma_Pago_MH`).

### Anular un cobro

UPDATE `Cabecera_documento_MH.estado_doc = 'N'`. **Pendiente:** hoy no cambia
`cod_motiv_anu` (queda en `MA001`) ni pide un motivo real de `Motivo_Anulacion_MH` —
debería actualizarse para pedir motivo real al anular.

### Cerrar turno

UPDATE `CAJA_APERTURA_CIERRE` (`estado_aper_cierre_caja='C'`, `fecha_cierre`/`hora_cierre`
actuales). No valida hoy que no haya nada pendiente — simplemente cierra.

## 7. Notas para no repetir errores ya corregidos

- Las columnas `fecha_*`/`hora_*` son texto `DD/MM/YYYY`/`HH:MM:SS` — nunca usar
  `ORDER BY`/rangos directos sobre ellas; usar los IDs correlativos (monotónicos) para
  orden cronológico, o `CONVERT(date, columna, 103)` para rangos reales
  (`App\Support\Caja\LegacyDate`).
- `Jerarquia_Forma_Pago_MH.fp_padre` ≠ código del padre real (ver sección 2).
- `Detalle_documento_MH.cod_precio` → `Precio_MH`, **no** directo a
  `Nomenclatura_caja_MH`.
- Antes de escribir en cualquier tabla legada no listada aquí, revisar
  `sys.foreign_keys` primero — varias FKs no son obvias por el nombre de columna.
