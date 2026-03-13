<?php

namespace App\Http\Controllers;

use App\Http\Middleware\UpdateTokenExpiration;
use App\Models\SesionAvanceEstudiantil;
use App\Models\TerminarClaseLog;
use App\Models\Planteldocentes;
use App\Models\Planteladministrativos;
use App\Models\Usuarioslcchs;
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
    private function queryMisEstudiantesBase($userOrDocenteId, string $tipo, ?int $instId = null)
    {
        $col = $this->columnaDocentePorTipo($tipo);
        if (!$col) return null;

        if ($userOrDocenteId instanceof Planteldocentes) {
            $docenteId = (int) $userOrDocenteId->id;
            $instId    = (int) $userOrDocenteId->instituciones_id;
        } else {
            $docenteId = (int) $userOrDocenteId;
            $instId    = (int) $instId;
        }

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
        $isDocente = ($user instanceof Planteldocentes);
        $isAdmin   = ($user instanceof Planteladministrativos) || ($user instanceof Usuarioslcchs);

        if (!$isDocente && !$isAdmin) {
            return response()->json(['message' => 'Solo los docentes o administrativos pueden ver estudiantes.'], 403);
        }

        if ($isAdmin) {
            $adminDocenteId = (int) $request->query('planteldocentes_id', 0);
            if ($adminDocenteId <= 0) {
                return response()->json(['message' => 'Debe indicar planteldocentes_id.'], 422);
            }
            $docente = Planteldocentes::find($adminDocenteId);
            if (!$docente) return response()->json(['message' => 'Docente no encontrado.'], 404);
            $docenteId = (int) $docente->id;
            $instId    = (int) $docente->instituciones_id;
        } else {
            $docenteId = (int) $user->id;
            $instId    = (int) $user->instituciones_id;
        }

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
        $isDocente = ($user instanceof Planteldocentes);
        $isAdmin   = ($user instanceof Planteladministrativos) || ($user instanceof Usuarioslcchs);

        if (!$isDocente && !$isAdmin) {
            return response()->json(['message' => 'Solo los docentes o administrativos pueden ver estudiantes.'], 403);
        }

        $tipo = (string) $request->query('tipo_asignacion', '');
        if (!$tipo) return response()->json(['message' => 'tipo_asignacion requerido.'], 422);

        $col = $this->columnaDocentePorTipo($tipo);
        if (!$col) return response()->json(['message' => 'tipo_asignacion inválido.'], 422);

        if ($isAdmin) {
            $adminDocenteId = (int) $request->query('planteldocentes_id', 0);
            if ($adminDocenteId <= 0) {
                return response()->json(['message' => 'Debe indicar planteldocentes_id.'], 422);
            }
            $docente = Planteldocentes::find($adminDocenteId);
            if (!$docente) return response()->json(['message' => 'Docente no encontrado.'], 404);
            $docenteId = (int) $docente->id;
            $instId    = (int) $docente->instituciones_id;
        } else {
            $docenteId = (int) $user->id;
            $instId    = (int) $user->instituciones_id;
        }

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
        $isDocente = ($user instanceof Planteldocentes);
        $isAdmin   = ($user instanceof Planteladministrativos) || ($user instanceof Usuarioslcchs);

        if (!$isDocente && !$isAdmin) {
            return response()->json(['message' => 'Solo los docentes o administrativos pueden ver estudiantes.'], 403);
        }

        $tipo = (string) $request->query('tipo_asignacion', '');
        if (!$tipo) return response()->json(['message' => 'tipo_asignacion requerido.'], 422);

        if ($isAdmin) {
            $adminDocenteId = (int) $request->query('planteldocentes_id', 0);
            if ($adminDocenteId <= 0) {
                return response()->json(['message' => 'Debe indicar planteldocentes_id.'], 422);
            }
            $docente = Planteldocentes::find($adminDocenteId);
            if (!$docente) return response()->json(['message' => 'Docente no encontrado.'], 404);
            $q = $this->queryMisEstudiantesBase($docente->id, $tipo, (int) $docente->instituciones_id);
        } else {
            $q = $this->queryMisEstudiantesBase($user, $tipo);
        }
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

    /**
     * Retorna SOLO los IDs de todos los estudiantes que coinciden con los filtros activos (sin paginación).
     * Útil para "Seleccionar todos" y "Comparar todos".
     */
    public function misEstudiantesTodosIds(Request $request)
    {
        $user = $request->user();
        $isDocente = ($user instanceof Planteldocentes);
        $isAdmin   = ($user instanceof Planteladministrativos) || ($user instanceof Usuarioslcchs);
        if (!$isDocente && !$isAdmin) return response()->json(['message' => 'No autorizado.'], 403);

        $tipo = (string) $request->query('tipo_asignacion', '');
        if (!$tipo) return response()->json(['message' => 'tipo_asignacion requerido.'], 422);

        if ($isAdmin) {
            $adminDocenteId = (int) $request->query('planteldocentes_id', 0);
            if ($adminDocenteId <= 0) return response()->json(['message' => 'Debe indicar planteldocentes_id.'], 422);
            $docente = Planteldocentes::find($adminDocenteId);
            if (!$docente) return response()->json(['message' => 'Docente no encontrado.'], 404);
            $q = $this->queryMisEstudiantesBase($docente->id, $tipo, (int) $docente->instituciones_id);
        } else {
            $q = $this->queryMisEstudiantesBase($user, $tipo);
        }
        if (!$q) return response()->json(['message' => 'tipo_asignacion inválido.'], 422);

        $cursos = $this->normArray($request->query('cursos', []));
        $paralelos = $this->normArray($request->query('paralelos', []));
        $turnos = $this->normArray($request->query('turnos', []));
        $instrumentos = $this->normArray($request->query('instrumentos', []));

        if (!empty($cursos)) $q->whereIn('info.Curso_Solicitado', $cursos);
        if (!empty($paralelos)) $q->whereIn('info.Paralelo_Solicitado', $paralelos);
        if (!empty($turnos)) $q->whereIn('info.Turno', $turnos);
        if (!empty($instrumentos)) $q->whereIn('info.InstrumentoMusical', $instrumentos);

        $q->orderBy('e.Ap_Paterno')->orderBy('e.Ap_Materno')->orderBy('e.Nombre');
        $results = $q->get();

        return response()->json(['data' => $results]);
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
        $isDocente = ($user instanceof Planteldocentes);
        $isAdmin   = ($user instanceof Planteladministrativos) || ($user instanceof Usuarioslcchs);

        if (!$isDocente && !$isAdmin) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $infoId = (int) $request->query('infoestudiantesifas_id', 0);
        $tipo   = $request->query('tipo_asignacion', '');
        $eval   = $request->query('evaluacion', null);

        if ($infoId <= 0 || !$tipo) {
            return response()->json(['message' => 'Parámetros inválidos.'], 422);
        }

        if ($isAdmin) {
            $adminDocenteId = (int) $request->query('planteldocentes_id', 0);
            if ($adminDocenteId <= 0) {
                return response()->json(['message' => 'Debe indicar planteldocentes_id.'], 422);
            }
            $docenteId = $adminDocenteId;
        } else {
            $docenteId = (int) $user->id;
        }

        $query = SesionAvanceEstudiantil::where('infoestudiantesifas_id', $infoId)
            ->where('planteldocentes_id', $docenteId)
            ->where('tipo_asignacion', $tipo);

        if ($eval !== null && $eval !== '') {
            $query->where('evaluacion', (int) $eval);
        }

        $perPage = (int) $request->query('per_page', 0);
        if ($perPage > 0) {
            $paginated = $query->orderBy('fecha', 'desc')
                ->orderBy('id', 'desc')
                ->paginate($perPage);
            return response()->json($paginated);
        }

        $sesiones = $query->orderBy('fecha', 'desc')
            ->orderBy('id', 'desc')
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
            ->orderBy('id', 'desc')
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
        $isDocente = ($user instanceof Planteldocentes);
        $isAdmin   = ($user instanceof Planteladministrativos) || ($user instanceof Usuarioslcchs);

        if (!$isDocente && !$isAdmin) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $tipo = $request->query('tipo_asignacion', '');
        if (!$tipo) {
            return response()->json(['message' => 'tipo_asignacion requerido.'], 422);
        }

        if ($isAdmin) {
            $adminDocenteId = (int) $request->query('planteldocentes_id', 0);
            if ($adminDocenteId <= 0) {
                return response()->json(['message' => 'Debe indicar planteldocentes_id.'], 422);
            }
            $docente = Planteldocentes::find($adminDocenteId);
            if (!$docente) return response()->json(['message' => 'Docente no encontrado.'], 404);
            $docenteId = (int) $docente->id;
        } else {
            $docenteId = (int) $user->id;
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
            ->where('planteldocentes_id', (int) $docenteId)
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
        $isDocente = ($user instanceof Planteldocentes);
        $isAdmin   = ($user instanceof Planteladministrativos) || ($user instanceof Usuarioslcchs);

        if (!$isDocente && !$isAdmin) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $infoIds = $request->input('info_ids', []);
        $tipo    = $request->input('tipo_asignacion', '');
        $eval    = $request->input('evaluacion', null);

        if (!is_array($infoIds) || count($infoIds) < 1 || !$tipo) {
            return response()->json(['message' => 'Parámetros inválidos.'], 422);
        }

        if ($isAdmin) {
            $adminDocenteId = (int) $request->input('planteldocentes_id', 0);
            if ($adminDocenteId <= 0) {
                return response()->json(['message' => 'Debe indicar planteldocentes_id.'], 422);
            }
            $docente = Planteldocentes::find($adminDocenteId);
            if (!$docente) return response()->json(['message' => 'Docente no encontrado.'], 404);
            $docenteId = (int) $docente->id;
        } else {
            $docenteId = (int) $user->id;
        }

        $query = SesionAvanceEstudiantil::whereIn('infoestudiantesifas_id', array_map('intval', $infoIds))
            ->where('planteldocentes_id', $docenteId)
            ->where('tipo_asignacion', $tipo);

        if ($eval !== null && $eval !== '' && (int) $eval > 0) {
            $query->where('evaluacion', (int) $eval);
        }

        $sesiones = $query->orderBy('fecha', 'desc')
            ->orderBy('id', 'desc')
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
                ->orderBy('id', 'desc')
                ->paginate($perPage);
            return response()->json($paginated);
        }

        $sesiones = $query->orderBy('fecha', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        return response()->json(['data' => $sesiones]);
    }

    // ─────────────────────────────────────────────────────
    // TERMINAR CLASE: marca F (falta) a estudiantes sin sesión en la fecha indicada.
    // Autocompleta por estudiante tomando su última sesión registrada (avance_texto).
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
            ->orderBy('id', 'desc')
            ->get(['infoestudiantesifas_id', 'avance_texto']);

        $ultimaPorEst = [];
        foreach ($ultimas as $row) {
            $iid = (int) $row->infoestudiantesifas_id;
            if (!isset($ultimaPorEst[$iid])) {
                $ultimaPorEst[$iid] = $row;
            }
        }

        $creadas = 0;
        $idsCreados = [];
        foreach ($sinSesion as $infoId) {
            $infoId = (int) $infoId;
            $ultima = $ultimaPorEst[$infoId] ?? null;
            $avanceTexto = $ultima ? ($ultima->avance_texto ?? '') : '';

            $sesion = SesionAvanceEstudiantil::create([
                'infoestudiantesifas_id' => $infoId,
                'planteldocentes_id'     => (int) $user->id,
                'tipo_asignacion'        => $tipo,
                'evaluacion'             => $evaluacion,
                'fecha'                  => $fecha,
                'avance_texto'           => $avanceTexto,
                'estrellas'              => 0,
                'sugerencia'             => 'NO VINO A CLASES',
                'asistencia'             => 'F',
            ]);
            $idsCreados[] = (int) $sesion->id;
            $creadas++;
        }

        // Registrar log para poder deshacer
        if ($creadas > 0) {
            TerminarClaseLog::create([
                'planteldocentes_id'   => (int) $user->id,
                'instituciones_id'     => (int) $user->instituciones_id,
                'tipo_asignacion'      => $tipo,
                'evaluacion'           => $evaluacion,
                'fecha'                => $fecha,
                'cursos_json'          => !empty($cursos) ? json_encode($cursos) : null,
                'sesiones_creadas_ids' => json_encode($idsCreados),
                'cantidad_creadas'     => $creadas,
            ]);
        }

        return response()->json([
            'message' => "Clase terminada. Se marcaron $creadas estudiantes como falta.",
            'creadas' => $creadas,
        ]);
    }

    // ─────────────────────────────────────────────────────
    // DESHACER TERMINAR CLASE (rollback)
    // Solo permite deshacer acciones del mismo día (hoy)
    // ─────────────────────────────────────────────────────
    public function deshacerTerminarClase(Request $request)
    {
        $user = $request->user();
        if (!($user instanceof Planteldocentes)) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $hoy = now()->toDateString();

        // Buscar el último log NO deshecho del docente, creado HOY
        $log = TerminarClaseLog::where('planteldocentes_id', (int) $user->id)
            ->whereNull('deshecho_at')
            ->whereDate('created_at', $hoy)
            ->orderByDesc('id')
            ->first();

        if (!$log) {
            return response()->json([
                'message' => 'No hay acciones de "Terminar Clase" para deshacer hoy.',
                'eliminadas' => 0,
            ], 404);
        }

        $ids = json_decode($log->sesiones_creadas_ids, true);
        if (!is_array($ids) || empty($ids)) {
            $log->update(['deshecho_at' => now(), 'deshecho_por' => (int) $user->id]);
            return response()->json([
                'message' => 'Log encontrado pero sin sesiones asociadas.',
                'eliminadas' => 0,
            ]);
        }

        // Eliminar solo las sesiones que fueron creadas por esta acción
        $eliminadas = SesionAvanceEstudiantil::whereIn('id', $ids)
            ->where('planteldocentes_id', (int) $user->id)
            ->where('asistencia', 'F')
            ->delete();

        $log->update(['deshecho_at' => now(), 'deshecho_por' => (int) $user->id]);

        return response()->json([
            'message' => "Se deshizo la acción. Se eliminaron $eliminadas registros de falta.",
            'eliminadas' => $eliminadas,
            'log_id' => $log->id,
        ]);
    }

    // ─────────────────────────────────────────────────────
    // HISTORIAL DE TERMINAR CLASE (para UI de deshacer)
    // Retorna los logs del día actual del docente
    // ─────────────────────────────────────────────────────
    public function terminarClaseHistorial(Request $request)
    {
        $user = $request->user();
        if (!($user instanceof Planteldocentes)) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $hoy = now()->toDateString();

        $logs = TerminarClaseLog::where('planteldocentes_id', (int) $user->id)
            ->whereDate('created_at', $hoy)
            ->orderByDesc('id')
            ->get(['id', 'tipo_asignacion', 'evaluacion', 'fecha', 'cantidad_creadas', 'deshecho_at', 'created_at']);

        return response()->json(['data' => $logs]);
    }

    // ─────────────────────────────────────────────────────
    // LICENCIAS DOCENTE: ver estudiantes con licencia activa
    // Disponible para planteldocentes y planteladministrativos
    // ─────────────────────────────────────────────────────
    public function licenciasDocente(Request $request)
    {
        $user = $request->user();
        $esDocente = $user instanceof Planteldocentes;
        $esAdmin   = $user instanceof Planteladministrativos || $user instanceof Usuarioslcchs;

        if (!$esDocente && !$esAdmin) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $instId = (int) ($user->instituciones_id ?? 0);
        if (!$instId) {
            return response()->json(['message' => 'No se pudo determinar la institución.'], 409);
        }

        $hoy = now()->toDateString();

        // Todas las licencias activas, ordenadas por fecha descendente
        $licencias = DB::table('licenciasestudiantesifas as lic')
            ->join('infoestudiantesifas as ie', 'ie.id', '=', 'lic.infoestudiantesifas_id')
            ->join('estudiantesifas as e', 'e.id', '=', 'ie.estudiantesifas_id')
            ->where('lic.instituciones_id', $instId)
            ->where('lic.estado', 'ACTIVO')
            ->select([
                'lic.id as licencia_id',
                'lic.fecha_inicio',
                'lic.fecha_fin',
                'lic.motivo',
                'lic.registrado_por',
                'ie.id as infoestudiantesifas_id',
                'ie.planteldocadmins_id',
                'ie.planteldocadmins_idPC',
                'ie.planteldocadmins_idOtros',
                'ie.Curso_Solicitado',
                'ie.Paralelo_Solicitado',
                'ie.Turno',
                'ie.InstrumentoMusical',
                'e.CI',
                'e.Ap_Paterno',
                'e.Ap_Materno',
                'e.Nombre',
                'e.Foto',
            ])
            ->orderByDesc('lic.fecha_fin')
            ->orderByDesc('lic.fecha_inicio')
            ->orderBy('e.Ap_Paterno')
            ->orderBy('e.Ap_Materno')
            ->orderBy('e.Nombre')
            ->get();

        // Determinar relación con el docente y materias compartidas
        $docenteId = $esDocente ? (int) $user->id : null;

        // Obtener materias del docente (si es docente)
        $materiasDocente = collect();
        if ($docenteId) {
            $materiasDocente = DB::table('planteldocentesmaterias as pdm')
                ->join('materias as m', 'm.id', '=', 'pdm.materias_id')
                ->join('plandeestudios as pe', 'pe.id', '=', 'm.plandeestudios_id')
                ->leftJoin('anios as a', 'a.id', '=', 'pe.anio_id')
                ->where('pdm.planteldocentes_id', $docenteId)
                ->select([
                    'm.id as materia_id',
                    'pe.NombreMateria',
                    'm.Paralelo as materia_paralelo',
                    'm.Turno as materia_turno',
                    'pe.LvlCurso as Curso',
                    'pe.SiglaMateria as Sigla',
                    'a.Anio',
                ])
                ->get()
                ->keyBy('materia_id');
        }

        $materiasDocenteIds = $materiasDocente->keys()->toArray();

        // Obtener calificaciones de los estudiantes con licencia que coincidan con materias del docente
        $infoIds = $licencias->pluck('infoestudiantesifas_id')->unique()->toArray();
        $calificacionesPorEst = [];
        if ($docenteId && !empty($infoIds) && !empty($materiasDocenteIds)) {
            $cals = DB::table('calificaciones')
                ->whereIn('infoestudiantesifas_id', $infoIds)
                ->whereIn('materias_id', $materiasDocenteIds)
                ->select(['infoestudiantesifas_id', 'materias_id'])
                ->get();
            foreach ($cals as $c) {
                $key = (int) $c->infoestudiantesifas_id;
                if (!isset($calificacionesPorEst[$key])) $calificacionesPorEst[$key] = [];
                $calificacionesPorEst[$key][] = (int) $c->materias_id;
            }
        }

        // Enriquecer resultados
        $resultado = $licencias->map(function ($lic) use ($docenteId, $calificacionesPorEst, $materiasDocente, $hoy) {
            $item = (array) $lic;

            // Indicar si la licencia está vigente hoy
            $item['vigente'] = ($lic->fecha_inicio <= $hoy && $lic->fecha_fin >= $hoy);

            // Relación directa con el docente
            $relacion = null;
            if ($docenteId) {
                if ((int) ($lic->planteldocadmins_id ?? 0) === $docenteId) {
                    $relacion = 'Tu Est. Especialidad';
                } elseif ((int) ($lic->planteldocadmins_idPC ?? 0) === $docenteId) {
                    $relacion = 'Tu Est. Prac. Conj.';
                } elseif ((int) ($lic->planteldocadmins_idOtros ?? 0) === $docenteId) {
                    $relacion = 'Tu Est. Complementario';
                }
            }
            $item['relacion_docente'] = $relacion;

            // Materias compartidas (para todos los estudiantes, directos o no)
            $materiasCompartidas = [];
            $infoId = (int) $lic->infoestudiantesifas_id;
            if ($docenteId && isset($calificacionesPorEst[$infoId])) {
                foreach ($calificacionesPorEst[$infoId] as $matId) {
                    $mat = $materiasDocente->get($matId);
                    if ($mat) {
                        $materiasCompartidas[] = [
                            'materia_id' => $matId,
                            'nombre' => $mat->NombreMateria,
                            'curso' => $mat->Curso,
                            'paralelo' => $mat->materia_paralelo,
                            'sigla' => $mat->Sigla,
                            'anio' => $mat->Anio,
                        ];
                    }
                }
            }
            $item['materias_compartidas'] = $materiasCompartidas;
            $item['cant_materias_compartidas'] = count($materiasCompartidas);

            return $item;
        });

        return response()->json(['data' => $resultado->values()]);
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
            ->orderBy('fecha', 'asc')
            ->orderBy('id', 'asc')
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

        // Agrupar por evaluación → fecha → [registros]
        $grouped = [];
        foreach ($sesiones as $s) {
            $ev = (int) $s->evaluacion;
            $f  = (string) ($s->fecha ?? '');
            if ($f === '') continue; // saltar sesiones sin fecha
            if (!isset($grouped[$ev])) $grouped[$ev] = [];
            if (!isset($grouped[$ev][$f])) $grouped[$ev][$f] = [];
            $est = $estudiantes->get((int) $s->infoestudiantesifas_id);
            $nombre = $est ? trim("{$est->Ap_Paterno} {$est->Ap_Materno} {$est->Nombre}") : "ID {$s->infoestudiantesifas_id}";
            $grouped[$ev][$f][] = [
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
     * Estabilizar sesiones: para las fechas indicadas y los estudiantes dados,
     * crear sesiones de falta donde no existan.
     */
    public function estabilizarSesiones(Request $request)
    {
        $user = $request->user();
        if (!($user instanceof Planteldocentes)) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $infoIds = $request->input('info_ids', []);
        $tipo    = $request->input('tipo_asignacion', '');
        $fechas  = $request->input('fechas', []);
        $eval    = (int) $request->input('evaluacion', 1);

        if (!is_array($infoIds) || count($infoIds) < 1 || !$tipo || !is_array($fechas) || count($fechas) < 1) {
            return response()->json(['message' => 'Parámetros inválidos.'], 422);
        }

        $docenteId = (int) $user->id;
        $creadas = 0;
        $idsCreados = [];

        // Ordenar fechas cronológicamente
        sort($fechas);

        foreach ($infoIds as $infoId) {
            $infoId = (int) $infoId;
            if ($infoId <= 0) continue;

            foreach ($fechas as $fecha) {
                $fecha = trim((string) $fecha);
                if (!$fecha) continue;

                // Verificar si ya existe sesión en esa fecha
                $existe = SesionAvanceEstudiantil::where('infoestudiantesifas_id', $infoId)
                    ->where('planteldocentes_id', $docenteId)
                    ->where('tipo_asignacion', $tipo)
                    ->where('evaluacion', $eval)
                    ->where('fecha', $fecha)
                    ->exists();

                if (!$existe) {
                    // Buscar última sesión anterior a esta fecha para copiar avance_texto
                    $ultimaAnterior = SesionAvanceEstudiantil::where('infoestudiantesifas_id', $infoId)
                        ->where('planteldocentes_id', $docenteId)
                        ->where('tipo_asignacion', $tipo)
                        ->where('evaluacion', $eval)
                        ->where('fecha', '<', $fecha)
                        ->orderBy('fecha', 'desc')
                        ->orderBy('id', 'desc')
                        ->first(['avance_texto']);

                    $sesion = SesionAvanceEstudiantil::create([
                        'infoestudiantesifas_id' => $infoId,
                        'planteldocentes_id'     => $docenteId,
                        'tipo_asignacion'        => $tipo,
                        'evaluacion'             => $eval,
                        'fecha'                  => $fecha,
                        'avance_texto'           => $ultimaAnterior->avance_texto ?? '',
                        'estrellas'              => 0,
                        'sugerencia'             => 'NO VINO A CLASES',
                        'asistencia'             => 'F',
                    ]);
                    $idsCreados[] = (int) $sesion->id;
                    $creadas++;
                }
            }
        }

        // Registrar log para poder deshacer
        if ($creadas > 0) {
            TerminarClaseLog::create([
                'planteldocentes_id'   => (int) $user->id,
                'instituciones_id'     => (int) $user->instituciones_id,
                'tipo_asignacion'      => $tipo,
                'evaluacion'           => $eval,
                'fecha'                => now()->toDateString(),
                'cursos_json'          => json_encode(['ESTABILIZAR']),
                'sesiones_creadas_ids' => json_encode($idsCreados),
                'cantidad_creadas'     => $creadas,
            ]);
        }

        return response()->json([
            'message' => "Estabilización completada. Se crearon $creadas registros de falta.",
            'creadas' => $creadas,
        ]);
    }

    /**
     * Deshacer última estabilización del docente (hoy).
     * Elimina las sesiones-falta creadas.
     */
    public function deshacerEstabilizar(Request $request)
    {
        $user = $request->user();
        if (!($user instanceof Planteldocentes)) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $hoy = now()->toDateString();

        $log = TerminarClaseLog::where('planteldocentes_id', (int) $user->id)
            ->whereNull('deshecho_at')
            ->whereDate('created_at', $hoy)
            ->where('cursos_json', json_encode(['ESTABILIZAR']))
            ->orderByDesc('id')
            ->first();

        if (!$log) {
            return response()->json([
                'message' => 'No hay estabilizaciones para deshacer hoy.',
                'eliminadas' => 0,
            ], 404);
        }

        $ids = json_decode($log->sesiones_creadas_ids, true);
        $eliminadas = 0;

        if (is_array($ids) && !empty($ids)) {
            // Recopilar combinaciones afectadas
            $afectados = SesionAvanceEstudiantil::whereIn('id', $ids)
                ->where('planteldocentes_id', (int) $user->id)
                ->where('asistencia', 'F')
                ->get(['id', 'infoestudiantesifas_id', 'tipo_asignacion', 'evaluacion']);

            $eliminadas = SesionAvanceEstudiantil::whereIn('id', $ids)
                ->where('planteldocentes_id', (int) $user->id)
                ->where('asistencia', 'F')
                ->delete();
        }

        $log->update(['deshecho_at' => now(), 'deshecho_por' => (int) $user->id]);

        return response()->json([
            'message' => "Se deshizo la estabilización. Se eliminaron $eliminadas registros de falta.",
            'eliminadas' => $eliminadas,
            'log_id' => $log->id,
        ]);
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
