<?php

namespace App\Services;

use App\Models\ApiIntegrationConfig;
use App\Models\ApiIntegrationLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class IntegrationService
{
    /**
     * Obtener datos de estudiantes matriculados con calificaciones y asistencia.
     * Datos limpios sin información sensible (contraseñas, tokens, etc.)
     */
    public function getStudentData(?int $institucionId = null, ?string $gestion = null): array
    {
        $query = DB::table('infoestudiantesifas as info')
            ->join('estudiantesifas as e', 'info.estudiantesifas_id', '=', 'e.id')
            ->leftJoin('instituciones as inst', 'info.instituciones_id', '=', 'inst.id')
            ->select([
                'info.id as matricula_id',
                'e.Ap_Paterno as apellido_paterno',
                'e.Ap_Materno as apellido_materno',
                'e.Nombre as nombre',
                'e.CI as ci',
                'e.Expedido as ci_expedido',
                'e.Sexo as sexo',
                'e.FechaNac as fecha_nacimiento',
                'info.FechInsc as fecha_inscripcion',
                'info.Matricula as matricula',
                'info.Curso_Solicitado as curso',
                'info.Paralelo_Solicitado as paralelo',
                'info.Turno as turno',
                'info.Categoria as categoria',
                'info.InstrumentoMusical as instrumento',
                'inst.Nombre as institucion',
                'inst.NIT as institucion_nit',
            ]);

        if ($institucionId) {
            $query->where('info.instituciones_id', $institucionId);
        }

        $estudiantes = $query->get()->map(function ($est) {
            $est->calificaciones = $this->getCalificacionesEstudiante((int) $est->matricula_id);
            $est->asistencia_resumen = $this->getAsistenciaResumen((int) $est->matricula_id);
            return $est;
        });

        return $estudiantes->toArray();
    }

    /**
     * Obtener calificaciones de un estudiante (promedio por materia).
     */
    private function getCalificacionesEstudiante(int $infoId): array
    {
        return DB::table('calificaciones as c')
            ->join('materias as m', 'c.materias_id', '=', 'm.id')
            ->join('plandeestudios as pe', 'm.plandeestudios_id', '=', 'pe.id')
            ->where('c.infoestudiantesifas_id', $infoId)
            ->select([
                'pe.NombreMateria as materia',
                'pe.SiglaMateria as sigla',
                'pe.TipoMateria as tipo_materia',
                'c.Teorico1', 'c.Teorico2', 'c.Teorico3', 'c.Teorico4',
                'c.Practico1', 'c.Practico2', 'c.Practico3', 'c.Practico4',
                'c.PromTeorico as promedio_teorico',
                'c.PromPractico as promedio_practico',
                'c.Promedio as promedio_final',
                'c.PruebaRecuperacion as recuperacion',
                'c.EstadoRegistroMateria as estado_registro',
            ])
            ->get()
            ->toArray();
    }

    /**
     * Resumen de asistencia de sesiones de avance de un estudiante.
     */
    private function getAsistenciaResumen(int $infoId): array
    {
        $result = DB::table('sesiones_avance_estudiantil')
            ->where('infoestudiantesifas_id', $infoId)
            ->selectRaw("
                COUNT(*) as total_sesiones,
                SUM(CASE WHEN asistencia = 'P' THEN 1 ELSE 0 END) as presentes,
                SUM(CASE WHEN asistencia = 'A' THEN 1 ELSE 0 END) as atrasos,
                SUM(CASE WHEN asistencia = 'F' THEN 1 ELSE 0 END) as faltas,
                SUM(CASE WHEN asistencia = 'L' THEN 1 ELSE 0 END) as licencias,
                ROUND(AVG(CASE WHEN estrellas > 0 THEN estrellas END), 2) as promedio_estrellas
            ")
            ->first();

        return $result ? (array) $result : [];
    }

    /**
     * Obtener cambios del día (asistencias y sesiones creadas/actualizadas hoy).
     */
    public function getDailyChanges(?int $institucionId = null): array
    {
        $hoy = now()->toDateString();

        $sesionesHoy = DB::table('sesiones_avance_estudiantil as s')
            ->join('infoestudiantesifas as info', 's.infoestudiantesifas_id', '=', 'info.id')
            ->join('estudiantesifas as e', 'info.estudiantesifas_id', '=', 'e.id')
            ->where(function ($q) use ($hoy) {
                $q->whereDate('s.created_at', $hoy)
                  ->orWhereDate('s.updated_at', $hoy);
            })
            ->when($institucionId, fn($q) => $q->where('info.instituciones_id', $institucionId))
            ->select([
                'info.id as matricula_id',
                'e.CI as ci',
                DB::raw("CONCAT(e.Ap_Paterno, ' ', e.Ap_Materno, ' ', e.Nombre) as nombre_completo"),
                's.fecha',
                's.asistencia',
                's.estrellas',
                's.evaluacion',
                's.tipo_asignacion',
                's.created_at',
                's.updated_at',
            ])
            ->orderBy('s.fecha', 'desc')
            ->get()
            ->toArray();

        return [
            'fecha_sync' => $hoy,
            'total_registros' => count($sesionesHoy),
            'registros' => $sesionesHoy,
        ];
    }

    /**
     * Obtener datos de carreras e instituciones para catálogo.
     */
    public function getCatalogData(?int $institucionId = null): array
    {
        $instituciones = DB::table('instituciones')
            ->when($institucionId, fn($q) => $q->where('id', $institucionId))
            ->where('Estado', 'ACTIVO')
            ->select(['id', 'Nombre', 'NIT', 'Direccion', 'Telefono', 'Celular'])
            ->get()
            ->toArray();

        $carreras = DB::table('carreras as c')
            ->join('instituciones as i', 'c.instituciones_id', '=', 'i.id')
            ->when($institucionId, fn($q) => $q->where('c.instituciones_id', $institucionId))
            ->where('c.Estado', 'ACTIVO')
            ->select([
                'c.id', 'c.NombreCarrera as nombre', 'c.Resolucion as resolucion',
                'c.Nivel as nivel', 'c.Modalidad as modalidad', 'c.Duracion as duracion',
                'c.HorasTotales as horas_totales', 'c.TituloOficial as titulo',
                'i.Nombre as institucion',
            ])
            ->get()
            ->toArray();

        return [
            'instituciones' => $instituciones,
            'carreras' => $carreras,
        ];
    }

    /**
     * Enviar datos a un endpoint externo configurado.
     */
    public function sendToExternal(ApiIntegrationConfig $config, string $endpoint, array $data, string $method = 'POST', string $iniciadoPor = 'manual'): ApiIntegrationLog
    {
        $fullUrl = rtrim($config->base_url ?? '', '/') . '/' . ltrim($endpoint, '/');
        $credentials = $config->getCredentials();

        try {
            $request = Http::timeout(30)
                ->withHeaders($config->headers ?? []);

            // Autenticación
            switch ($config->auth_type) {
                case 'bearer_token':
                    $request = $request->withToken($credentials['token'] ?? '');
                    break;
                case 'api_key':
                    $request = $request->withHeaders([
                        ($credentials['header_name'] ?? 'X-API-Key') => $credentials['api_key'] ?? '',
                    ]);
                    break;
                case 'oauth2':
                    $token = $this->getOAuth2Token($config, $credentials);
                    $request = $request->withToken($token);
                    break;
            }

            $response = match (strtoupper($method)) {
                'GET'    => $request->get($fullUrl, $data),
                'POST'   => $request->post($fullUrl, $data),
                'PUT'    => $request->put($fullUrl, $data),
                'DELETE' => $request->delete($fullUrl, $data),
                default  => $request->post($fullUrl, $data),
            };

            $log = ApiIntegrationLog::create([
                'config_id'         => $config->id,
                'tipo'              => 'ENVIO',
                'endpoint'          => $fullUrl,
                'metodo'            => strtoupper($method),
                'payload_enviado'   => $data,
                'status_code'       => $response->status(),
                'respuesta'         => mb_substr($response->body(), 0, 5000),
                'exitoso'           => $response->successful(),
                'error_mensaje'     => $response->successful() ? null : mb_substr($response->body(), 0, 1000),
                'iniciado_por'      => $iniciadoPor,
                'registros_enviados' => count($data['registros'] ?? $data['estudiantes'] ?? []),
            ]);

            if (!$response->successful()) {
                Log::warning("IntegrationService: Error al enviar a {$fullUrl}", [
                    'status' => $response->status(),
                    'body'   => mb_substr($response->body(), 0, 500),
                ]);
            }

            return $log;
        } catch (\Throwable $e) {
            Log::error("IntegrationService: Excepción al enviar a {$fullUrl}", [
                'error' => $e->getMessage(),
            ]);

            return ApiIntegrationLog::create([
                'config_id'       => $config->id,
                'tipo'            => 'ENVIO',
                'endpoint'        => $fullUrl,
                'metodo'          => strtoupper($method),
                'payload_enviado' => $data,
                'status_code'     => null,
                'respuesta'       => null,
                'exitoso'         => false,
                'error_mensaje'   => mb_substr($e->getMessage(), 0, 1000),
                'iniciado_por'    => $iniciadoPor,
                'registros_enviados' => 0,
            ]);
        }
    }

    /**
     * Obtener token OAuth2 (client_credentials grant).
     */
    private function getOAuth2Token(ApiIntegrationConfig $config, array $credentials): string
    {
        $response = Http::asForm()->post($credentials['token_url'] ?? '', [
            'grant_type'    => 'client_credentials',
            'client_id'     => $credentials['client_id'] ?? '',
            'client_secret' => $credentials['client_secret'] ?? '',
            'scope'         => $credentials['scope'] ?? '',
        ]);

        return $response->json('access_token', '');
    }
}
