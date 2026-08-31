<?php

namespace App\Http\Middleware;

use App\Support\Audit\AccessAudit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Registra la vista que el usuario acaba de abrir.
 *
 * Se anota despues de responder, para no sumar latencia al render, y solo cuando la
 * peticion es realmente una vista: GET, con nombre de ruta y respuesta HTML. Sin esos
 * filtros la tabla se llenaria de assets, de peticiones de Livewire y de redirecciones,
 * y el auditor no podria leer nada.
 */
class RecordPageView
{
    /** Rutas que no aportan nada al rastro y solo hacen ruido. */
    private const RUTAS_IGNORADAS = [
        'livewire.update',
        'livewire.upload-file',
        'livewire.preview-file',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! AccessAudit::tracksNavigation()) {
            return;
        }

        if (! $this->esVista($request, $response)) {
            return;
        }

        $usuario = $request->user();

        // El rastro de navegacion no debe poder tumbar la aplicacion: si falla la
        // escritura se registra el error y la respuesta ya se entrego igual.
        try {
            AccessAudit::view($request, $usuario?->id, $usuario?->name, $response->getStatusCode());
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function esVista(Request $request, Response $response): bool
    {
        if ($request->method() !== 'GET' || $request->ajax()) {
            return false;
        }

        $ruta = $request->route()?->getName();

        if (! $ruta || in_array($ruta, self::RUTAS_IGNORADAS, true)) {
            return false;
        }

        // Redirecciones y descargas no son vistas consultadas.
        $tipo = (string) $response->headers->get('Content-Type');

        return $response->getStatusCode() < 400
            && ! $response->isRedirection()
            && str_contains($tipo, 'text/html');
    }
}
