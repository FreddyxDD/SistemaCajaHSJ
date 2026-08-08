<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasPermission
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $permission, string $application = 'gestioncajahsj'): Response
    {
        $user = $request->user();

        abort_unless($user && $user->activo, 403);

        if ($user->hasRole('administrador', $application) || $user->hasPermission($permission, $application)) {
            return $next($request);
        }

        abort(403);
    }
}
