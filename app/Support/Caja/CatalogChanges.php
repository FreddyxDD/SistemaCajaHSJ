<?php

namespace App\Support\Caja;

use App\Models\AuditEvent;

/**
 * Marcas de "esto cambió hace poco" para el catálogo.
 *
 * Costos ajusta tarifas y renombra servicios sin avisar, y quien atiende se entera
 * frente al paciente: lo que en la manana costaba 50 ahora cuesta 55, o "Hemograma
 * completo" ya no se llama asi. Esto lee el rastro de auditoria y marca el servicio
 * donde se consulta, para que la sorpresa ocurra antes de cobrar.
 *
 * Lo usan tanto la pantalla de cobro como la consulta de precios, por eso vive aqui
 * y no dentro de un componente.
 */
class CatalogChanges
{
    /**
     * @param  string|null  $paymentMethodName  nombre de la forma de pago consultada;
     *                                          los cambios de precio de otras formas de
     *                                          pago no se marcan, porque no afectan a
     *                                          lo que se esta cobrando o cotizando.
     * @return array<string, array{tipo: string, etiqueta: string, detalle: string, fecha: \Illuminate\Support\Carbon, usuario: ?string, servicio: string}>
     */
    public static function recent(?string $paymentMethodName = null): array
    {
        $dias = (int) config('caja.avisos_cambios_dias', 7);

        if ($dias <= 0) {
            return [];
        }

        $formaPago = $paymentMethodName ? trim($paymentMethodName) : null;

        $eventos = AuditEvent::query()
            ->where('module', CatalogAudit::MODULE)
            ->where('occurred_at', '>=', now()->subDays($dias))
            // Ascendente: si un servicio cambio varias veces, la marca que queda es la
            // del cambio mas reciente.
            ->orderBy('occurred_at')
            ->get();

        $marcas = [];

        foreach ($eventos as $evento) {
            $nuevos = $evento->new_values ?? [];
            $viejos = $evento->old_values ?? [];
            $marca = null;

            if ($evento->action === CatalogAudit::ACCION_ALTA) {
                $marca = [
                    'tipo' => 'nuevo',
                    'etiqueta' => 'Nuevo',
                    'detalle' => 'Servicio agregado al catálogo',
                ];
            } elseif ($evento->action === CatalogAudit::ACCION_PRECIOS && $formaPago && array_key_exists($formaPago, $nuevos)) {
                $antes = $viejos[$formaPago] ?? null;
                $despues = $nuevos[$formaPago];

                $marca = [
                    'tipo' => 'precio',
                    'etiqueta' => 'Precio actualizado',
                    'detalle' => $antes === 'sin precio'
                        ? "Ahora se cobra S/ {$despues} con esta forma de pago"
                        : "Antes S/ {$antes} · ahora S/ {$despues}",
                ];
            } elseif ($evento->action === CatalogAudit::ACCION_EDICION && array_key_exists('Descripción', $nuevos)) {
                $marca = [
                    'tipo' => 'nombre',
                    'etiqueta' => 'Cambió de nombre',
                    'detalle' => 'Antes: '.($viejos['Descripción'] ?? '—'),
                ];
            }

            if ($marca) {
                $marcas[$evento->auditable_id] = $marca + [
                    'fecha' => $evento->occurred_at,
                    'usuario' => $evento->metadata['usuario'] ?? null,
                    // El nombre viaja en el evento: si el servicio se renombro, el
                    // resumen debe poder nombrarlo aunque ya no se llame asi.
                    'servicio' => $evento->metadata['servicio'] ?? $evento->auditable_id,
                ];
            }
        }

        return $marcas;
    }
}
