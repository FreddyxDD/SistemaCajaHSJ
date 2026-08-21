<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Support\Caja\CajaDatabaseEnvironment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

class CajaEnvironmentController extends Controller
{
    public function __invoke(Request $request, CajaDatabaseEnvironment $environments): RedirectResponse
    {
        abort_unless($environments->enabled(), 404);
        abort_unless($request->user()?->canDo('users.view'), 403);

        $validated = $request->validate([
            'environment' => ['required', 'string', Rule::in($environments->allowed())],
        ]);

        $previous = $environments->selected($request);
        $target = $validated['environment'];

        if ($target === $previous) {
            return back()->with('caja_environment_status', 'La conexión de Caja ya está en '.$environments->label($target).'.');
        }

        try {
            $connection = $environments->activate($target, verify: true);
        } catch (Throwable $exception) {
            $environments->activate($previous);
            report($exception);

            return back()->withErrors([
                'caja_environment' => 'No se pudo conectar al entorno seleccionado. No se realizó el cambio.',
            ]);
        }

        $request->session()->put('caja_database_environment', $target);

        AuditEvent::query()->create([
            'user_id' => $request->user()->id,
            'event_type' => 'security',
            'module' => 'caja',
            'action' => 'database_environment_changed',
            'route_name' => $request->route()?->getName(),
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'old_values' => ['environment' => $previous],
            'new_values' => ['environment' => $target],
            'metadata' => $connection,
            'status_code' => 302,
            'occurred_at' => now(),
        ]);

        return back()->with('caja_environment_status', 'Conexión de Caja cambiada a '.$environments->label($target).'.');
    }
}
