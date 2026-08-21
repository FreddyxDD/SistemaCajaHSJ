<?php

namespace App\Support\Caja;

use App\Models\AuditEvent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Rastro de auditoria del catalogo facturable.
 *
 * El esquema legado no guarda historia: Nomenclatura_caja_MH y Precio_MH solo tienen
 * nom_usu/fecha_actu/hora_actu, es decir, quien toco la fila por ultima vez. El valor
 * anterior se pierde en cada cambio y no hay triggers ni tabla espejo que lo conserve.
 *
 * Costos necesita justificar cada tarifa ante auditoria, asi que cada cambio se
 * registra aqui con su antes y su despues en `audit_events` (base propia del
 * aplicativo). El legado se sigue actualizando igual: esta tabla no lo reemplaza, lo
 * documenta.
 */
class CatalogAudit
{
    public const MODULE = 'catalogo';

    public const ITEM = 'Nomenclatura_caja_MH';

    public const PRECIO = 'Precio_MH';

    public const ACCION_ALTA = 'item.creado';

    public const ACCION_EDICION = 'item.editado';

    public const ACCION_ACTIVADO = 'item.activado';

    public const ACCION_DESACTIVADO = 'item.desactivado';

    public const ACCION_PRECIOS = 'precios.actualizados';

    /** @var array<string, string> Etiqueta legible de cada accion, para el auditor. */
    public const ACCIONES = [
        self::ACCION_ALTA => 'Alta de servicio',
        self::ACCION_EDICION => 'Edición de servicio',
        self::ACCION_ACTIVADO => 'Reactivación',
        self::ACCION_DESACTIVADO => 'Baja (desactivación)',
        self::ACCION_PRECIOS => 'Cambio de precios',
    ];

    /**
     * @param  array<string, mixed>  $old  valores antes del cambio, con etiqueta legible
     * @param  array<string, mixed>  $new  valores despues del cambio
     * @param  array<string, mixed>  $metadata  contexto: descripcion del item, motivo, etc.
     */
    public static function record(
        string $action,
        string $auditableType,
        string $auditableId,
        array $old = [],
        array $new = [],
        array $metadata = [],
    ): AuditEvent {
        $user = Auth::user();

        return AuditEvent::query()->create([
            'user_id' => $user?->id,
            'event_type' => 'catalogo',
            'module' => self::MODULE,
            'action' => $action,
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'route_name' => Request::route()?->getName(),
            'method' => Request::method(),
            'url' => mb_substr((string) Request::fullUrl(), 0, 800),
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            // El nombre se guarda junto al evento: un usuario puede cambiar de nombre
            // o darse de baja, y el rastro tiene que seguir siendo legible sin el.
            'metadata' => array_merge(['usuario' => $user?->name, 'correo' => $user?->email], $metadata) ?: null,
            'occurred_at' => now(),
        ]);
    }

    /**
     * Compara dos mapas de campo => valor y devuelve solo lo que cambio, en la forma
     * [antes, despues]. Sin esto el auditor tendria que leer filas identicas buscando
     * la diferencia a ojo.
     *
     * @param  array<string, mixed>  $antes
     * @param  array<string, mixed>  $despues
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function diff(array $antes, array $despues): array
    {
        $cambiosAntes = [];
        $cambiosDespues = [];

        foreach ($despues as $campo => $valorNuevo) {
            $valorViejo = $antes[$campo] ?? null;

            if ((string) $valorViejo === (string) $valorNuevo) {
                continue;
            }

            $cambiosAntes[$campo] = $valorViejo;
            $cambiosDespues[$campo] = $valorNuevo;
        }

        return [$cambiosAntes, $cambiosDespues];
    }
}
