<?php

namespace App\Listeners;

use App\Support\Audit\AccessAudit;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;

/**
 * Anota en el rastro de auditoria cada entrada y salida de la aplicacion.
 *
 * Se engancha a los eventos de Laravel en vez de al controlador de login: asi cubre
 * cualquier via de autenticacion (formulario, passkey, recordarme, dos pasos) sin
 * tener que tocar cada una.
 *
 * Los metodos NO se llaman `handle*`: Laravel autodescubre por convencion los
 * metodos con ese prefijo que tipan un evento, y sumados a esta suscripcion
 * explicita cada ingreso quedaba registrado dos veces.
 */
class RecordAccessEvents
{
    public function __construct(private Request $request) {}

    public function onLogin(Login $event): void
    {
        AccessAudit::session(
            AccessAudit::ACCION_INGRESO,
            $event->user->getAuthIdentifier(),
            $event->user->name ?? null,
            $event->user->email ?? null,
            $this->request,
            ['recordado' => $event->remember, 'guard' => $event->guard],
        );
    }

    public function onLogout(Logout $event): void
    {
        AccessAudit::session(
            AccessAudit::ACCION_SALIDA,
            $event->user?->getAuthIdentifier(),
            $event->user->name ?? null,
            $event->user->email ?? null,
            $this->request,
            ['guard' => $event->guard],
        );
    }

    /**
     * Intento fallido: se guarda el correo tecleado, nunca la contrasena. Sirve para
     * distinguir un error de tipeo de alguien probando credenciales.
     */
    public function onFailed(Failed $event): void
    {
        AccessAudit::session(
            AccessAudit::ACCION_FALLIDO,
            $event->user?->getAuthIdentifier(),
            $event->user->name ?? null,
            $event->credentials['email'] ?? null,
            $this->request,
            ['guard' => $event->guard, 'usuario_existe' => $event->user !== null],
        );
    }

    public function onLockout(Lockout $event): void
    {
        AccessAudit::session(
            AccessAudit::ACCION_BLOQUEO,
            null,
            null,
            $event->request->input('email'),
            $this->request,
        );
    }

    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'onLogin',
            Logout::class => 'onLogout',
            Failed::class => 'onFailed',
            Lockout::class => 'onLockout',
        ];
    }
}
