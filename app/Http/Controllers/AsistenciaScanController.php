<?php

namespace App\Http\Controllers;

use App\Models\AsistenciaQrToken;
use App\Models\AsistenciaRegistro;
use App\Models\AsistenciaSesion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class AsistenciaScanController extends Controller
{
    private string $modoInstrumentosEspecialidad = 'MODO INSTRUMENTOS DE ESPECIALIDAD';
    private string $modoPracticaConjuntos = 'MODO PRÁCTICA DE CONJUNTOS';
    private string $modoInstrumentoComplementario = 'MODO INSTRUMENTO COMPLEMENTARIO';

    private function normModoAsistencia($v): string
    {
        $s = trim((string) ($v ?? ''));
        if ($s === '' || strtolower($s) === 'null' || strtolower($s) === 'undefined') {
            return 'NORMAL';
        }
        return mb_strtoupper($s, 'UTF-8');
    }

    private function licenciasTable(): string
    {
        return Schema::hasTable('licenciasestudiantesifas') ? 'licenciasestudiantesifas' : 'permisos_asistencia';
    }

    private function haversineMeters($lat1, $lon1, $lat2, $lon2)
    {
        $earthRadius = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }

    private function tryParseLatLngFromString(?string $raw): ?array
    {
        $s = trim((string) ($raw ?? ''));
        if ($s === '') return null;

        // Caso simple: "lat,lng"
        if (preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/u', $s, $m)) {
            return [(float) $m[1], (float) $m[2]];
        }

        // URLs típicas de Google Maps: ...@lat,lng,...
        if (preg_match('/@\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)/u', $s, $m)) {
            return [(float) $m[1], (float) $m[2]];
        }

        // query params: q=lat,lng | query=lat,lng | ll=lat,lng
        if (preg_match('/[?&](?:q|query|ll)=\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)/u', $s, $m)) {
            return [(float) $m[1], (float) $m[2]];
        }

        return null;
    }

    private function parseInstitucionLatLng(int $institucionId, ?string $ubicacionGps): ?array
    {
        $base = $this->tryParseLatLngFromString($ubicacionGps);
        if ($base) return $base;

        $s = trim((string) ($ubicacionGps ?? ''));
        if ($s === '') return null;

        // Si parece URL, intenta resolver (incl. maps.app.goo.gl) y re-parsear.
        if (str_starts_with(strtolower($s), 'http://') || str_starts_with(strtolower($s), 'https://')) {
            try {
                // Usar cURL directo para seguir redirects de URLs cortas (maps.app.goo.gl)
                $ch = curl_init($s);
                curl_setopt_array($ch, [
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 8,
                    CURLOPT_NOBODY => false,
                    CURLOPT_USERAGENT => 'Mozilla/5.0',
                    CURLOPT_SSL_VERIFYPEER => false,
                ]);
                $body = curl_exec($ch);
                $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
                curl_close($ch);

                // 1. Intentar con la URL final tras redirects
                if ($finalUrl) {
                    $coords = $this->tryParseLatLngFromString($finalUrl);
                    if ($coords) return $coords;
                }

                // 2. Buscar coords en el body (Google Maps embebe @lat,lng en el HTML)
                if ($body && is_string($body)) {
                    if (preg_match('/@(-?\d+\.\d+),(-?\d+\.\d+)/', $body, $m)) {
                        return [(float) $m[1], (float) $m[2]];
                    }
                    // Fallback: buscar pattern "center=lat,lng" o "ll=lat,lng"
                    if (preg_match('/(?:center|ll|q)=(-?\d+\.\d+)(?:%2C|,)(-?\d+\.\d+)/', $body, $m)) {
                        return [(float) $m[1], (float) $m[2]];
                    }
                }

                // 3. Fallback: intento vía Http facade
                $resp = Http::timeout(6)->get($s);
                $hUrl = $resp->handlerStats()['url'] ?? null;
                $coords = $this->tryParseLatLngFromString($hUrl);
                if ($coords) return $coords;

                $coords2 = $this->tryParseLatLngFromString($resp->body());
                if ($coords2) return $coords2;
            } catch (\Throwable $e) {
                // \Log::warning('parseInstitucionLatLng failed', ['url' => $s, 'error' => $e->getMessage()]);
            }
        }

        return null;
    }

    private function estudiantesQueryPorSesionCtx(AsistenciaSesion $sesion, int $materiaId, ?string $modoMateria)
    {
        $q = DB::table('calificaciones as cal')
            ->join('infoestudiantesifas as ie', 'cal.infoestudiantesifas_id', '=', 'ie.id')
            ->where('cal.materias_id', $materiaId)
            ->where('ie.instituciones_id', (int) $sesion->instituciones_id);

        $modoMateria = (string) ($modoMateria ?? '');
        if ($modoMateria === $this->modoInstrumentosEspecialidad) {
            $q->where('ie.planteldocadmins_id', (int) $sesion->planteldocentes_id);
        } elseif ($modoMateria === $this->modoPracticaConjuntos) {
            $q->where('ie.planteldocadmins_idPC', (int) $sesion->planteldocentes_id);
        } elseif ($modoMateria === $this->modoInstrumentoComplementario) {
            $q->where('ie.planteldocadmins_idOtros', (int) $sesion->planteldocentes_id);
        }

        return $q;
    }

    /**
     * Verifica si un estudiante tiene licencia vigente para la fecha dada.
     */
    private function estudianteTieneLicencia(int $infoestudiantesifasId, string $fecha): bool
    {
        $tabla = $this->licenciasTable();

        return DB::table($tabla)
            ->where('infoestudiantesifas_id', $infoestudiantesifasId)
            ->where('fecha_inicio', '<=', $fecha)
            ->where('fecha_fin', '>=', $fecha)
            ->exists();
    }

    private function closeSessionAndFillMissing(AsistenciaSesion $sesion, int $materiaId, ?string $modoMateria): void
    {
        DB::transaction(function () use ($sesion, $materiaId, $modoMateria) {
            $locked = AsistenciaSesion::query()->where('id', (int) $sesion->id)->lockForUpdate()->first();
            if (!$locked) return;
            if ($locked->estado === 'CERRADA') return;

            $locked->estado = 'CERRADA';
            $locked->save();

            $base = $this->estudiantesQueryPorSesionCtx($locked, $materiaId, $modoMateria);
            $ids = $base->distinct()->pluck('ie.id')->map(fn ($x) => (int) $x)->values();
            if ($ids->count() === 0) return;

            $yaMarcados = AsistenciaRegistro::query()
                ->where('asistencias_sesiones_id', (int) $locked->id)
                ->whereIn('infoestudiantesifas_id', $ids->all())
                ->pluck('infoestudiantesifas_id')
                ->map(fn ($x) => (int) $x)
                ->all();

            $yaSet = array_fill_keys($yaMarcados, true);
            $fechaSesion = (string) ($locked->fecha ?? date('Y-m-d'));

            foreach ($ids as $infoId) {
                $infoId = (int) $infoId;
                if (isset($yaSet[$infoId])) continue;

                // Auto-aplicar licencia si el estudiante tiene una vigente para la fecha
                $tieneLicencia = $this->estudianteTieneLicencia($infoId, $fechaSesion);

                $estado = $tieneLicencia ? 'PERMISO' : 'FALTA';
                $obs = $tieneLicencia
                    ? 'Licencia aplicada automáticamente al cerrar sesión'
                    : 'Cierre automático por vencimiento';

                AsistenciaRegistro::create([
                    'asistencias_sesiones_id' => (int) $locked->id,
                    'infoestudiantesifas_id' => $infoId,
                    'estado_asistencia' => $estado,
                    'metodo' => 'SISTEMA',
                    'fecha_registro' => now(),
                    'gps_valido' => 0,
                    'estado' => 'ACTIVO',
                    'visibilidad' => 'VISIBLE',
                    'observacion' => $obs,
                ]);
            }
        });
    }

    public function scan(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string|min:5',
            'gps_lat' => 'nullable|numeric',
            'gps_lng' => 'nullable|numeric',
            'gps_precision_m' => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json(['ok' => false, 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        $estudiantesifasId = $request->user()->id ?? null;
        if (!$estudiantesifasId) {
            return response()->json(['ok' => false, 'message' => 'No se pudo determinar el estudiante autenticado.'], 401);
        }

        $qr = AsistenciaQrToken::query()
            ->where('token', $data['token'])
            ->first();

        if (!$qr) {
            return response()->json(['ok' => false, 'message' => 'QR inválido.'], 404);
        }

        if (now()->greaterThanOrEqualTo($qr->expires_at)) {
            return response()->json(['ok' => false, 'message' => 'QR vencido.'], 409);
        }

        $sesion = AsistenciaSesion::find($qr->asistencias_sesiones_id);
        if (!$sesion) {
            return response()->json(['ok' => false, 'message' => 'Sesión no encontrada.'], 404);
        }

        // Si ya está cerrada, igual permitimos consultar el resultado (por ejemplo, vencida).
        if ($sesion->estado !== 'ABIERTA' && $sesion->estado !== 'CERRADA') {
            return response()->json(['ok' => false, 'message' => 'La sesión no está abierta.'], 409);
        }

        // Contexto de materia/modos
        $ctx = DB::table('aulas_virtuales as av')
            ->leftJoin('materias as m', 'm.id', '=', 'av.materias_id')
            ->leftJoin('plandeestudios as p', 'p.id', '=', 'm.plandeestudios_id')
            ->where('av.id', (int) $sesion->aulas_virtuales_id)
            ->first([
                'av.materias_id as materia_id',
                'm.ModoAsistencia as materia_modo_asistencia',
                'p.ModoMateria as plande_modo_materia',
            ]);

        $modoAsistencia = $this->normModoAsistencia($ctx->materia_modo_asistencia ?? null);
        if (!str_contains($modoAsistencia, 'QR')) {
            return response()->json(['ok' => false, 'message' => 'Esta materia/sesión no está habilitada para QR.'], 409);
        }

        $materiaId = (int) ($ctx->materia_id ?? 0);
        if ($materiaId <= 0) {
            return response()->json(['ok' => false, 'message' => 'Sesión no vinculada a una materia.'], 409);
        }

        // Mapeo: el usuario autenticado es estudiantesifas.id, pero el aula y los registros
        // trabajan con infoestudiantesifas.id (estudiante inscrito por institución).
        $info = DB::table('infoestudiantesifas')
            ->where('estudiantesifas_id', $estudiantesifasId)
            ->where('instituciones_id', $sesion->instituciones_id)
            ->orderByDesc('id')
            ->first();

        if (!$info) {
            return response()->json([
                'ok' => false,
                'message' => 'No tienes inscripción activa (infoestudiantesifas) para esta institución.',
            ], 403);
        }

        $infoestudiantesifasId = (int) $info->id;

        // Verifica que el estudiante pertenezca a la materia (y al grupo del docente en modos especiales).
        $qPertenece = DB::table('calificaciones as cal')
            ->join('infoestudiantesifas as ie', 'cal.infoestudiantesifas_id', '=', 'ie.id')
            ->where('cal.materias_id', $materiaId)
            ->where('cal.infoestudiantesifas_id', $infoestudiantesifasId)
            ->where('ie.instituciones_id', (int) $sesion->instituciones_id);

        $modoMateria = (string) ($ctx->plande_modo_materia ?? '');
        if ($modoMateria === $this->modoInstrumentosEspecialidad) {
            $qPertenece->where('ie.planteldocadmins_id', (int) $sesion->planteldocentes_id);
        } elseif ($modoMateria === $this->modoPracticaConjuntos) {
            $qPertenece->where('ie.planteldocadmins_idPC', (int) $sesion->planteldocentes_id);
        } elseif ($modoMateria === $this->modoInstrumentoComplementario) {
            $qPertenece->where('ie.planteldocadmins_idOtros', (int) $sesion->planteldocentes_id);
        }

        $esDeLaLista = $qPertenece->exists();
        if (!$esDeLaLista) {
            return response()->json(['ok' => false, 'message' => 'No perteneces a esta materia/sesión.'], 403);
        }

        // Si la sesión ya fue cerrada (por vencimiento u otro), devuelve el registro existente (si lo hay).
        if ($sesion->estado === 'CERRADA') {
            $registroExistente = AsistenciaRegistro::query()
                ->where('asistencias_sesiones_id', (int) $sesion->id)
                ->where('infoestudiantesifas_id', $infoestudiantesifasId)
                ->first();

            if ($registroExistente) {
                return response()->json([
                    'ok' => true,
                    'registro' => $registroExistente,
                    'sesion' => $sesion,
                    'message' => 'Sesión cerrada. Este es tu estado final.',
                ]);
            }
        }

        // Estado por tiempo
        $minutos = now()->diffInMinutes($sesion->hora_ingreso);
        $estado = ($minutos <= (int) $sesion->tiempo_espera_minutos) ? 'PRESENTE' : 'ATRASO';

        // Validación GPS
        $gpsValido = 0;
        $distancia = null;

        if ((int) $sesion->gps_requerido === 1) {
            if (!isset($data['gps_lat']) || !isset($data['gps_lng'])) {
                return response()->json(['ok' => false, 'message' => 'GPS requerido.'], 422);
            }

            $inst = DB::table('instituciones')->where('id', $sesion->instituciones_id)->first();
            $ubic = $inst->UbicacionGps ?? null;
            $coordsInst = $this->parseInstitucionLatLng((int) $sesion->instituciones_id, $ubic);
            if (!$coordsInst) {
                return response()->json([
                    'ok' => false,
                    'message' => 'La institución no tiene UbicacionGps válida. Use "lat,lng" o un link de Google Maps que contenga coordenadas.',
                ], 409);
            }

            [$latInst, $lngInst] = $coordsInst;
            $distancia = $this->haversineMeters((float) $data['gps_lat'], (float) $data['gps_lng'], (float) $latInst, (float) $lngInst);

            if ($distancia <= (float) $sesion->radio_metros) {
                $gpsValido = 1;
            } else {
                return response()->json([
                    'ok' => false,
                    'message' => 'Estás fuera del radio permitido.',
                    'distancia_m' => $distancia,
                    'radio_m' => (int) $sesion->radio_metros,
                ], 403);
            }
        }

        // Si venció el tiempo (30 min), registrar como FALTA/PERMISO y cerrar la sesión automáticamente.
        $limite = $sesion->hora_ingreso->copy()->addMinutes($sesion->minutos_falta ?? 30);
        if (now()->greaterThanOrEqualTo($limite)) {
            // Cierra + completa faltas/permiso (incluye a este estudiante).
            $this->closeSessionAndFillMissing($sesion, $materiaId, $ctx->plande_modo_materia ?? null);

            $registroFinal = AsistenciaRegistro::query()
                ->where('asistencias_sesiones_id', (int) $sesion->id)
                ->where('infoestudiantesifas_id', $infoestudiantesifasId)
                ->first();

            if ($registroFinal) {
                return response()->json([
                    'ok' => true,
                    'registro' => $registroFinal,
                    'sesion' => AsistenciaSesion::find((int) $sesion->id),
                    'message' => 'La sesión ya venció. Se registró tu estado final.',
                ]);
            }

            return response()->json([
                'ok' => false,
                'message' => 'La sesión ya venció. No se pudo registrar tu estado final.',
            ], 409);
        }

        // Insert único por sesión+estudiante
        try {
            $registro = AsistenciaRegistro::create([
                'asistencias_sesiones_id' => $sesion->id,
                'infoestudiantesifas_id' => $infoestudiantesifasId,
                'estado_asistencia' => $estado,
                'metodo' => 'QR',
                'fecha_registro' => now(),
                'asistencias_qr_tokens_id' => $qr->id,
                'gps_lat' => $data['gps_lat'] ?? null,
                'gps_lng' => $data['gps_lng'] ?? null,
                'gps_precision_m' => $data['gps_precision_m'] ?? null,
                'gps_distancia_m' => $distancia,
                'gps_valido' => $gpsValido,
                'estado' => 'ACTIVO',
                'visibilidad' => 'VISIBLE',
            ]);

            // Invalidar el token inmediatamente (uso único) para que nadie más pueda
            // reutilizarlo durante la ventana de TTL.
            $qr->expires_at = now()->subSecond();
            $qr->save();

            return response()->json([
                'ok' => true,
                'registro' => $registro,
                'sesion' => $sesion,
            ]);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'uq_asist_reg_unica') || str_contains($msg, 'Duplicate entry')) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Ya registraste tu asistencia para esta sesión.',
                ], 409);
            }

            return response()->json(['ok' => false, 'message' => 'Error registrando asistencia.', 'error' => $msg], 500);
        }
    }
}
