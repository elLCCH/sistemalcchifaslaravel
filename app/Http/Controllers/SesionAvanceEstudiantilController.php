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
    // MIS ESTUDIANTES (PAGINADO + FILTROS): para docentes con muchos estudiantes
    // ─────────────────────────────────────────────────────

    private function columnaDocentePorTipo(string $tipo): ?string
    {
        if ($tipo === 'ESPECIALIDAD') return 'planteldocadmins_id';
        if ($tipo === 'PRACTICA_CONJUNTOS') return 'planteldocadmins_idPC';
        if ($tipo === 'COMPLEMENTARIO') return 'planteldocadmins_idOtros';
        return null;
    }

    /** Query base de estudiantes asignados a un docente por tipo. */
    private function queryMisEstudiantesBase(Planteldocentes $user, string $tipo)
    {
        $col = $this->columnaDocentePorTipo($tipo);
        if (!$col) return null;

        $docenteId = (int) $user->id;
        $instId    = (int) $user->instituciones_id;

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

        return DB::table('infoestudiantesifas as info')
            ->join('estudiantesifas as e', 'info.estudiantesifas_id', '=', 'e.id')
            ->join('instituciones as i', 'info.instituciones_id', '=', 'i.id')
            ->where("info.$col", $docenteId)
            ->where('info.instituciones_id', $instId)
            ->select($selectBase);
    }

    private function normArray($v): array
    {
        if (is_array($v)) return array_values(array_filter(array_map(fn ($x) => trim((string) $x), $v), fn ($x) => $x !== ''));
        if ($v === null) return [];
        $s = trim((string) $v);
        if ($s === '') return [];
        return [$s];
    }

    /** Totales por tipo (para badges). */
    public function misEstudiantesTotales(Request $request)
    {
        $user = $request->user();
        if (!($user instanceof Planteldocentes)) {
            return response()->json(['message' => 'Solo los docentes pueden ver sus estudiantes.'], 403);
        }

        $docenteId = (int) $user->id;
        $instId    = (int) $user->instituciones_id;

        $base = DB::table('infoestudiantesifas')->where('instituciones_id', $instId);

        $especialidad = (clone $base)->where('planteldocadmins_id', $docenteId)->count();
        $practica     = (clone $base)->where('planteldocadmins_idPC', $docenteId)->count();
        $complement   = (clone $base)->where('planteldocadmins_idOtros', $docenteId)->count();

        return response()->json([
            'especialidad'   => (int) $especialidad,
            'practica'       => (int) $practica,
            'complementario' => (int) $complement,
        ]);
    }

    /** Opciones de filtros (distinct) para el tipo activo. */
    public function misEstudiantesFiltros(Request $request)
    {
        $user = $request->user();
        if (!($user instanceof Planteldocentes)) {
            return response()->json(['message' => 'Solo los docentes pueden ver sus estudiantes.'], 403);
        }

        $tipo = (string) $request->query('tipo_asignacion', '');
        if (!$tipo) return response()->json(['message' => 'tipo_asignacion requerido.'], 422);

        $col = $this->columnaDocentePorTipo($tipo);
        if (!$col) return response()->json(['message' => 'tipo_asignacion inválido.'], 422);

        $docenteId = (int) $user->id;
        $instId    = (int) $user->instituciones_id;

        $q = DB::table('infoestudiantesifas as info')
            ->where("info.$col", $docenteId)
            ->where('info.instituciones_id', $instId);

        $cursos = (clone $q)
            ->select('info.Curso_Solicitado')
            ->whereNotNull('info.Curso_Solicitado')
            ->distinct()
            ->orderBy('info.Curso_Solicitado')
            ->pluck('Curso_Solicitado')
            ->map(fn ($v) => trim((string) $v))
            ->filter(fn ($v) => $v !== '')
            ->values();

        $paralelos = (clone $q)
            ->select('info.Paralelo_Solicitado')
            ->whereNotNull('info.Paralelo_Solicitado')
            ->distinct()
            ->orderBy('info.Paralelo_Solicitado')
            ->pluck('Paralelo_Solicitado')
            ->map(fn ($v) => trim((string) $v))
            ->filter(fn ($v) => $v !== '')
            ->values();

        $turnos = (clone $q)
            ->select('info.Turno')
            ->whereNotNull('info.Turno')
            ->distinct()
            ->orderBy('info.Turno')
            ->pluck('Turno')
            ->map(fn ($v) => trim((string) $v))
            ->filter(fn ($v) => $v !== '')
            ->values();

        $instrumentos = (clone $q)
            ->select('info.InstrumentoMusical')
            ->whereNotNull('info.InstrumentoMusical')
            ->distinct()
            ->orderBy('info.InstrumentoMusical')
            ->pluck('InstrumentoMusical')
            ->map(fn ($v) => trim((string) $v))
            ->filter(fn ($v) => $v !== '')
            ->values();

        $total = (clone $q)->count();

        return response()->json([
            'total' => (int) $total,
            'cursos' => $cursos,
            'paralelos' => $paralelos,
            'turnos' => $turnos,
            'instrumentos' => $instrumentos,
        ]);
    }

    /** Grupos Curso+Paralelo+Turno (con cantidad) para Terminar Clase. */
    public function misEstudiantesCursos(Request $request)
    {
        $user = $request->user();
        if (!($user instanceof Planteldocentes)) {
            return response()->json(['message' => 'Solo los docentes pueden ver sus estudiantes.'], 403);
        }

        $tipo = (string) $request->query('tipo_asignacion', '');
        if (!$tipo) return response()->json(['message' => 'tipo_asignacion requerido.'], 422);

        $col = $this->columnaDocentePorTipo($tipo);
        if (!$col) return response()->json(['message' => 'tipo_asignacion inválido.'], 422);

        $docenteId = (int) $user->id;
        $instId    = (int) $user->instituciones_id;

        $rows = DB::table('infoestudiantesifas as info')
            ->where("info.$col", $docenteId)
            ->where('info.instituciones_id', $instId)
            ->select([
                'info.Curso_Solicitado',
                'info.Paralelo_Solicitado',
                'info.Turno',
                DB::raw('COUNT(*) as cant'),
            ])
            ->groupBy('info.Curso_Solicitado', 'info.Paralelo_Solicitado', 'info.Turno')
            ->orderBy('info.Curso_Solicitado')
            ->orderBy('info.Paralelo_Solicitado')
            ->orderBy('info.Turno')
            ->get();

        $data = [];
        foreach ($rows as $r) {
            $curso = trim((string) ($r->Curso_Solicitado ?? ''));
            $paralelo = trim((string) ($r->Paralelo_Solicitado ?? ''));
            $turno = trim((string) ($r->Turno ?? ''));
            $key = $curso . '||' . $paralelo . '||' . ($turno !== '' ? $turno : '—');
            $label = trim($curso . ($paralelo !== '' ? (' ' . $paralelo) : ''));
            $data[] = [
                'key' => $key,
                'curso' => $curso,
                'paralelo' => $paralelo,
                'turno' => $turno,
                'label' => $label !== '' ? $label : 'SIN CURSO',
                'cantEstudiantes' => (int) ($r->cant ?? 0),
            ];
        }

        return response()->json(['data' => $data]);
    }

    /** Listado paginado (15x defecto) con filtros y búsqueda. */
    public function misEstudiantesPaginado(Request $request)
    {
        $user = $request->user();
        if (!($user instanceof Planteldocentes)) {
            return response()->json(['message' => 'Solo los docentes pueden ver sus estudiantes.'], 403);
        }

        $tipo = (string) $request->query('tipo_asignacion', '');
        if (!$tipo) return response()->json(['message' => 'tipo_asignacion requerido.'], 422);

        $q = $this->queryMisEstudiantesBase($user, $tipo);
        if (!$q) return response()->json(['message' => 'tipo_asignacion inválido.'], 422);

        $perPage = (int) $request->query('per_page', 15);
        if ($perPage <= 0) $perPage = 15;
        if ($perPage > 100) $perPage = 100;

        $cursos = $this->normArray($request->query('cursos', []));
        $paralelos = $this->normArray($request->query('paralelos', []));
        $turnos = $this->normArray($request->query('turnos', []));
        $instrumentos = $this->normArray($request->query('instrumentos', []));
        $search = trim((string) $request->query('q', ''));

        if (!empty($cursos)) $q->whereIn('info.Curso_Solicitado', $cursos);
        if (!empty($paralelos)) $q->whereIn('info.Paralelo_Solicitado', $paralelos);
        if (!empty($turnos)) $q->whereIn('info.Turno', $turnos);
        if (!empty($instrumentos)) $q->whereIn('info.InstrumentoMusical', $instrumentos);

        if ($search !== '') {
            $searchLower = mb_strtolower($search);
            $tokens = preg_split('/\s+/', $searchLower) ?: [];
            $tokens = array_values(array_filter(array_map(fn($t) => trim((string) $t), $tokens), fn($t) => $t !== ''));

            // Requerir que TODOS los tokens aparezcan en alguno de los campos (AND entre tokens, OR entre campos)
            $q->where(function ($sub) use ($tokens) {
                foreach ($tokens as $t) {
                    $like = '%' . $t . '%';
                    $sub->where(function ($sub2) use ($like) {
                        $sub2->whereRaw('LOWER(COALESCE(e.Ap_Paterno, \'\')) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(COALESCE(e.Ap_Materno, \'\')) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(COALESCE(e.Nombre, \'\')) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(COALESCE(e.CI, \'\')) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(COALESCE(info.InstrumentoMusical, \'\')) LIKE ?', [$like])
                            // Nombre completo concatenado (para búsquedas tipo "mamani villca")
                            ->orWhereRaw("LOWER(TRIM(CONCAT_WS(' ', COALESCE(e.Ap_Paterno,''), COALESCE(e.Ap_Materno,''), COALESCE(e.Nombre,'')))) LIKE ?", [$like]);
                    });
                }
            });
        }

        $q->orderBy('e.Ap_Paterno')->orderBy('e.Ap_Materno')->orderBy('e.Nombre');

        $paginated = $q->paginate($perPage);
        return response()->json($paginated);
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

        // Estudiantes ven todas las sesiones (incluyendo las que no tienen asistencia registrada)

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
    // TERMINAR CLASE: marca F (falta) a estudiantes sin sesión en la fecha indicada.
    // Autocompleta por estudiante tomando su última sesión registrada (número + avance_texto).
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
        $cursos     = $request->input('cursos', []);

        if (!$tipo) {
            return response()->json(['message' => 'tipo_asignacion requerido.'], 422);
        }

        if ($evaluacion <= 0) {
            $evaluacion = 1;
        }

        if (!is_array($cursos)) {
            $cursos = [];
        }

        // Obtener todos los estudiantes asignados al docente en este tipo
        $estudiantesIds = $this->obtenerIdsEstudiantes($user, $tipo, $cursos);
        if (empty($estudiantesIds)) {
            return response()->json(['message' => 'No tienes estudiantes asignados.'], 422);
        }

        // Sesiones existentes hoy (para no duplicar)
        $sesionesHoy = SesionAvanceEstudiantil::where('planteldocentes_id', (int) $user->id)
            ->where('tipo_asignacion', $tipo)
            ->where('evaluacion', $evaluacion)
            ->where('fecha', $fecha)
            ->whereIn('infoestudiantesifas_id', $estudiantesIds)
            ->get(['infoestudiantesifas_id']);

        $idsConSesion = $sesionesHoy->pluck('infoestudiantesifas_id')->map(fn($v) => (int) $v)->toArray();
        $sinSesion = array_values(array_diff($estudiantesIds, $idsConSesion));

        if (empty($sinSesion)) {
            return response()->json([
                'message' => 'Clase terminada. No había estudiantes pendientes sin sesión hoy.',
                'creadas' => 0,
            ]);
        }

        // Cargar última sesión por estudiante (para autollenar individualmente)
        $ultimas = SesionAvanceEstudiantil::where('planteldocentes_id', (int) $user->id)
            ->where('tipo_asignacion', $tipo)
            ->where('evaluacion', $evaluacion)
            ->whereIn('infoestudiantesifas_id', $sinSesion)
            ->orderBy('fecha', 'desc')
            ->orderBy('numero_clase', 'desc')
            ->get(['infoestudiantesifas_id', 'numero_clase', 'avance_texto']);

        $ultimaPorEst = [];
        foreach ($ultimas as $row) {
            $iid = (int) $row->infoestudiantesifas_id;
            if (!isset($ultimaPorEst[$iid])) {
                $ultimaPorEst[$iid] = $row;
            }
        }

        $creadas = 0;
        foreach ($sinSesion as $infoId) {
            $infoId = (int) $infoId;
            $ultima = $ultimaPorEst[$infoId] ?? null;
            $numeroClase = $ultima ? (((int) $ultima->numero_clase) + 1) : 1;
            $avanceTexto = $ultima ? ($ultima->avance_texto ?? '') : '';

            SesionAvanceEstudiantil::create([
                'infoestudiantesifas_id' => $infoId,
                'planteldocentes_id'     => (int) $user->id,
                'tipo_asignacion'        => $tipo,
                'evaluacion'             => $evaluacion,
                'fecha'                  => $fecha,
                'numero_clase'           => $numeroClase,
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
        $estudiantes = DB::table('infoestudiantesifas as info')
            ->join('estudiantesifas as e', 'info.estudiantesifas_id', '=', 'e.id')
            ->whereIn('info.id', $infoIds)
            ->select([
                'info.id as id',
                'info.Curso_Solicitado',
                'info.Paralelo_Solicitado',
                'info.Turno',
                'e.Ap_Paterno',
                'e.Ap_Materno',
                'e.Nombre',
                'e.CI',
                'e.Foto',
            ])
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
                'curso'      => $est?->Curso_Solicitado ?? '',
                'paralelo'   => $est?->Paralelo_Solicitado ?? '',
                'turno'      => $est?->Turno ?? '',
                'fecha'      => $s->fecha,
                'asistencia' => $s->asistencia,
                'estrellas'  => $s->estrellas,
            ];
        }

        return response()->json(['data' => $grouped, 'estudiantes' => $estudiantes->values()]);
    }

    /**
     * Helper: obtener IDs de infoestudiantesifas asignados al docente por tipo.
     */
    private function obtenerIdsEstudiantes($user, string $tipo, array $cursoKeys = []): array
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

        $q = DB::table('infoestudiantesifas')
            ->where($column, $docenteId)
            ->where('instituciones_id', $instId);

        // Filtro opcional por curso+paralelo+turno (keys: "CURSO||PARALELO||TURNO")
        // Compatibilidad: también acepta "CURSO||TURNO".
        $cursoKeys = array_values(array_filter(array_map(fn($v) => is_string($v) ? trim($v) : '', $cursoKeys)));
        if (!empty($cursoKeys)) {
            $pairs = [];
            foreach ($cursoKeys as $key) {
                $parts = explode('||', $key);
                $curso = isset($parts[0]) ? trim($parts[0]) : '';
                $paralelo = '';
                $turno = '';
                if (count($parts) >= 3) {
                    $paralelo = trim((string) ($parts[1] ?? ''));
                    $turno = trim((string) ($parts[2] ?? ''));
                } else {
                    $turno = trim((string) ($parts[1] ?? ''));
                }
                if ($curso === '') continue;
                // Si paralelo/turno está vacío, filtrar solo por los que existan.
                $pairs[] = [$curso, $paralelo, $turno];
            }

            if (!empty($pairs)) {
                $q->where(function ($sub) use ($pairs) {
                    foreach ($pairs as $pair) {
                        [$curso, $paralelo, $turno] = $pair;
                        $sub->orWhere(function ($sub2) use ($curso, $paralelo, $turno) {
                            $sub2->where('Curso_Solicitado', $curso);
                            if ($paralelo !== '') {
                                $sub2->where('Paralelo_Solicitado', $paralelo);
                            }
                            if ($turno !== '') {
                                $sub2->where('Turno', $turno);
                            }
                        });
                    }
                });
            }
        }

        return $q->pluck('id')
            ->map(fn($v) => (int) $v)
            ->toArray();
    }
}
