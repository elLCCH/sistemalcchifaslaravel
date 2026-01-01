<?php

namespace App\Http\Controllers;

use App\Models\AsistenciaQrToken;
use App\Models\AsistenciaRegistro;
use App\Models\AsistenciaSesion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AsistenciaScanController extends Controller
{
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

        if ($sesion->estado !== 'ABIERTA') {
            return response()->json(['ok' => false, 'message' => 'La sesión no está abierta.'], 409);
        }

        $limite = $sesion->hora_ingreso->copy()->addMinutes($sesion->minutos_falta ?? 30);
        if (now()->greaterThanOrEqualTo($limite)) {
            return response()->json(['ok' => false, 'message' => 'La sesión ya venció (pasaron 30 minutos).'], 409);
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

        // Verifica que el estudiante pertenezca al aula.
        $esParticipante = DB::table('aulas_participantes')
            ->where('aulas_virtuales_id', $sesion->aulas_virtuales_id)
            ->where('rol', 'ESTUDIANTE')
            ->where('infoestudiantesifas_id', $infoestudiantesifasId)
            ->exists();

        if (!$esParticipante) {
            return response()->json(['ok' => false, 'message' => 'No perteneces a esta aula.'], 403);
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
            if (!$ubic || !str_contains($ubic, ',')) {
                return response()->json(['ok' => false, 'message' => 'La institución no tiene UbicacionGps válida (lat,lng).'], 409);
            }

            [$latInst, $lngInst] = array_map('trim', explode(',', $ubic));
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
