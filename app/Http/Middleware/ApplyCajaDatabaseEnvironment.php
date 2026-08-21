<?php

namespace App\Http\Middleware;

use App\Support\Caja\CajaDatabaseEnvironment;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApplyCajaDatabaseEnvironment
{
    public function __construct(private readonly CajaDatabaseEnvironment $environments) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->environments->enabled()) {
            $request->session()->forget('caja_database_environment');
        }

        $selected = $this->environments->enabled()
            ? $this->environments->selected($request)
            : $this->environments->default();

        if ($selected !== $this->environments->default() && ! $request->user()?->canDo('users.view')) {
            $selected = $this->environments->default();
            $request->session()->forget('caja_database_environment');
        }

        $this->environments->activate($selected);

        return $next($request);
    }
}
