@props([
    'size' => 'size-8',
])

{{-- Logo institucional del Hospital San José de Chincha.

     Existen dos variantes reales del archivo (texto azul para fondo claro y texto
     blanco para fondo oscuro) y se conmutan por tema con `dark:`. No se aplican
     filtros de color sobre el logo: se cambia el archivo. --}}
<img
    src="{{ asset('img/logo-hospital.png') }}"
    alt="{{ __('Hospital San José de Chincha') }}"
    {{ $attributes->class([$size, 'object-contain dark:hidden']) }}
/>
<img
    src="{{ asset('img/logo-hospital-dark.png') }}"
    alt=""
    aria-hidden="true"
    {{ $attributes->class([$size, 'hidden object-contain dark:block']) }}
/>
