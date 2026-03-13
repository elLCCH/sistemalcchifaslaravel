<?php

namespace App\Http\Controllers;

use App\Models\ApiIntegrationConfig;
use App\Models\ApiIntegrationLog;
use App\Models\Usuarioslcchs;
use App\Services\IntegrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class IntegrationApiController extends Controller
{
    public function __construct(private IntegrationService $service) {}

    // ─────────────────────────────────────────────────
    // CRUD de configuraciones de integración
    // ─────────────────────────────────────────────────

    /** Listar todas las configuraciones */
    public function configs(Request $request): JsonResponse
    {
        $configs = ApiIntegrationConfig::orderBy('nombre')
            ->get()
            ->map(fn($c) => [
                'id'               => $c->id,
                'instituciones_id' => $c->instituciones_id,
                'nombre'           => $c->nombre,
                'slug'             => $c->slug,
                'base_url'         => $c->base_url,
                'auth_type'        => $c->auth_type,
                'activo'           => $c->activo,
                'auto_sync'        => $c->auto_sync,
                'headers'          => $c->headers,
                'created_at'       => $c->created_at,
                'updated_at'       => $c->updated_at,
            ]);

        return response()->json(['data' => $configs]);
    }

    /** Crear nueva configuración */
    public function createConfig(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nombre'           => 'required|string|max:100',
            'instituciones_id' => 'nullable|integer|exists:instituciones,id',
            'base_url'         => 'nullable|url|max:500',
            'auth_type'        => 'nullable|string|in:bearer_token,api_key,oauth2',
            'headers'          => 'nullable|array',
            'auto_sync'        => 'nullable|boolean',
        ]);

        $data['slug'] = Str::slug($data['nombre']);
        $data['activo'] = false;

        // Asegurar slug único
        $base = $data['slug'];
        $i = 1;
        while (ApiIntegrationConfig::where('slug', $data['slug'])->exists()) {
            $data['slug'] = $base . '-' . $i++;
        }

        $config = ApiIntegrationConfig::create($data);

        return response()->json(['data' => $config, 'message' => 'Configuración creada.'], 201);
    }

    /** Actualizar configuración */
    public function updateConfig(Request $request, int $id): JsonResponse
    {
        $config = ApiIntegrationConfig::findOrFail($id);

        $data = $request->validate([
            'nombre'           => 'sometimes|string|max:100',
            'instituciones_id' => 'nullable|integer|exists:instituciones,id',
            'base_url'         => 'nullable|url|max:500',
            'auth_type'        => 'nullable|string|in:bearer_token,api_key,oauth2',
            'headers'          => 'nullable|array',
            'activo'           => 'nullable|boolean',
            'auto_sync'        => 'nullable|boolean',
        ]);

        $config->update($data);

        return response()->json(['data' => $config, 'message' => 'Configuración actualizada.']);
    }

    /** Guardar credenciales (cifradas) */
    public function setCredentials(Request $request, int $id): JsonResponse
    {
        $config = ApiIntegrationConfig::findOrFail($id);

        $credentials = $request->validate([
            'token'         => 'nullable|string',
            'api_key'       => 'nullable|string',
            'header_name'   => 'nullable|string',
            'client_id'     => 'nullable|string',
            'client_secret' => 'nullable|string',
            'token_url'     => 'nullable|string',
            'scope'         => 'nullable|string',
        ]);

        $config->setCredentials(array_filter($credentials, fn($v) => $v !== null));

        return response()->json(['message' => 'Credenciales guardadas de forma segura.']);
    }

    /** Generar/regenerar webhook secret */
    public function regenerateWebhookSecret(int $id): JsonResponse
    {
        $config = ApiIntegrationConfig::findOrFail($id);
        $config->webhook_secret = Str::random(64);
        $config->save();

        return response()->json([
            'message' => 'Webhook secret regenerado.',
            'webhook_secret' => $config->webhook_secret,
        ]);
    }

    /** Eliminar configuración */
    public function deleteConfig(int $id): JsonResponse
    {
        $config = ApiIntegrationConfig::findOrFail($id);
        $config->delete();

        return response()->json(['message' => 'Configuración eliminada.']);
    }

    // ─────────────────────────────────────────────────
    // CONSULTA DE DATOS (preview antes de enviar)
    // ─────────────────────────────────────────────────

    /** Vista previa de datos de estudiantes para envío */
    public function previewStudentData(Request $request): JsonResponse
    {
        $institucionId = $request->query('instituciones_id') ? (int) $request->query('instituciones_id') : null;
        $data = $this->service->getStudentData($institucionId);

        return response()->json([
            'total' => count($data),
            'preview' => array_slice($data, 0, 5), // Solo 5 para preview
            'campos' => count($data) > 0 ? array_keys((array) $data[0]) : [],
        ]);
    }

    /** Vista previa de cambios diarios */
    public function previewDailyChanges(Request $request): JsonResponse
    {
        $institucionId = $request->query('instituciones_id') ? (int) $request->query('instituciones_id') : null;
        $changes = $this->service->getDailyChanges($institucionId);

        return response()->json($changes);
    }

    /** Vista previa de catálogo (instituciones + carreras) */
    public function previewCatalog(Request $request): JsonResponse
    {
        $institucionId = $request->query('instituciones_id') ? (int) $request->query('instituciones_id') : null;
        $catalog = $this->service->getCatalogData($institucionId);

        return response()->json($catalog);
    }

    // ─────────────────────────────────────────────────
    // ENVÍO DE DATOS AL ENDPOINT EXTERNO
    // ─────────────────────────────────────────────────

    /** Enviar datos de estudiantes al endpoint configurado */
    public function sendStudentData(Request $request, int $configId): JsonResponse
    {
        $config = ApiIntegrationConfig::findOrFail($configId);

        if (!$config->activo) {
            return response()->json(['message' => 'Esta integración está desactivada.'], 422);
        }

        $endpoint = $request->input('endpoint', '/api/students');
        $institucionId = $request->input('instituciones_id') ? (int) $request->input('instituciones_id') : ($config->instituciones_id ?? null);
        $data = $this->service->getStudentData($institucionId);

        $user = $request->user();
        $iniciadoPor = 'manual:' . ($user->Usuario ?? $user->Nombre ?? 'unknown') . '@' . $user->id;

        $log = $this->service->sendToExternal($config, $endpoint, [
            'sistema'     => 'SISTEMALCCHIFAS',
            'timestamp'   => now()->toIso8601String(),
            'estudiantes' => $data,
        ], 'POST', $iniciadoPor);

        return response()->json([
            'message'  => $log->exitoso ? 'Datos enviados correctamente.' : 'Error al enviar datos.',
            'exitoso'  => $log->exitoso,
            'log_id'   => $log->id,
            'status'   => $log->status_code,
            'registros' => count($data),
        ], $log->exitoso ? 200 : 502);
    }

    /** Enviar cambios diarios */
    public function sendDailyChanges(Request $request, int $configId): JsonResponse
    {
        $config = ApiIntegrationConfig::findOrFail($configId);

        if (!$config->activo) {
            return response()->json(['message' => 'Esta integración está desactivada.'], 422);
        }

        $endpoint = $request->input('endpoint', '/api/daily-changes');
        $institucionId = $config->instituciones_id;
        $changes = $this->service->getDailyChanges($institucionId);

        $user = $request->user();
        $iniciadoPor = 'manual:' . ($user->Usuario ?? $user->Nombre ?? 'unknown') . '@' . $user->id;

        $log = $this->service->sendToExternal($config, $endpoint, [
            'sistema'   => 'SISTEMALCCHIFAS',
            'timestamp' => now()->toIso8601String(),
            ...$changes,
        ], 'POST', $iniciadoPor);

        return response()->json([
            'message'  => $log->exitoso ? 'Cambios diarios enviados.' : 'Error al enviar.',
            'exitoso'  => $log->exitoso,
            'log_id'   => $log->id,
            'status'   => $log->status_code,
        ], $log->exitoso ? 200 : 502);
    }

    // ─────────────────────────────────────────────────
    // LOGS DE INTEGRACIÓN
    // ─────────────────────────────────────────────────

    /** Listar logs de una configuración */
    public function logs(Request $request, int $configId): JsonResponse
    {
        $logs = ApiIntegrationLog::where('config_id', $configId)
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($logs);
    }

    /** Todos los logs (admin) */
    public function allLogs(Request $request): JsonResponse
    {
        $logs = ApiIntegrationLog::with('config:id,nombre,slug')
            ->orderByDesc('created_at')
            ->paginate(30);

        return response()->json($logs);
    }

    /** Estadísticas rápidas */
    public function stats(): JsonResponse
    {
        $total = ApiIntegrationConfig::count();
        $activas = ApiIntegrationConfig::where('activo', true)->count();
        $logsHoy = ApiIntegrationLog::whereDate('created_at', today())->count();
        $exitososHoy = ApiIntegrationLog::whereDate('created_at', today())->where('exitoso', true)->count();
        $fallidosHoy = $logsHoy - $exitososHoy;
        $ultimoLog = ApiIntegrationLog::orderByDesc('created_at')->first(['id', 'config_id', 'tipo', 'exitoso', 'created_at']);

        return response()->json([
            'total_configs' => $total,
            'activas'       => $activas,
            'logs_hoy'      => $logsHoy,
            'exitosos_hoy'  => $exitososHoy,
            'fallidos_hoy'  => $fallidosHoy,
            'ultimo_log'    => $ultimoLog,
        ]);
    }

    // ─────────────────────────────────────────────────
    // WEBHOOK RECEPTOR (para que el Ministerio envíe datos)
    // ─────────────────────────────────────────────────

    /** Recibir webhook desde sistema externo */
    public function receiveWebhook(Request $request, string $slug): JsonResponse
    {
        $config = ApiIntegrationConfig::where('slug', $slug)
            ->where('activo', true)
            ->first();

        if (!$config) {
            return response()->json(['message' => 'Integración no encontrada o inactiva.'], 404);
        }

        // Validar secret si está configurado
        if ($config->webhook_secret) {
            $providedSecret = $request->header('X-Webhook-Secret', '');
            if (!hash_equals($config->webhook_secret, $providedSecret)) {
                return response()->json(['message' => 'Secret inválido.'], 403);
            }
        }

        // Registrar log de recepción
        ApiIntegrationLog::create([
            'config_id'       => $config->id,
            'tipo'            => 'WEBHOOK',
            'endpoint'        => $request->fullUrl(),
            'metodo'          => $request->method(),
            'payload_enviado' => $request->all(),
            'status_code'     => 200,
            'exitoso'         => true,
            'iniciado_por'    => 'webhook:' . $slug,
            'registros_enviados' => count($request->input('registros', [])),
        ]);

        return response()->json([
            'message' => 'Webhook recibido correctamente.',
            'received_at' => now()->toIso8601String(),
        ]);
    }

    /** Test de conexión con endpoint externo */
    public function testConnection(int $configId): JsonResponse
    {
        $config = ApiIntegrationConfig::findOrFail($configId);

        if (!$config->base_url) {
            return response()->json(['message' => 'No hay URL base configurada.', 'exitoso' => false], 422);
        }

        $log = $this->service->sendToExternal($config, '/health', [
            'test' => true,
            'timestamp' => now()->toIso8601String(),
            'sistema' => 'SISTEMALCCHIFAS',
        ], 'GET', 'test-connection');

        return response()->json([
            'exitoso'     => $log->exitoso,
            'status_code' => $log->status_code,
            'message'     => $log->exitoso ? 'Conexión exitosa.' : ('Error: ' . ($log->error_mensaje ?? 'Sin respuesta')),
        ]);
    }
}
