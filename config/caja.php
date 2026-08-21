<?php

return [

    /* El selector solo se muestra cuando ambos entornos fueron configurados de
       manera consciente. */
    'environment_switch_enabled' => env('CAJA_DB_SWITCH_ENABLED', false),

    /* Una sesión nueva siempre inicia en el entorno seguro. */
    'default_environment' => env('CAJA_DB_DEFAULT', 'development'),

    /*
    |--------------------------------------------------------------------------
    | Distrito por defecto
    |--------------------------------------------------------------------------
    |
    | Historia_clinica.cod_dis tiene FK hacia Distrito_MH y no admite vacio. Cuando
    | un paciente llega desde SIGH sin distrito, o con un ubigeo que no esta en el
    | catalogo de Caja, se usa este. 110201 es Chincha Alta, el distrito del hospital.
    |
    */

    'distrito_por_defecto' => env('CAJA_DISTRITO_DEFECTO', '110201'),

    /*
    |--------------------------------------------------------------------------
    | Duracion maxima de un turno
    |--------------------------------------------------------------------------
    |
    | Un cajero no debe pasar mas de estas horas en el mismo turno. Superado el
    | limite el turno se marca como pendiente de cierre: se puede seguir cerrando
    | (y cobrando, para no dejar al paciente esperando), pero no se permite abrir
    | otro turno sin cerrar el anterior.
    |
    */

    'turno_horas_maximas' => env('CAJA_TURNO_HORAS_MAX', 12),

];
