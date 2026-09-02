<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Datos del establecimiento impresos en la boleta
    |--------------------------------------------------------------------------
    |
    | Se toman del formato oficial del hospital. Cambiarlos aqui (o por .env) en
    | vez de editar la plantilla del ticket.
    |
    */

    'hospital' => env('TICKET_HOSPITAL', 'HOSPITAL SAN JOSÉ DE CHINCHA'),
    'unidad' => env('TICKET_UNIDAD', 'Unidad Ejecutora 401 Salud Chincha'),
    'direccion' => env('TICKET_DIRECCION', 'AV. ALVA MAURTUA N° 600'),
    'ruc' => env('TICKET_RUC', '20410275768'),

    /*
    | Logo del hospital para la cabecera del ticket. Ruta relativa a public/.
    | Si el archivo no existe, el ticket imprime solo el nombre del hospital
    | (no se inventa un logo).
    */
    'logo' => env('TICKET_LOGO', 'img/logo-hospital.png'),

    /*
    | Ancho del papel de la ticketera. 80mm es el estandar; usar 58mm si la
    | impresora es de ese formato.
    */
    'ancho_mm' => (int) env('TICKET_ANCHO_MM', 80),

    'pie' => env('TICKET_PIE', 'Gracias por su preferencia'),

];
