<?php

namespace App\Services;

use App\Models\Infoauditoria;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class AuditService
{
    public function logRequest(
        Request $request,
        ?int $statusCode = null,
        ?string $message = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $responseBody = null,
    ): void
    {
        try {
            $accion = $this->mapAccion($request->method());
            if ($accion === null) {
                return;
            }

            // Evitar auditar la propia auditoría (loop)
            $path = ltrim($request->path(), '/');
            if (str_starts_with($path, 'api/infoauditoria')) {
                return;
            }

            $user = $request->user();
            $token = $user?->currentAccessToken();

            [$recurso, $recursoId] = $this->inferRecurso($request);

            $headers = $this->filteredHeaders($request);
            $body = $this->filteredBody($request);

            // Si no se pudo obtener el AFTER desde BD, usar la respuesta JSON como fallback.
            if ($newValues === null && is_array($responseBody) && in_array($accion, ['CREATE', 'UPDATE'], true)) {
                $newValues = $responseBody;
            }

            Infoauditoria::create([
                'actor_type' => $user ? get_class($user) : null,
                'actor_id' => $user?->id,
                'actor_nombrecompleto' => $token?->nombrecompleto,
                'actor_pertenencia' => $token?->pertenencia,
                'actor_permisos' => $token?->permisos,

                'accion' => $accion,
                'recurso' => $recurso,
                'recurso_id' => $recursoId,

                'metodo' => $request->method(),
                'url' => $request->fullUrl(),
                'route_name' => $request->route()?->getName(),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),

                'request_headers' => $headers,
                'request_body' => $body,

                'old_values' => $oldValues,
                'new_values' => $newValues,

                'status_code' => $statusCode,
                'mensaje' => $message,
            ]);
        } catch (\Throwable $e) {
            // Importante: la auditoría nunca debe romper el flujo normal.
        }
    }

    private function mapAccion(string $method): ?string
    {
        return match (strtoupper($method)) {
            'POST' => 'CREATE',
            'PUT', 'PATCH' => 'UPDATE',
            'DELETE' => 'DELETE',
            default => null,
        };
    }

    private function inferRecurso(Request $request): array
    {
        $path = trim($request->path(), '/');

        // Normalmente: api/anios/5
        $segments = explode('/', $path);
        if (count($segments) >= 2 && $segments[0] === 'api') {
            $resource = $segments[1] ?? null;
            $id = $segments[2] ?? null;
            return [$resource, $id];
        }

        return [null, null];
    }

    private function filteredHeaders(Request $request): array
    {
        $headers = [];
        foreach ($request->headers->all() as $key => $values) {
            if (strtolower($key) === 'authorization') {
                continue;
            }
            $headers[$key] = $values;
        }
        return $headers;
    }

    private function filteredBody(Request $request): ?array
    {
        // Evitar subir archivos binarios
        $input = $request->except(['Contrasenia', 'password', 'clave', 'claveActual', 'nuevaClave']);

        // Limpiar UploadedFile
        foreach ($request->files->all() as $key => $file) {
            $input[$key] = '[FILE]';
        }

        // No guardar payload vacío
        if (empty($input)) {
            return null;
        }

        // Limitar campos muy pesados
        $maxKeys = 200;
        $keys = array_keys($input);
        if (count($keys) > $maxKeys) {
            $input = Arr::only($input, array_slice($keys, 0, $maxKeys));
            $input['_truncated'] = true;
        }

        return $input;
    }
}
