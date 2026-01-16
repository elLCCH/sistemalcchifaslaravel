<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceAllowedHost
{
    /**
     * Restringe el acceso en producción a dominios permitidos.
     *
     * Configuración:
     * - APP_ALLOWED_HOSTS=ifasoruro.edu.bo,www.ifasoruro.edu.bo
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Solo aplicar en production (en local/dev/testing no se valida)
        if (!app()->environment('production')) {
            return $next($request);
        }

        $host = strtolower((string) $request->getHost());

        // Siempre permitir localhost (por si se prueba apuntando a production localmente)
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return $next($request);
        }

        $allowedRaw = (string) env('APP_ALLOWED_HOSTS', 'ifasoruro.edu.bo,www.ifasoruro.edu.bo');
        $allowed = array_values(array_filter(array_map(static function ($h) {
            return strtolower(trim((string) $h));
        }, explode(',', $allowedRaw)), static function ($h) {
            return $h !== '';
        }));

        if (!in_array($host, $allowed, true)) {
            abort(403, 'Dominio no autorizado.');
        }

        return $next($request);
    }
}
