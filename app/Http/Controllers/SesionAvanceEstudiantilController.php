<?php

namespace App\Http\Controllers;

use App\Http\Middleware\UpdateTokenExpiration;
use App\Models\SesionAvanceEstudiantil;
use App\Models\Planteldocentes;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class SesionAvanceEstudiantilController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }

    // ─────────────────────────────────────────────────────
    // MIS ESTUDIANTES: devuelve los 3 grupos de estudiantes
    // asignados al docente autenticado.
    // ─────────────────────────────────────────────────────
    public function misEstudiantes(Request $request)
    {
        $user = $request->user();
        if (!($user instanceof Planteldocentes)) {
            return response()->json(['message' => 'Solo los docentes pueden ver sus estudiantes.'], 403);
        }

        $docenteId = (int) $user->id;
        $instId    = (int) $user->instituciones_id;

        // Campos base de cada estudiante
        $selectBase = [
            'info.id as infoestudiantesifas_id',
            'info.estudiantesifas_id',
            'info.instituciones_id',
            'info.Turno',
            'info.Curso_Solicitado',
            'info.Paralelo_Solicitado',
            'info.InstrumentoMusical',
            'info.InstrumentoMusicalSecundario',
            'e.Ap_Paterno',
            'e.Ap_Materno',
            'e.Nombre',
            'e.CI',
            'e.Foto',
            'i.Nombre as institucion_nombre',
            'i.Logo   as institucion_logo',
            'i.ColorBajo as color_bajo',
        ];

        // 1) Instrumento de Especialidad (planteldocadmins_id)
        $especialidad = DB::table('infoestudiantesifas as info')
            ->join('estudiantesifas as e', 'info.estudiantesifas_id', '=', 'e.id')
            ->join('instituciones as i', 'info.instituciones_id', '=', 'i.id')
            ->where('info.planteldocadmins_id', $docenteId)
            ->where('info.instituciones_id', $instId)
            ->select($selectBase)
            ->orderBy('e.Ap_Paterno')
            ->orderBy('e.Ap_Materno')
            ->orderBy('e.Nombre')
            ->get()
            ->map(fn ($r) => (array) $r + ['tipo_asignacion' => 'ESPECIALIDAD']);

        // 2) Práctica de Conjuntos (planteldocadmins_idPC)
        $practica = DB::table('infoestudiantesifas as info')
            ->join('estudiantesifas as e', 'info.estudiantesifas_id', '=', 'e.id')
            ->join('instituciones as i', 'info.instituciones_id', '=', 'i.id')
            ->where('info.planteldocadmins_idPC', $docenteId)
            ->where('info.instituciones_id', $instId)
            ->select($selectBase)
            ->orderBy('e.Ap_Paterno')
            ->orderBy('e.Ap_Materno')
            ->orderBy('e.Nombre')
            ->get()
            ->map(fn ($r) => (array) $r + ['tipo_asignacion' => 'PRACTICA_CONJUNTOS']);

        // 3) Instrumento Complementario (planteldocadmins_idOtros)
        $complementario = DB::table('infoestudiantesifas as info')
            ->join('estudiantesifas as e', 'info.estudiantesifas_id', '=', 'e.id')
            ->join('instituciones as i', 'info.instituciones_id', '=', 'i.id')
            ->where('info.planteldocadmins_idOtros', $docenteId)
            ->where('info.instituciones_id', $instId)
            ->select($selectBase)
            ->orderBy('e.Ap_Paterno')
            ->orderBy('e.Ap_Materno')
            ->orderBy('e.Nombre')
            ->get()
            ->map(fn ($r) => (array) $r + ['tipo_asignacion' => 'COMPLEMENTARIO']);

        return response()->json([
            'especialidad'    => $especialidad->values(),
            'practica'        => $practica->values(),
            'complementario'  => $complementario->values(),
        ]);
    }

    // ─────────────────────────────────────────────────────
    // CRUD de sesiones de avance
    // ─────────────────────────────────────────────────────

    /**
     * Listar sesiones de un estudiante (por infoestudiantesifas_id + tipo).
     * Opcionalmente filtrar por evaluacion.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!($user instanceof Planteldocentes)) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $infoId = (int) $request->query('infoestudiantesifas_id', 0);
        $tipo   = $request->query('tipo_asignacion', '');
        $eval   = $request->query('evaluacion', null);

        if ($infoId <= 0 || !$tipo) {
            return response()->json(['message' => 'Parámetros inválidos.'], 422);
        }

        $query = SesionAvanceEstudiantil::where('infoestudiantesifas_id', $infoId)
            ->where('planteldocentes_id', (int) $user->id)
            ->where('tipo_asignacion', $tipo);

        if ($eval !== null && $eval !== '') {
            $query->where('evaluacion', (int) $eval);
        }

        $perPage = (int) $request->query('per_page', 0);
        if ($perPage > 0) {
            $paginated = $query->orderBy('fecha', 'desc')
                ->orderBy('numero_clase', 'desc')
                ->paginate($perPage);
            return response()->json($paginated);
        }

        $sesiones = $query->orderBy('fecha', 'desc')
            ->orderBy('numero_clase', 'desc')
            ->get();

        return response()->json(['data' => $sesiones]);
    }

    /**
     * Crear nueva sesión.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        if (!($user instanceof Planteldocentes)) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $validated = $request->validate([
            'infoestudiantesifas_id' => 'required|integer|min:1',
            'tipo_asignacion'        => 'required|string|in:ESPECIALIDAD,PRACTICA_CONJUNTOS,COMPLEMENTARIO',
            'evaluacion'             => 'required|integer|min:1|max:4',
            'fecha'                  => 'required|date',
            'numero_clase'           => 'required|integer|min:1',
            'avance_texto'           => 'nullable|string',
            'estrellas'              => 'nullable|integer|min:0|max:5',
            'sugerencia'             => 'nullable|string',
            'asistencia'             => 'nullable|string|in:P,A,F,L',
        ]);

        $validated['planteldocentes_id'] = (int) $user->id;

        $sesion = SesionAvanceEstudiantil::create($validated);

        return response()->json(['data' => $sesion], 201);
    }

    /**
     * Ver una sesión.
     */
    public function show(Request $request, int $id)
    {
        $user = $request->user();
        if (!($user instanceof Planteldocentes)) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $sesion = SesionAvanceEstudiantil::where('id', $id)
            ->where('planteldocentes_id', (int) $user->id)
            ->first();

        if (!$sesion) {
            return response()->json(['message' => 'No encontrado'], 404);
        }

        return response()->json(['data' => $sesion]);
    }

    /**
     * Actualizar sesión.
     */
    public function update(Request $request, int $id)
    {
        $user = $request->user();
        if (!($user instanceof Planteldocentes)) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $sesion = SesionAvanceEstudiantil::where('id', $id)
            ->where('planteldocentes_id', (int) $user->id)
            ->first();

        if (!$sesion) {
            return response()->json(['message' => 'No encontrado'], 404);
        }

        $validated = $request->validate([
            'fecha'          => 'sometimes|date',
            'numero_clase'   => 'sometimes|integer|min:1',
            'evaluacion'     => 'sometimes|integer|min:1|max:4',
            'avance_texto'   => 'nullable|string',
            'estrellas'      => 'nullable|integer|min:0|max:5',
            'sugerencia'     => 'nullable|string',
            'asistencia'     => 'nullable|string|in:P,A,F,L',
        ]);

        $sesion->update($validated);

        return response()->json(['data' => $sesion]);
    }

    /**
     * Eliminar sesión.
     */
    public function destroy(Request $request, int $id)
    {
        $user = $request->user();
        if (!($user instanceof Planteldocentes)) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $sesion = SesionAvanceEstudiantil::where('id', $id)
            ->where('planteldocentes_id', (int) $user->id)
            ->first();

        if (!$sesion) {
            return response()->json(['message' => 'No encontrado'], 404);
        }

        $sesion->delete();

        return response()->json(['message' => 'Eliminado']);
    }

    /**
     * Copiar última sesión (para "copiar sesión anterior").
     */
    public function copiarUltima(Request $request)
    {
        $user = $request->user();
        if (!($user instanceof Planteldocentes)) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $infoId = (int) $request->input('infoestudiantesifas_id', 0);
        $tipo   = $request->input('tipo_asignacion', '');
        $eval   = (int) $request->input('evaluacion', 1);

        if ($infoId <= 0 || !$tipo) {
            return response()->json(['message' => 'Parámetros inválidos.'], 422);
        }

        // Copiar la sesión más reciente por fecha (de la evaluación indicada)
        $ultima = SesionAvanceEstudiantil::where('infoestudiantesifas_id', $infoId)
            ->where('planteldocentes_id', (int) $user->id)
            ->where('tipo_asignacion', $tipo)
            ->where('evaluacion', $eval)
            ->orderBy('fecha', 'desc')
            ->orderBy('numero_clase', 'desc')
            ->first();

        if (!$ultima) {
            return response()->json(['message' => 'No hay sesiones previas para copiar.'], 404);
        }

        $nueva = SesionAvanceEstudiantil::create([
            'infoestudiantesifas_id' => $infoId,
            'planteldocentes_id'     => (int) $user->id,
            'tipo_asignacion'        => $tipo,
            'evaluacion'             => $eval,
            'fecha'                  => now()->toDateString(),
            'numero_clase'           => $ultima->numero_clase + 1,
            'avance_texto'           => $ultima->avance_texto,
            'estrellas'              => null,
            'sugerencia'             => null,
            'asistencia'             => null,
        ]);

        return response()->json(['data' => $nueva], 201);
    }

    // ─────────────────────────────────────────────────────
    // RESUMEN por evaluación (para baterías en tabla docente)
    // Devuelve promedio de estrellas por evaluación para cada
    // estudiante de la lista del docente.
    // ─────────────────────────────────────────────────────
    public function resumenBaterias(Request $request)
    {
        $user = $request->user();
        if (!($user instanceof Planteldocentes)) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $tipo = $request->query('tipo_asignacion', '');
        if (!$tipo) {
            return response()->json(['message' => 'tipo_asignacion requerido.'], 422);
        }

        $rows = DB::table('sesiones_avance_estudiantil')
            ->select(
                'infoestudiantesifas_id',
                'evaluacion',
                DB::raw('AVG(estrellas) as promedio_estrellas'),
                DB::raw('COUNT(*) as total_sesiones'),
                DB::raw("SUM(CASE WHEN asistencia = 'P' THEN 1 ELSE 0 END) as presentes"),
                DB::raw("SUM(CASE WHEN asistencia = 'A' THEN 1 ELSE 0 END) as atrasos"),
                DB::raw("SUM(CASE WHEN asistencia = 'F' THEN 1 ELSE 0 END) as faltas"),
                DB::raw("SUM(CASE WHEN asistencia = 'L' THEN 1 ELSE 0 END) as licencias")
            )
            ->where('planteldocentes_id', (int) $user->id)
            ->where('tipo_asignacion', $tipo)
            ->groupBy('infoestudiantesifas_id', 'evaluacion')
            ->get();

        // Agrupar: { infoId: { 1: {promedio, total, P, A, F, L}, 2: {...}, ...} }
        $result = [];
        foreach ($rows as $r) {
            $id = (int) $r->infoestudiantesifas_id;
            $ev = (int) $r->evaluacion;
            if (!isset($result[$id])) $result[$id] = [];
            $result[$id][$ev] = [
                'promedio'   => $r->promedio_estrellas !== null ? round((float) $r->promedio_estrellas, 1) : 0,
                'sesiones'   => (int) $r->total_sesiones,
                'presentes'  => (int) $r->presentes,
                'atrasos'    => (int) $r->atrasos,
                'faltas'     => (int) $r->faltas,
                'licencias'  => (int) $r->licencias,
            ];
        }

        return response()->json(['data' => $result]);
    }

    // ─────────────────────────────────────────────────────
    // COMPARACIÓN: sesiones de múltiples estudiantes
    // ─────────────────────────────────────────────────────
    public function comparacion(Request $request)
    {
        $user = $request->user();
        if (!($user instanceof Planteldocentes)) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $infoIds = $request->input('info_ids', []);
        $tipo    = $request->input('tipo_asignacion', '');
        $eval    = $request->input('evaluacion', null);

        if (!is_array($infoIds) || count($infoIds) < 1 || !$tipo) {
            return response()->json(['message' => 'Parámetros inválidos.'], 422);
        }

        $query = SesionAvanceEstudiantil::whereIn('infoestudiantesifas_id', array_map('intval', $infoIds))
            ->where('planteldocentes_id', (int) $user->id)
            ->where('tipo_asignacion', $tipo);

        if ($eval !== null && $eval !== '' && (int) $eval > 0) {
            $query->where('evaluacion', (int) $eval);
        }

        $sesiones = $query->orderBy('fecha', 'desc')
            ->orderBy('numero_clase', 'desc')
            ->get();

        // Agrupar por infoestudiantesifas_id
        $grouped = [];
        foreach ($sesiones as $s) {
            $id = (int) $s->infoestudiantesifas_id;
            if (!isset($grouped[$id])) $grouped[$id] = [];
            $grouped[$id][] = $s;
        }

        return response()->json(['data' => $grouped]);
    }

    // ─────────────────────────────────────────────────────
    // VISTA ESTUDIANTE: el estudiante ve sus propias sesiones
    // ─────────────────────────────────────────────────────
    public function misSesionesEstudiante(Request $request)
    {
        $user = $request->user();

        // Aceptar estudiante o admin que proxy-consulte
        $infoId = null;

        if ($user instanceof \App\Models\Estudiantesifas) {
            // Buscar infoestudiantesifas del estudiante
            $info = DB::table('infoestudiantesifas')
                ->where('estudiantesifas_id', (int) $user->id)
                ->select('id')
                ->first();

            if (!$info) {
                return response()->json(['message' => 'No se encontró información del estudiante.'], 404);
            }
            $infoId = (int) $info->id;
        } else {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $tipo = $request->query('tipo_asignacion', '');
        $eval = $request->query('evaluacion', null);

        $query = SesionAvanceEstudiantil::where('infoestudiantesifas_id', $infoId);

        // Estudiantes no ven sesiones sin asistencia (Ninguno)
        $query->whereNotNull('asistencia');

        if ($tipo) {
            $query->where('tipo_asignacion', $tipo);
        }
        if ($eval !== null && $eval !== '') {
            $query->where('evaluacion', (int) $eval);
        }

        $perPage = (int) $request->query('per_page', 0);
        if ($perPage > 0) {
            $paginated = $query->orderBy('fecha', 'desc')
                ->orderBy('numero_clase', 'desc')
                ->paginate($perPage);
            return response()->json($paginated);
        }

        $sesiones = $query->orderBy('fecha', 'desc')
            ->orderBy('numero_clase', 'desc')
            ->get();

        return response()->json(['data' => $sesiones]);
    }

    // ─────────────────────────────────────────────────────
    // TERMINAR CLASE: marcar F (falta) a estudiantes sin sesión hoy
    // Solo si al menos un estudiante tiene asistencia registrada (P/A/F/L).
    // ─────────────────────────────────────────────────────
    public function terminarClase(Request $request)
    {
        $user = $request->user();
        if (!($user instanceof Planteldocentes)) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $tipo       = $request->input('tipo_asignacion', '');
        $evaluacion = (int) $request->input('evaluacion', 1);
        $fecha      = $request->input('fecha', now()->toDateString());

        if (!$tipo) {
            return response()->json(['message' => 'tipo_asignacion requerido.'], 422);
        }

        // Obtener todos los estudiantes asignados al docente en este tipo
        $estudiantesIds = $this->obtenerIdsEstudiantes($user, $tipo);
        if (empty($estudiantesIds)) {
            return response()->json(['message' => 'No tienes estudiantes asignados.'], 422);
        }

        // Obtener sesiones que ya existen para esa fecha
        $sesionesHoy = SesionAvanceEstudiantil::where('planteldocentes_id', (int) $user->id)
            ->where('tipo_asignacion', $tipo)
            ->where('fecha', $fecha)
            ->get();

        // Verificar que al menos uno tiene asistencia real (P/A/F/L)
        $conAsistencia = $sesionesHoy->filter(fn($s) => in_array($s->asistencia, ['P', 'A', 'F', 'L']));
        if ($conAsistencia->isEmpty()) {
            return response()->json(['message' => 'No se puede terminar la clase: ningún estudiante tiene asistencia registrada hoy.'], 422);
        }

        // Obtener el avance_texto de alguna sesión existente hoy para copiar
        $sesionModelo = $sesionesHoy->first();
        $avanceTexto  = $sesionModelo ? $sesionModelo->avance_texto : '';

        // Determinar número de clase (usar el máximo existente hoy)
        $maxClase = $sesionesHoy->max('numero_clase') ?? 1;

        // IDs de estudiantes que ya tienen sesión hoy
        $idsConSesion = $sesionesHoy->pluck('infoestudiantesifas_id')->map(fn($v) => (int) $v)->toArray();

        // Estudiantes sin sesión hoy
        $sinSesion = array_diff($estudiantesIds, $idsConSesion);

        $creadas = 0;
        foreach ($sinSesion as $infoId) {
            SesionAvanceEstudiantil::create([
                'infoestudiantesifas_id' => $infoId,
                'planteldocentes_id'     => (int) $user->id,
                'tipo_asignacion'        => $tipo,
                'evaluacion'             => $evaluacion,
                'fecha'                  => $fecha,
                'numero_clase'           => $maxClase,
                'avance_texto'           => $avanceTexto,
                'estrellas'              => 0,
                'sugerencia'             => 'NO ASISTIÓ A LA CLASE',
                'asistencia'             => 'F',
            ]);
            $creadas++;
        }

        return response()->json([
            'message' => "Clase terminada. Se marcaron $creadas estudiantes como falta.",
            'creadas' => $creadas,
        ]);
    }

    // ─────────────────────────────────────────────────────
    // ASISTENCIAS DOCENTE: vista completa de asistencias para Excel
    // Ordenada por número de sesión, agrupada por evaluación
    // ─────────────────────────────────────────────────────
    public function asistenciasDocente(Request $request)
    {
        $user = $request->user();
        if (!($user instanceof Planteldocentes)) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $tipo = $request->query('tipo_asignacion', '');
        $eval = $request->query('evaluacion', null);

        if (!$tipo) {
            return response()->json(['message' => 'tipo_asignacion requerido.'], 422);
        }

        $query = SesionAvanceEstudiantil::where('planteldocentes_id', (int) $user->id)
            ->where('tipo_asignacion', $tipo);

        if ($eval !== null && $eval !== '' && (int) $eval > 0) {
            $query->where('evaluacion', (int) $eval);
        }

        $sesiones = $query->orderBy('evaluacion', 'asc')
            ->orderBy('numero_clase', 'asc')
            ->orderBy('fecha', 'asc')
            ->get();

        // Obtener nombres de estudiantes
        $infoIds = $sesiones->pluck('infoestudiantesifas_id')->unique()->toArray();
        $estudiantes = DB::table('infoestudiantesifas')
            ->whereIn('id', $infoIds)
            ->select('id', 'Ap_Paterno', 'Ap_Materno', 'Nombre', 'CI')
            ->get()
            ->keyBy('id');

        // Agrupar por evaluación → número_clase → [registros]
        $grouped = [];
        foreach ($sesiones as $s) {
            $ev = (int) $s->evaluacion;
            $nc = (int) $s->numero_clase;
            if (!isset($grouped[$ev])) $grouped[$ev] = [];
            if (!isset($grouped[$ev][$nc])) $grouped[$ev][$nc] = [];
            $est = $estudiantes[(int) $s->infoestudiantesifas_id] ?? null;
            $nombre = $est ? trim("{$est->Ap_Paterno} {$est->Ap_Materno} {$est->Nombre}") : "ID {$s->infoestudiantesifas_id}";
            $grouped[$ev][$nc][] = [
                'infoestudiantesifas_id' => (int) $s->infoestudiantesifas_id,
                'nombre'     => $nombre,
                'ci'         => $est?->CI ?? '',
                'asistencia' => $s->asistencia,
                'estrellas'  => $s->estrellas,
            ];
        }

        return response()->json(['data' => $grouped, 'estudiantes' => $estudiantes->values()]);
    }

    /**
     * Helper: obtener IDs de infoestudiantesifas asignados al docente por tipo.
     */
    private function obtenerIdsEstudiantes($user, string $tipo): array
    {
        $docenteId = (int) $user->id;
        $instId    = (int) $user->instituciones_id;

        if ($tipo === 'ESPECIALIDAD') {
            $column = 'planteldocadmins_id';
        } elseif ($tipo === 'PRACTICA_CONJUNTOS') {
            $column = 'planteldocadmins_idPC';
        } elseif ($tipo === 'COMPLEMENTARIO') {
            $column = 'planteldocadmins_idOtros';
        } else {
            return [];
        }

        return DB::table('infoestudiantesifas')
            ->where($column, $docenteId)
            ->where('instituciones_id', $instId)
            ->pluck('id')
            ->map(fn($v) => (int) $v)
            ->toArray();
    }
}
