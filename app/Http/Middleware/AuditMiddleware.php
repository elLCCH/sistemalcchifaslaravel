<?php

namespace App\Http\Middleware;

use App\Services\AuditService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AuditMiddleware
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        [$recurso, $recursoId] = $this->inferRecurso($request);

        $oldValues = null;
        if (in_array(strtoupper($request->method()), ['PUT', 'PATCH', 'DELETE'], true) && $recurso && $this->isNumericId($recursoId)) {
            try {
                $row = DB::table($recurso)->where('id', (int) $recursoId)->first();
                $oldValues = $row ? (array) $row : null;
            } catch (\Throwable $e) {
                $oldValues = null;
            }
        }

        $response = $next($request);

        $statusCode = method_exists($response, 'getStatusCode') ? $response->getStatusCode() : null;

        $responseBody = null;
        $newValues = null;

        // Intentar tomar JSON de respuesta
        try {
            $contentType = $response->headers->get('Content-Type');
            if ($contentType && str_contains(strtolower($contentType), 'application/json')) {
                $decoded = json_decode($response->getContent() ?? '', true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $responseBody = is_array($decoded) ? $decoded : null;
                }
            }
        } catch (\Throwable $e) {
            $responseBody = null;
        }

        // AFTER desde BD (si aplica)
        if (in_array(strtoupper($request->method()), ['PUT', 'PATCH'], true) && $recurso && $this->isNumericId($recursoId)) {
            try {
                $row = DB::table($recurso)->where('id', (int) $recursoId)->first();
                $newValues = $row ? (array) $row : null;
            } catch (\Throwable $e) {
                $newValues = null;
            }
        }

        $this->audit->logRequest(
            $request,
            $statusCode,
            null,
            $oldValues,
            $newValues,
            $responseBody
        );

        return $response;
    }

    private function inferRecurso(Request $request): array
    {
        $path = trim($request->path(), '/');
        $segments = explode('/', $path);

        if (count($segments) >= 2 && $segments[0] === 'api') {
            return [$segments[1] ?? null, $segments[2] ?? null];
        }

        return [null, null];
    }

    private function isNumericId(?string $value): bool
    {
        if ($value === null) return false;
        return ctype_digit($value);
    }
}
