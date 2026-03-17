<?php

namespace App\Http\Controllers;

use App\Models\Licenciasestudiantesifas;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;

class LicenciasestudiantesifasController extends Controller
{
    public function __construct()
    {
        // $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }
    public function index(Request $request)
    {
        $q = Licenciasestudiantesifas::query();

        $institucionId = $request->user()->instituciones_id ?? null;
        if (!$institucionId) {
            return response()->json(['ok' => false, 'message' => 'No se pudo determinar la institución del usuario.'], 409);
        }

        $q->where('licenciasestudiantesifas.instituciones_id', (int) $institucionId);

        if ($request->query('anios_id')) {
            $q->where('licenciasestudiantesifas.anios_id', (int) $request->query('anios_id'));
        }

        if ($request->query('infoestudiantesifas_id')) {
            $q->where('licenciasestudiantesifas.infoestudiantesifas_id', (int) $request->query('infoestudiantesifas_id'));
        }

        // Para que secretaría vea datos legibles en la lista.
        $q->leftJoin('infoestudiantesifas as ie', 'ie.id', '=', 'licenciasestudiantesifas.infoestudiantesifas_id')
            ->leftJoin('estudiantesifas as e', 'e.id', '=', 'ie.estudiantesifas_id')
            ->select([
                'licenciasestudiantesifas.*',
                DB::raw("e.CI as estudiante_ci"),
                DB::raw("e.Ap_Paterno as estudiante_ap_paterno"),
                DB::raw("e.Ap_Materno as estudiante_ap_materno"),
                DB::raw("e.Nombre as estudiante_nombre"),
            ]);

        return response()->json([
            'ok' => true,
            'licencias' => $q
                ->orderByDesc('licenciasestudiantesifas.fecha_inicio')
                ->orderByDesc('licenciasestudiantesifas.fecha_fin')
                ->orderByDesc('licenciasestudiantesifas.id')
                ->limit(500)
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'anios_id' => 'required|integer|min:1',
            'infoestudiantesifas_id' => 'required|integer',
            'fecha_inicio' => 'required|date',
            'fecha_fin' => 'required|date',
            'motivo' => 'nullable|string|max:255',
            'registrado_por' => 'nullable|string|max:80',
        ]);

        if ($validator->fails()) {
            return response()->json(['ok' => false, 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        $institucionId = $request->user()->instituciones_id ?? null;
        if (!$institucionId) {
            return response()->json(['ok' => false, 'message' => 'No se pudo determinar la institución del usuario.'], 409);
        }

        if (strtotime($data['fecha_fin']) < strtotime($data['fecha_inicio'])) {
            return response()->json(['ok' => false, 'message' => 'Rango de fechas inválido.'], 422);
        }

        // Validar que la gestión/año exista. No forzamos formato (Anio es VARCHAR(11)).
        $anioRow = DB::table('anios')->where('id', (int) $data['anios_id'])->first(['id', 'Anio']);
        if (!$anioRow) {
            return response()->json(['ok' => false, 'message' => 'Gestión/año inválido.'], 422);
        }

        $payload = [
            'instituciones_id' => (int) $institucionId,
            'anios_id' => (int) $data['anios_id'],
            'infoestudiantesifas_id' => (int) $data['infoestudiantesifas_id'],
            'fecha_inicio' => $data['fecha_inicio'],
            'fecha_fin' => $data['fecha_fin'],
            'motivo' => $data['motivo'] ?? null,
            'registrado_por' => $data['registrado_por'] ?? null,
            'estado' => 'ACTIVO',
            'visibilidad' => 'VISIBLE',
        ];

        // Importante: crear licencia NO debe forzar PERMISO automáticamente.
        // La sincronización contra asistencias se hace con el botón "Aplicar".
        $licencia = Licenciasestudiantesifas::create($payload);

        return response()->json(['ok' => true, 'licencia' => $licencia]);
    }

    private function aplicarLicenciaEnAsistencias(Licenciasestudiantesifas $lic): array
    {
        $stats = [
            'sesiones_revisadas' => 0,
            'registros_insertados' => 0,
            'registros_actualizados' => 0,
        ];

        $sesiones = DB::table('asistencias_sesiones')
            ->where('instituciones_id', (int) $lic->instituciones_id)
            ->whereDate('fecha', '>=', $lic->fecha_inicio)
            ->whereDate('fecha', '<=', $lic->fecha_fin)
            ->orderBy('fecha')
            ->limit(4000)
            ->get(['id', 'aulas_virtuales_id', 'planteldocentes_id', 'fecha', 'instituciones_id']);

        foreach ($sesiones as $s) {
            $stats['sesiones_revisadas']++;

            $sesionId = (int) ($s->id ?? 0);
            if ($sesionId <= 0) continue;

            // Validar pertenencia del estudiante a la lista de esa sesión (respeta modos especiales)
            $row = DB::table('aulas_virtuales as av')
                ->leftJoin('materias as m', 'm.id', '=', 'av.materias_id')
                ->leftJoin('plandeestudios as p', 'p.id', '=', 'm.plandeestudios_id')
                ->where('av.id', (int) $s->aulas_virtuales_id)
                ->first([
                    'av.materias_id as materia_id',
                    'p.ModoMateria as plande_modo_materia',
                ]);

            $materiaId = (int) ($row->materia_id ?? 0);
            if ($materiaId <= 0) continue;

            $modoMateria = (string) ($row->plande_modo_materia ?? '');

            $base = DB::table('calificaciones as cal')
                ->join('infoestudiantesifas as ie', 'cal.infoestudiantesifas_id', '=', 'ie.id')
                ->where('cal.materias_id', $materiaId)
                ->where('ie.instituciones_id', (int) $s->instituciones_id)
                ->where('ie.id', (int) $lic->infoestudiantesifas_id);

            if ($modoMateria === 'MODO INSTRUMENTOS DE ESPECIALIDAD') {
                $base->where('ie.planteldocadmins_id', (int) $s->planteldocentes_id);
            } elseif ($modoMateria === 'MODO PRÁCTICA DE CONJUNTOS') {
                $base->where('ie.planteldocadmins_idPC', (int) $s->planteldocentes_id);
            } elseif ($modoMateria === 'MODO INSTRUMENTO COMPLEMENTARIO') {
                $base->where('ie.planteldocadmins_idOtros', (int) $s->planteldocentes_id);
            }

            if (!$base->exists()) continue;

            $reg = DB::table('asistencias_registros')
                ->where('asistencias_sesiones_id', $sesionId)
                ->where('infoestudiantesifas_id', (int) $lic->infoestudiantesifas_id)
                ->first(['id', 'estado_asistencia']);

            if (!$reg) {
                DB::table('asistencias_registros')->insert([
                    'asistencias_sesiones_id' => $sesionId,
                    'infoestudiantesifas_id' => (int) $lic->infoestudiantesifas_id,
                    'estado_asistencia' => 'PERMISO',
                    'metodo' => 'SISTEMA',
                    'fecha_registro' => now(),
                    'gps_valido' => 0,
                    'estado' => 'ACTIVO',
                    'visibilidad' => 'VISIBLE',
                    'observacion' => 'Licencia aplicada por secretaría',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $stats['registros_insertados']++;
                continue;
            }

            $estado = strtoupper((string) ($reg->estado_asistencia ?? ''));
            if ($estado === 'FALTA') {
                DB::table('asistencias_registros')
                    ->where('id', (int) $reg->id)
                    ->update([
                        'estado_asistencia' => 'PERMISO',
                        'metodo' => 'SISTEMA',
                        'fecha_registro' => now(),
                        'observacion' => 'Licencia aplicada por secretaría',
                        'updated_at' => now(),
                    ]);
                $stats['registros_actualizados']++;
            }
        }

        return $stats;
    }

    public function aplicar(Request $request, $id)
    {
        $licencia = Licenciasestudiantesifas::find($id);
        if (!$licencia) {
            return response()->json(['ok' => false, 'message' => 'Licencia no encontrada'], 404);
        }

        $institucionId = $request->user()->instituciones_id ?? null;
        if (!$institucionId) {
            return response()->json(['ok' => false, 'message' => 'No se pudo determinar la institución del usuario.'], 409);
        }

        if ((int) $licencia->instituciones_id !== (int) $institucionId) {
            return response()->json(['ok' => false, 'message' => 'No tienes permiso para aplicar esta licencia.'], 403);
        }

        $estadoLic = strtoupper(trim((string) ($licencia->estado ?? 'ACTIVO')));
        if ($estadoLic !== '' && $estadoLic !== 'ACTIVO') {
            return response()->json(['ok' => false, 'message' => 'La licencia no está ACTIVA.'], 409);
        }

        $stats = DB::transaction(function () use ($licencia) {
            return $this->aplicarLicenciaEnAsistencias($licencia);
        });

        return response()->json(['ok' => true, 'licencia' => $licencia, 'stats' => $stats]);
    }

    public function buscarEstudiantes(Request $request)
    {
        $institucionId = $request->user()->instituciones_id ?? null;
        if (!$institucionId) {
            return response()->json(['ok' => false, 'message' => 'No se pudo determinar la institución del usuario.'], 409);
        }

        $q = trim((string) $request->query('q', ''));
        if ($q === '') {
            return response()->json(['ok' => true, 'estudiantes' => []]);
        }

        $needle = mb_strtoupper($q, 'UTF-8');
        $needleLike = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $needle) . '%';

        $rows = DB::table('infoestudiantesifas as ie')
            ->join('estudiantesifas as e', 'e.id', '=', 'ie.estudiantesifas_id')
            ->where('ie.instituciones_id', (int) $institucionId)
            ->where(function ($w) use ($needleLike) {
                $w->whereRaw('UPPER(e.CI) LIKE ?', [$needleLike])
                    ->orWhereRaw('UPPER(e.Ap_Paterno) LIKE ?', [$needleLike])
                    ->orWhereRaw('UPPER(e.Ap_Materno) LIKE ?', [$needleLike])
                    ->orWhereRaw('UPPER(e.Nombre) LIKE ?', [$needleLike]);
            })
            ->orderBy('e.Ap_Paterno')
            ->orderBy('e.Ap_Materno')
            ->orderBy('e.Nombre')
            ->limit(30)
            ->get([
                'ie.id as infoestudiantesifas_id',
                'e.id as estudiantesifas_id',
                'e.CI as ci',
                'e.Ap_Paterno as ap_paterno',
                'e.Ap_Materno as ap_materno',
                'e.Nombre as nombre',
            ]);

        $out = $rows->map(function ($r) {
            $parts = array_filter([
                trim((string) ($r->ap_paterno ?? '')),
                trim((string) ($r->ap_materno ?? '')),
                trim((string) ($r->nombre ?? '')),
            ], fn ($x) => $x !== '');

            return [
                'infoestudiantesifas_id' => (int) $r->infoestudiantesifas_id,
                'estudiantesifas_id' => (int) $r->estudiantesifas_id,
                'ci' => (string) ($r->ci ?? ''),
                'nombre_completo' => trim(implode(' ', $parts)),
            ];
        });

        return response()->json(['ok' => true, 'estudiantes' => $out]);
    }

    public function update(Request $request, $id)
    {
        $licencia = Licenciasestudiantesifas::find($id);
        if (!$licencia) {
            return response()->json(['ok' => false, 'message' => 'Licencia no encontrada'], 404);
        }

        $institucionId = $request->user()->instituciones_id ?? null;
        if (!$institucionId) {
            return response()->json(['ok' => false, 'message' => 'No se pudo determinar la institución del usuario.'], 409);
        }

        if ((int) $licencia->instituciones_id !== (int) $institucionId) {
            return response()->json(['ok' => false, 'message' => 'No tienes permiso para modificar esta licencia.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'fecha_inicio' => 'sometimes|date',
            'fecha_fin' => 'sometimes|date',
            'motivo' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['ok' => false, 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        if (isset($data['fecha_inicio']) && isset($data['fecha_fin'])) {
            if (strtotime($data['fecha_fin']) < strtotime($data['fecha_inicio'])) {
                return response()->json(['ok' => false, 'message' => 'Rango de fechas inválido.'], 422);
            }
        }

        $licencia->update($data);

        return response()->json(['ok' => true, 'licencia' => $licencia]);
    }

    public function destroy(Request $request, $id)
    {
        $licencia = Licenciasestudiantesifas::find($id);

        if (!$licencia) {
            return response()->json(['ok' => false, 'message' => 'Licencia no encontrada'], 404);
        }

        $licencia->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Aplicar TODAS las licencias activas de la institución del usuario (bulk).
     * Opcionalmente filtra por anios_id.
     */
    public function aplicarTodas(Request $request)
    {
        $institucionId = $request->user()->instituciones_id ?? null;
        if (!$institucionId) {
            return response()->json(['ok' => false, 'message' => 'No se pudo determinar la institución del usuario.'], 409);
        }

        $aniosId = $request->input('anios_id');

        $query = Licenciasestudiantesifas::where('instituciones_id', (int) $institucionId)
            ->where(function ($q) {
                $q->whereNull('estado')
                  ->orWhereRaw("UPPER(TRIM(estado)) = 'ACTIVO'")
                  ->orWhere('estado', '');
            });

        if ($aniosId) {
            $query->where('anios_id', (int) $aniosId);
        }

        $licencias = $query->get();

        if ($licencias->isEmpty()) {
            return response()->json(['ok' => true, 'message' => 'No hay licencias activas para aplicar.', 'total_licencias' => 0, 'stats' => []]);
        }

        $totalStats = [
            'licencias_procesadas' => 0,
            'sesiones_revisadas' => 0,
            'registros_insertados' => 0,
            'registros_actualizados' => 0,
        ];

        DB::transaction(function () use ($licencias, &$totalStats) {
            foreach ($licencias as $licencia) {
                $stats = $this->aplicarLicenciaEnAsistencias($licencia);
                $totalStats['licencias_procesadas']++;
                $totalStats['sesiones_revisadas'] += $stats['sesiones_revisadas'];
                $totalStats['registros_insertados'] += $stats['registros_insertados'];
                $totalStats['registros_actualizados'] += $stats['registros_actualizados'];
            }
        });

        return response()->json([
            'ok' => true,
            'total_licencias' => $totalStats['licencias_procesadas'],
            'stats' => $totalStats,
        ]);
    }
}
