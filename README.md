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
