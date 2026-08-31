<?php

namespace App\Support\Audit;

use App\Models\AuditEvent;
use Illuminate\Http\Request;

/**
 * Rastro de accesos: quien entra, desde donde, cuantas veces y que consulta.
 *
 * Se apoya en la tabla `audit_events` que ya existe, que trae justamente los campos
 * que hacen falta (user_id, route_name, url, ip_address, user_agent, status_code,
 * occurred_at) y sus indices por usuario y por fecha. No hace falta una tabla nueva:
 * lo que cambia es el `event_type`, que separa este rastro del de catalogo.
 *
 * Dos tipos de evento:
 *  - `acceso`     ingreso, salida e intento fallido de inicio de sesion.
 *  - `navegacion` cada vista que el usuario abre.
 *
 * La navegacion es de alto volumen: solo se registran peticiones GET con nombre de
 * ruta (las de assets y las de Livewire quedan fuera), y se puede desactivar entera
 * desde configuracion.
 */
class AccessAudit
{
    public const TIPO_ACCESO = 'acceso';

    public const TIPO_NAVEGACION = 'navegacion';

    public const MODULE = 'accesos';

    public const ACCION_INGRESO = 'ingreso';

    public const ACCION_SALIDA = 'salida';

    public const ACCION_FALLIDO = 'intento_fallido';

    public const ACCION_BLOQUEO = 'bloqueo';

    public const ACCION_VISTA = 'vista';

    /** @var array<string, string> Etiqueta legible de cada accion, para el auditor. */
    public const ACCIONES = [
        self::ACCION_INGRESO => 'Inicio de sesión',
        self::ACCION_SALIDA => 'Cierre de sesión',
        self::ACCION_FALLIDO => 'Intento fallido',
        self::ACCION_BLOQUEO => 'Bloqueo por intentos',
        self::ACCION_VISTA => 'Vista consultada',
    ];

    public static function enabled(): bool
    {
        return (bool) config('auditoria.accesos_habilitado', true);
    }

    public static function tracksNavigation(): bool
    {
        return self::enabled() && (bool) config('auditoria.navegacion_habilitada', true);
    }

    /**
     * Registra un evento de sesion (ingreso, salida, intento fallido).
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function session(
        string $action,
        ?int $userId,
        ?string $userName,
        ?string $email,
        Request $request,
        array $metadata = [],
    ): ?AuditEvent {
        if (! self::enabled()) {
            return null;
        }

        return self::write($request, [
            'user_id' => $userId,
            'event_type' => self::TIPO_ACCESO,
            'action' => $action,
            // El nombre y el correo se copian al evento: un usuario puede cambiar de
            // nombre o darse de baja, y el rastro debe seguir siendo legible sin el.
            'metadata' => array_merge([
                'usuario' => $userName,
                'correo' => $email,
                'equipo' => self::hostname($request),
                'navegador' => self::browser($request->userAgent()),
                'sesion' => $request->hasSession() ? $request->session()->getId() : null,
            ], $metadata),
        ]);
    }

    /** Registra la vista que el usuario acaba de abrir. */
    public static function view(Request $request, ?int $userId, ?string $userName, int $statusCode): ?AuditEvent
    {
        if (! self::tracksNavigation()) {
            return null;
        }

        return self::write($request, [
            'user_id' => $userId,
            'event_type' => self::TIPO_NAVEGACION,
            'action' => self::ACCION_VISTA,
            'status_code' => $statusCode,
            'metadata' => [
                'usuario' => $userName,
                'equipo' => self::hostname($request),
                'sesion' => $request->hasSession() ? $request->session()->getId() : null,
            ],
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private static function write(Request $request, array $attributes): AuditEvent
    {
        return AuditEvent::query()->create(array_merge([
            'module' => self::MODULE,
            'route_name' => $request->route()?->getName(),
            'method' => $request->method(),
            'url' => mb_substr($request->fullUrl(), 0, 800),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'occurred_at' => now(),
        ], $attributes));
    }

    /**
     * Nombre del equipo desde el que se conecta, cuando la red lo resuelve. En la red
     * del hospital identifica la ventanilla mejor que la IP, que puede rotar por DHCP.
     * La resolucion inversa puede tardar, asi que es opcional por configuracion.
     */
    private static function hostname(Request $request): ?string
    {
        if (! config('auditoria.resolver_equipo', false)) {
            return null;
        }

        $ip = $request->ip();

        if (! $ip || $ip === '127.0.0.1' || $ip === '::1') {
            return 'local';
        }

        $host = @gethostbyaddr($ip);

        return ($host && $host !== $ip) ? $host : null;
    }

    /** Resumen legible del navegador; el user agent completo queda igual en la fila. */
    private static function browser(?string $userAgent): ?string
    {
        if (! $userAgent) {
            return null;
        }

        return match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => 'Otro',
        };
    }
}
