<?php

namespace App\Http\Middleware;

use App\Models\AccessAccount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordIsChanged
{
    /**
     * Obliga a las cuentas provisionadas con una clave temporal a cambiarla antes
     * de acceder a cualquier modulo de la aplicacion.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $account = $user->accessAccount;

        if (! $account instanceof AccessAccount || ! $account->must_change_password) {
            return $next($request);
        }

        if ($request->routeIs('password.temporary.*', 'logout')) {
            return $next($request);
        }

        return redirect()->route('password.temporary.edit');
    }
}
