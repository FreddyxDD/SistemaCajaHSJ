<?php

namespace App\Support\Caja;

/**
 * Color por grupo de forma de pago (Jerarquia_Forma_Pago_MH.fp_padre).
 *
 * El cajero distingue de un vistazo si un comprobante fue CONTADO, SIS, SOAT, etc.,
 * asi que el color debe ser el mismo en toda la aplicacion: turno, comprobantes,
 * cajeros y reportes. Los grupos existentes en la base son CONTADO, SIS, SOAT,
 * CONVENIO, CREDITO y PROGRAMA; cualquier otro cae en el color neutro.
 */
class PaymentMethodPalette
{
    /** @var array<string, array{badge: string, dot: string, bar: string}> */
    private const GROUPS = [
        'CONTADO' => [
            'badge' => 'emerald',
            'dot' => 'bg-emerald-500',
            'bar' => 'bg-emerald-500 dark:bg-emerald-400',
        ],
        'SIS' => [
            'badge' => 'sky',
            'dot' => 'bg-sky-500',
            'bar' => 'bg-sky-500 dark:bg-sky-400',
        ],
        'SOAT' => [
            'badge' => 'amber',
            'dot' => 'bg-amber-500',
            'bar' => 'bg-amber-500 dark:bg-amber-400',
        ],
        'CONVENIO' => [
            'badge' => 'violet',
            'dot' => 'bg-violet-500',
            'bar' => 'bg-violet-500 dark:bg-violet-400',
        ],
        'CREDITO' => [
            'badge' => 'rose',
            'dot' => 'bg-rose-500',
            'bar' => 'bg-rose-500 dark:bg-rose-400',
        ],
        'PROGRAMA' => [
            'badge' => 'teal',
            'dot' => 'bg-teal-500',
            'bar' => 'bg-teal-500 dark:bg-teal-400',
        ],
    ];

    private const FALLBACK = [
        'badge' => 'zinc',
        'dot' => 'bg-zinc-400',
        'bar' => 'bg-zinc-400 dark:bg-zinc-500',
    ];

    /** Nombre de color de Flux para <flux:badge color="..."> */
    public static function badge(?string $group): string
    {
        return self::resolve($group)['badge'];
    }

    /** Clases del punto de color que precede al nombre de la forma de pago. */
    public static function dot(?string $group): string
    {
        return self::resolve($group)['dot'];
    }

    /** Clases de la barra en los cuadres por forma de pago. */
    public static function bar(?string $group): string
    {
        return self::resolve($group)['bar'];
    }

    /** @return array{badge: string, dot: string, bar: string} */
    private static function resolve(?string $group): array
    {
        return self::GROUPS[strtoupper(trim((string) $group))] ?? self::FALLBACK;
    }
}
