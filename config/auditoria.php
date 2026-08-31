<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Rastro de accesos
    |--------------------------------------------------------------------------
    |
    | Registra quien entra, desde donde y cuando: inicios de sesion, cierres e
    | intentos fallidos. Desactivarlo apaga tambien el rastro de navegacion.
    |
    */

    'accesos_habilitado' => env('AUDITORIA_ACCESOS', true),

    /*
    |--------------------------------------------------------------------------
    | Rastro de navegacion
    |--------------------------------------------------------------------------
    |
    | Registra que vista abre cada usuario. Es el de mayor volumen: una fila por
    | pagina consultada. Se puede apagar sin perder el registro de accesos.
    |
    */

    'navegacion_habilitada' => env('AUDITORIA_NAVEGACION', true),

    /*
    |--------------------------------------------------------------------------
    | Resolver el nombre del equipo
    |--------------------------------------------------------------------------
    |
    | En la red del hospital el nombre del equipo identifica la ventanilla mejor
    | que la IP, que rota por DHCP. La resolucion inversa consulta al DNS y puede
    | tardar, asi que viene apagada: enciendela solo si el DNS responde rapido.
    |
    */

    'resolver_equipo' => env('AUDITORIA_RESOLVER_EQUIPO', false),

    /*
    |--------------------------------------------------------------------------
    | Retencion
    |--------------------------------------------------------------------------
    |
    | Dias que se conserva el rastro de navegacion antes de poder purgarlo con
    | `php artisan auditoria:purgar`. Los accesos (ingresos, salidas e intentos
    | fallidos) NO se purgan: son el registro que pide auditoria.
    |
    */

    'navegacion_dias_retencion' => env('AUDITORIA_NAVEGACION_DIAS', 90),

];
