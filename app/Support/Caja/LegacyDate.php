<?php

namespace App\Support\Caja;

use Carbon\CarbonInterface;

/**
 * Los campos fecha_* del esquema legado (SISGESH_BD) son texto "DD/MM/YYYY", no
 * fechas reales — no se pueden comparar ni ordenar como texto. Este helper centraliza
 * la conversion para filtros por rango de fechas en reportes/analitica.
 */
class LegacyDate
{
    /**
     * Tipado contra CarbonInterface (no Carbon) porque AppServiceProvider configura
     * Date::use(CarbonImmutable::class) — now() devuelve CarbonImmutable, no Carbon.
     */
    public static function format(CarbonInterface $date): string
    {
        return $date->format('d/m/Y');
    }

    /**
     * Fragmento SQL Server para convertir una columna de fecha-texto legada a `date`
     * real (estilo 103 = dd/mm/yyyy), para usar en whereRaw/selectRaw/groupBy.
     */
    public static function sqlToDate(string $column): string
    {
        return "CONVERT(date, {$column}, 103)";
    }
}
