<?php

namespace App\Http\Controllers;

use App\Models\AsistenciaQrToken;
use App\Models\AsistenciaRegistro;
use App\Models\AsistenciaSesion;
use App\Models\Planteldocentes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AsistenciaQrTokenController extends Controller
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

    private function estudiantesQueryPorSesion(AsistenciaSesion $sesion)
    {
        $row = DB::table('aulas_virtuales as av')
            ->leftJoin('materias as m', 'm.id', '=', 'av.materias_id')
            ->leftJoin('plandeestudios as p', 'p.id', '=', 'm.plandeestudios_id')
            ->where('av.id', (int) $sesion->aulas_virtuales_id)
            ->first([
                'av.materias_id as materia_id',
                'p.ModoMateria as plande_modo_materia',
            ]);

        $materiaId = (int) ($row->materia_id ?? 0);
        if ($materiaId <= 0) {
            return null;
        }

        $q = DB::table('calificaciones as cal')
            ->join('infoestudiantesifas as ie', 'cal.infoestudiantesifas_id', '=', 'ie.id')
            ->join('estudiantesifas as e', 'e.id', '=', 'ie.estudiantesifas_id')
            ->where('cal.materias_id', $materiaId)
            ->where('ie.instituciones_id', (int) $sesion->instituciones_id);

        $modoMateria = (string) ($row->plande_modo_materia ?? '');

        if ($modoMateria === $this->modoInstrumentosEspecialidad) {
            $q->where('ie.planteldocadmins_id', (int) $sesion->planteldocentes_id);
        } elseif ($modoMateria === $this->modoPracticaConjuntos) {
            $q->where('ie.planteldocadmins_idPC', (int) $sesion->planteldocentes_id);
        } elseif ($modoMateria === $this->modoInstrumentoComplementario) {
            $q->where('ie.planteldocadmins_idOtros', (int) $sesion->planteldocentes_id);
        }

        return $q;
    }

    public function create(Request $request, $id)
    {
      try {
        $user = $request->user();
        if (!$user || !($user instanceof Planteldocentes)) {
            return response()->json(['ok' => false, 'message' => 'Solo docentes pueden generar QR.'], 403);
        }

        $sesion = AsistenciaSesion::find($id);
        if (!$sesion) {
            return response()->json(['ok' => false, 'message' => 'Sesión no encontrada'], 404);
        }

        if ((int) $sesion->planteldocentes_id !== (int) $user->id) {
            return response()->json(['ok' => false, 'message' => 'No tienes permiso para generar QR en esta sesión.'], 403);
        }

        if ($sesion->estado !== 'ABIERTA') {
            return response()->json(['ok' => false, 'message' => 'La sesión no está abierta.'], 409);
        }

        if (!is_null($sesion->qr_detenido_at)) {
            return response()->json([
                'ok' => false,
                'message' => 'El QR fue detenido y no se puede reiniciar.',
            ], 409);
        }

        // Permitir QR solo si la materia está configurada con QR
        $row = DB::table('aulas_virtuales as av')
            ->leftJoin('materias as m', 'm.id', '=', 'av.materias_id')
            ->where('av.id', (int) $sesion->aulas_virtuales_id)
            ->first(['m.ModoAsistencia as materia_modo_asistencia']);

        if (!$row) {
            return response()->json(['ok' => false, 'message' => 'No se encontró el aula/materia vinculada a esta sesión.'], 404);
        }

        $modo = $this->normModoAsistencia($row->materia_modo_asistencia ?? null);
        if (!str_contains($modo, 'QR')) {
            return response()->json(['ok' => false, 'message' => 'Esta sesión/materia no usa QR.'], 409);
        }

        $validator = Validator::make($request->all(), [
            'ttl_seconds' => 'nullable|integer|min:2|max:60',
        ]);

        if ($validator->fails()) {
            return response()->json(['ok' => false, 'errors' => $validator->errors()], 422);
        }

        $ttl = (int) ($validator->validated()['ttl_seconds'] ?? 5);

        // Primera vez: al generar el primer token, se considera INICIO del QR (arranca contador)
        // y se alinea hora_ingreso con ese momento.
        if (is_null($sesion->qr_iniciado_at)) {
            $sesion->qr_iniciado_at = now();
            $sesion->hora_ingreso = now();
            $sesion->save();
        }

        // Si pasaron 30 minutos desde hora_ingreso, no generar más tokens.
        $limite = $sesion->hora_ingreso->copy()->addMinutes((int) ($sesion->minutos_falta ?? 30));
        if (now()->greaterThanOrEqualTo($limite)) {
            return response()->json([
                'ok' => false,
                'message' => 'La sesión ya venció (pasaron 30 minutos).',
            ], 409);
        }

        $token = Str::random(64);
        $expiresAt = now()->addSeconds($ttl);

        $row = AsistenciaQrToken::create([
            'asistencias_sesiones_id' => $sesion->id,
            'token' => $token,
            'expires_at' => $expiresAt,
            'created_at' => now(),
        ]);

        return response()->json([
            'ok' => true,
            'qr' => [
                'id' => $row->id,
                'token' => $row->token,
                'expires_at' => $row->expires_at,
            ],
            'sesion' => AsistenciaSesion::find((int) $sesion->id),
        ]);
      } catch (\Throwable $e) {
        //   \Log::error('QR create error', ['id' => $id, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
          return response()->json(['ok' => false, 'message' => 'Error interno: ' . $e->getMessage()], 500);
      }
    }

    public function stop(Request $request, $id)
    {
        $user = $request->user();
        if (!$user || !($user instanceof Planteldocentes)) {
            return response()->json(['ok' => false, 'message' => 'Solo docentes pueden detener QR.'], 403);
        }

        $sesion = AsistenciaSesion::find($id);
        if (!$sesion) {
            return response()->json(['ok' => false, 'message' => 'Sesión no encontrada'], 404);
        }

        if ((int) $sesion->planteldocentes_id !== (int) $user->id) {
            return response()->json(['ok' => false, 'message' => 'No tienes permiso para modificar esta sesión.'], 403);
        }

        if ($sesion->estado !== 'ABIERTA') {
            return response()->json(['ok' => false, 'message' => 'La sesión no está abierta.'], 409);
        }

        // Si nunca se inició, no hay nada que detener.
        if (is_null($sesion->qr_iniciado_at)) {
            return response()->json(['ok' => false, 'message' => 'El QR aún no fue iniciado.'], 409);
        }

        if (!is_null($sesion->qr_detenido_at)) {
            return response()->json(['ok' => true, 'sesion' => $sesion, 'message' => 'El QR ya estaba detenido.']);
        }

        $result = DB::transaction(function () use ($sesion) {
            $sesion->qr_detenido_at = now();
            $sesion->estado = 'CERRADA';
            $sesion->save();

            $base = $this->estudiantesQueryPorSesion($sesion);
            if (!$base) {
                return [
                    'sesion' => $sesion,
                    'total_participantes' => 0,
                    'total_ya_marcados' => 0,
                    'total_permiso' => 0,
                    'total_falta' => 0,
                ];
            }

            $participantes = $base->distinct()->get(['ie.id as infoestudiantesifas_id']);
            $fechaSesion = (string) ($sesion->fecha ?? date('Y-m-d'));

            $totalMarcados = 0;
            $totalFalta = 0;
            $totalPermiso = 0;

            foreach ($participantes as $p) {
                $estudianteId = (int) ($p->infoestudiantesifas_id ?? 0);
                if ($estudianteId <= 0) continue;

                $yaRegistrado = AsistenciaRegistro::query()
                    ->where('asistencias_sesiones_id', (int) $sesion->id)
                    ->where('infoestudiantesifas_id', $estudianteId)
                    ->exists();

                if ($yaRegistrado) {
                    $totalMarcados++;
                    continue;
                }

                // Auto-aplicar licencia si el estudiante tiene una vigente para la fecha
                $tieneLicencia = $this->estudianteTieneLicencia($estudianteId, $fechaSesion);

                if ($tieneLicencia) {
                    AsistenciaRegistro::create([
                        'asistencias_sesiones_id' => (int) $sesion->id,
                        'infoestudiantesifas_id' => $estudianteId,
                        'estado_asistencia' => 'PERMISO',
                        'metodo' => 'SISTEMA',
                        'fecha_registro' => now(),
                        'gps_valido' => 0,
                        'estado' => 'ACTIVO',
                        'visibilidad' => 'VISIBLE',
                        'observacion' => 'Licencia aplicada automáticamente al detener QR',
                    ]);
                    $totalPermiso++;
                } else {
                    AsistenciaRegistro::create([
                        'asistencias_sesiones_id' => (int) $sesion->id,
                        'infoestudiantesifas_id' => $estudianteId,
                        'estado_asistencia' => 'FALTA',
                        'metodo' => 'SISTEMA',
                        'fecha_registro' => now(),
                        'gps_valido' => 0,
                        'estado' => 'ACTIVO',
                        'visibilidad' => 'VISIBLE',
                        'observacion' => 'Cierre por docente (no registró asistencia)',
                    ]);
                    $totalFalta++;
                }
            }

            return [
                'sesion' => $sesion,
                'total_participantes' => count($participantes),
                'total_ya_marcados' => $totalMarcados,
                'total_permiso' => $totalPermiso,
                'total_falta' => $totalFalta,
            ];
        });

        return response()->json(['ok' => true] + $result);
    }
}
