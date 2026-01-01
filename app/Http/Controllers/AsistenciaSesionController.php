<?php

namespace App\Http\Controllers;

use App\Models\AsistenciaRegistro;
use App\Models\AsistenciaSesion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AsistenciaSesionController extends Controller
{
    public function index(Request $request)
    {
        $fecha = $request->query('fecha');

        $q = AsistenciaSesion::query();

        if ($fecha) {
            $q->whereDate('fecha', $fecha);
        }

        // Multi-institución: si el token trae instituciones_id en abilities/meta, úsalo.
        $institucionId = $request->user()->instituciones_id ?? null;
        if ($institucionId) {
            $q->where('instituciones_id', $institucionId);
        }

        return response()->json([
            'ok' => true,
            'sesiones' => $q->orderByDesc('hora_ingreso')->limit(200)->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'instituciones_id' => 'required|integer',
            'aulas_virtuales_id' => 'required|integer',
            'fecha' => 'nullable|date',
            'hora_ingreso' => 'nullable|date',
            'tiempo_espera_minutos' => 'nullable|integer|min:1|max:120',
            'gps_requerido' => 'nullable|boolean',
            'radio_metros' => 'nullable|integer|min:1|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json(['ok' => false, 'errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();

        $fecha = $payload['fecha'] ?? date('Y-m-d');
        $horaIngreso = $payload['hora_ingreso'] ?? date('Y-m-d H:i:s');

        $tiempoEspera = $payload['tiempo_espera_minutos'] ?? 10;
        $gpsRequerido = array_key_exists('gps_requerido', $payload) ? (int) $payload['gps_requerido'] : 1;
        $radio = $payload['radio_metros'] ?? 150;

        $docenteId = $request->user()->id ?? null;

        if (!$docenteId) {
            return response()->json(['ok' => false, 'message' => 'No se pudo determinar el docente autenticado.'], 401);
        }

        try {
            $sesion = AsistenciaSesion::create([
                'instituciones_id' => $payload['instituciones_id'],
                'aulas_virtuales_id' => $payload['aulas_virtuales_id'],
                'planteldocentes_id' => $docenteId,
                'fecha' => $fecha,
                'hora_ingreso' => $horaIngreso,
                'tiempo_espera_minutos' => $tiempoEspera,
                'minutos_falta' => 30,
                'gps_requerido' => $gpsRequerido,
                'radio_metros' => $radio,
                'estado' => 'ABIERTA',
                'visibilidad' => 'VISIBLE',
            ]);

            return response()->json(['ok' => true, 'sesion' => $sesion]);
        } catch (\Throwable $e) {
            // Si ya existe la sesión diaria (UNIQUE) devolvemos mensaje amigable.
            $msg = $e->getMessage();
            if (str_contains($msg, 'uq_asist_sesion_diaria') || str_contains($msg, 'Duplicate entry')) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Ya existe una sesión de asistencia para esta aula en esa fecha.',
                ], 409);
            }

            return response()->json(['ok' => false, 'message' => 'Error creando sesión.', 'error' => $msg], 500);
        }
    }

    public function show(Request $request, $id)
    {
        $sesion = AsistenciaSesion::find($id);

        if (!$sesion) {
            return response()->json(['ok' => false, 'message' => 'Sesión no encontrada'], 404);
        }

        return response()->json(['ok' => true, 'sesion' => $sesion]);
    }

    public function registros(Request $request, $id)
    {
        $sesion = AsistenciaSesion::find($id);
        if (!$sesion) {
            return response()->json(['ok' => false, 'message' => 'Sesión no encontrada'], 404);
        }

        $registros = AsistenciaRegistro::query()
            ->where('asistencias_sesiones_id', $sesion->id)
            ->orderByDesc('fecha_registro')
            ->limit(5000)
            ->get();

        return response()->json(['ok' => true, 'sesion' => $sesion, 'registros' => $registros]);
    }

    public function estudiantes(Request $request, $id)
    {
        $sesion = AsistenciaSesion::find($id);
        if (!$sesion) {
            return response()->json(['ok' => false, 'message' => 'Sesión no encontrada'], 404);
        }

        // Listado completo del aula (rol ESTUDIANTE) + su estado en esta sesión (si existe registro)
        $rows = DB::table('aulas_participantes as ap')
            ->join('infoestudiantesifas as ie', 'ie.id', '=', 'ap.infoestudiantesifas_id')
            ->join('estudiantesifas as e', 'e.id', '=', 'ie.estudiantesifas_id')
            ->leftJoin('asistencias_registros as ar', function ($join) use ($sesion) {
                $join->on('ar.infoestudiantesifas_id', '=', 'ie.id')
                    ->where('ar.asistencias_sesiones_id', '=', $sesion->id);
            })
            ->where('ap.aulas_virtuales_id', $sesion->aulas_virtuales_id)
            ->where('ap.rol', 'ESTUDIANTE')
            ->orderBy('e.Ap_Paterno')
            ->orderBy('e.Ap_Materno')
            ->orderBy('e.Nombre')
            ->get([
                'ie.id as infoestudiantesifas_id',
                'ie.estudiantesifas_id as estudiantesifas_id',
                'e.Ap_Paterno as ap_paterno',
                'e.Ap_Materno as ap_materno',
                'e.Nombre as nombre',
                'ar.estado_asistencia as estado_asistencia',
                'ar.metodo as metodo',
                'ar.fecha_registro as fecha_registro',
            ]);

        // Permisos aplicables a la fecha de sesión
        $permisoIds = DB::table('permisos_asistencia')
            ->where('instituciones_id', $sesion->instituciones_id)
            ->whereDate('fecha_inicio', '<=', $sesion->fecha)
            ->whereDate('fecha_fin', '>=', $sesion->fecha)
            ->where(function ($q) use ($sesion) {
                $q->whereNull('aulas_virtuales_id')
                    ->orWhere('aulas_virtuales_id', $sesion->aulas_virtuales_id);
            })
            ->where(function ($q) {
                $q->whereNull('estado')->orWhere('estado', 'ACTIVO');
            })
            ->pluck('infoestudiantesifas_id')
            ->map(fn ($v) => (int) $v)
            ->values();

        $permisoSet = array_fill_keys($permisoIds->all(), true);

        $estudiantes = $rows->map(function ($r) use ($permisoSet) {
            $infoId = (int) $r->infoestudiantesifas_id;
            $tienePermiso = isset($permisoSet[$infoId]);

            return [
                'infoestudiantesifas_id' => $infoId,
                'estudiantesifas_id' => (int) $r->estudiantesifas_id,
                'nombre_completo' => trim(($r->ap_paterno ?? '') . ' ' . ($r->ap_materno ?? '') . ' ' . ($r->nombre ?? '')),
                'estado_asistencia' => $r->estado_asistencia,
                'metodo' => $r->metodo,
                'fecha_registro' => $r->fecha_registro,
                'tiene_permiso' => $tienePermiso,
            ];
        });

        return response()->json([
            'ok' => true,
            'sesion' => $sesion,
            'estudiantes' => $estudiantes,
        ]);
    }

    public function cerrar(Request $request, $id)
    {
        $sesion = AsistenciaSesion::find($id);
        if (!$sesion) {
            return response()->json(['ok' => false, 'message' => 'Sesión no encontrada'], 404);
        }

        if ($sesion->estado === 'CERRADA') {
            return response()->json(['ok' => true, 'sesion' => $sesion, 'message' => 'La sesión ya estaba cerrada.']);
        }

        $result = DB::transaction(function () use ($sesion) {
            $sesion->estado = 'CERRADA';
            $sesion->save();

            // Participantes del aula (estudiantes)
            $participantes = DB::table('aulas_participantes')
                ->where('aulas_virtuales_id', $sesion->aulas_virtuales_id)
                ->where('rol', 'ESTUDIANTE')
                ->get(['infoestudiantesifas_id']);

            $totalMarcados = 0;
            $totalFalta = 0;
            $totalPermiso = 0;

            foreach ($participantes as $p) {
                $estudianteId = $p->infoestudiantesifas_id;
                if (!$estudianteId) {
                    continue;
                }

                $yaRegistrado = AsistenciaRegistro::query()
                    ->where('asistencias_sesiones_id', $sesion->id)
                    ->where('infoestudiantesifas_id', $estudianteId)
                    ->exists();

                if ($yaRegistrado) {
                    $totalMarcados++;
                    continue;
                }

                // ¿Tiene permiso activo para esa fecha y aula?
                $tienePermiso = DB::table('permisos_asistencia')
                    ->where('instituciones_id', $sesion->instituciones_id)
                    ->where('infoestudiantesifas_id', $estudianteId)
                    ->whereDate('fecha_inicio', '<=', $sesion->fecha)
                    ->whereDate('fecha_fin', '>=', $sesion->fecha)
                    ->where(function ($q) use ($sesion) {
                        $q->whereNull('aulas_virtuales_id')
                            ->orWhere('aulas_virtuales_id', $sesion->aulas_virtuales_id);
                    })
                    ->where(function ($q) {
                        $q->whereNull('estado')->orWhere('estado', 'ACTIVO');
                    })
                    ->exists();

                if ($tienePermiso) {
                    AsistenciaRegistro::create([
                        'asistencias_sesiones_id' => $sesion->id,
                        'infoestudiantesifas_id' => $estudianteId,
                        'estado_asistencia' => 'PERMISO',
                        'metodo' => 'SISTEMA',
                        'fecha_registro' => now(),
                        'gps_valido' => 0,
                        'estado' => 'ACTIVO',
                        'visibilidad' => 'VISIBLE',
                        'observacion' => 'Permiso aplicado por secretaría',
                    ]);
                    $totalPermiso++;
                    continue;
                }

                AsistenciaRegistro::create([
                    'asistencias_sesiones_id' => $sesion->id,
                    'infoestudiantesifas_id' => $estudianteId,
                    'estado_asistencia' => 'FALTA',
                    'metodo' => 'SISTEMA',
                    'fecha_registro' => now(),
                    'gps_valido' => 0,
                    'estado' => 'ACTIVO',
                    'visibilidad' => 'VISIBLE',
                    'observacion' => 'Cierre de sesión (no registró asistencia)',
                ]);
                $totalFalta++;
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
