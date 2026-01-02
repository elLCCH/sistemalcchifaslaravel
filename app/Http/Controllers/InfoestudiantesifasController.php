<?php

namespace App\Http\Controllers;

use App\Models\Infoestudiantesifas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;
class InfoestudiantesifasController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }

    private function aplicarFiltroAnio($q, int $anioIdInt, bool $includeSinAsignar, string $anioScope = 'default'): void
    {
        $anioScope = strtolower(trim($anioScope));
        if (!in_array($anioScope, ['default', 'assigned', 'unassigned'], true)) {
            $anioScope = 'default';
        }

        if ($anioScope === 'unassigned') {
            // Solo SIN ASIGNAR: no tiene ningún año asignado (sin calificaciones)
            // Nota: este filtro no requiere anio_id.
            $q->whereNotExists(function ($qq) {
                $qq->select(DB::raw(1))
                    ->from('calificaciones as c')
                    ->join('materias as m', 'm.id', '=', 'c.materias_id')
                    ->join('plandeestudios as p', 'p.id', '=', 'm.plandeestudios_id')
                    ->whereColumn('c.infoestudiantesifas_id', 'infoestudiantesifas.id');
            });
            return;
        }

        if ($anioIdInt <= 0) return;

        if ($anioScope === 'assigned') {
            // Solo ASIGNADOS al año
            $q->whereExists(function ($qq) use ($anioIdInt) {
                $qq->select(DB::raw(1))
                    ->from('calificaciones as c')
                    ->join('materias as m', 'm.id', '=', 'c.materias_id')
                    ->join('plandeestudios as p', 'p.id', '=', 'm.plandeestudios_id')
                    ->whereColumn('c.infoestudiantesifas_id', 'infoestudiantesifas.id')
                    ->where('p.anio_id', $anioIdInt);
            });
            return;
        }

        if ($includeSinAsignar) {
            $q->where(function ($outer) use ($anioIdInt) {
                $outer->whereExists(function ($qq) use ($anioIdInt) {
                    $qq->select(DB::raw(1))
                        ->from('calificaciones as c')
                        ->join('materias as m', 'm.id', '=', 'c.materias_id')
                        ->join('plandeestudios as p', 'p.id', '=', 'm.plandeestudios_id')
                        ->whereColumn('c.infoestudiantesifas_id', 'infoestudiantesifas.id')
                        ->where('p.anio_id', $anioIdInt);
                })
                ->orWhereNotExists(function ($qq) {
                    $qq->select(DB::raw(1))
                        ->from('calificaciones as c')
                        ->join('materias as m', 'm.id', '=', 'c.materias_id')
                        ->join('plandeestudios as p', 'p.id', '=', 'm.plandeestudios_id')
                        ->whereColumn('c.infoestudiantesifas_id', 'infoestudiantesifas.id');
                });
            });
        } else {
            $q->whereExists(function ($qq) use ($anioIdInt) {
                $qq->select(DB::raw(1))
                    ->from('calificaciones as c')
                    ->join('materias as m', 'm.id', '=', 'c.materias_id')
                    ->join('plandeestudios as p', 'p.id', '=', 'm.plandeestudios_id')
                    ->whereColumn('c.infoestudiantesifas_id', 'infoestudiantesifas.id')
                    ->where('p.anio_id', $anioIdInt);
            });
        }
    }

    private function normalizarKey($value, string $fallback): string
    {
        $v = trim((string) ($value ?? ''));
        return $v !== '' ? $v : $fallback;
    }

    private function cursoEsTecnicoSuperior(string $cursoSolicitado): bool
    {
        return stripos($cursoSolicitado, 'SUPERIOR') !== false;
    }

    public function estadisticas(Request $request)
    {
        $anioId = (int) $request->query('anio_id', 0);
        if ($anioId <= 0) {
            return response()->json(['message' => 'anio_id es requerido'], 422);
        }

        $includeSinAsignar = filter_var($request->query('include_sin_asignar', '0'), FILTER_VALIDATE_BOOLEAN);
        $anioScope = (string) $request->query('anio_scope', 'default');

        $user = request()->user();
        $isSuperAdmin = empty($user?->instituciones_id);

        $base = Infoestudiantesifas::query()
            ->leftJoin('estudiantesifas', 'infoestudiantesifas.estudiantesifas_id', '=', 'estudiantesifas.id')
            ->when(!$isSuperAdmin && !empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('infoestudiantesifas.instituciones_id', $user->instituciones_id);
            });

        $this->aplicarFiltroAnio($base, $anioId, $includeSinAsignar, $anioScope);

        // Totales
        $total = (clone $base)->count();

        // =============================
        // Por Curso_Solicitado
        // =============================
        $cursos = (clone $base)
            ->selectRaw('infoestudiantesifas.Curso_Solicitado as curso, COUNT(*) as total')
            ->groupBy('infoestudiantesifas.Curso_Solicitado')
            ->orderByDesc('total')
            ->get();

        $cursoSexo = (clone $base)
            ->selectRaw('infoestudiantesifas.Curso_Solicitado as curso, UPPER(TRIM(COALESCE(estudiantesifas.Sexo, \'\'))) as sexo, COUNT(*) as total')
            ->groupBy('infoestudiantesifas.Curso_Solicitado', DB::raw('UPPER(TRIM(COALESCE(estudiantesifas.Sexo, \'\')))'))
            ->get();

        $cursoEdad = (clone $base)
            ->whereNotNull('estudiantesifas.Edad')
            ->whereRaw('TRIM(COALESCE(estudiantesifas.Edad, \'\')) <> \'\'')
            ->selectRaw('infoestudiantesifas.Curso_Solicitado as curso, CAST(estudiantesifas.Edad AS UNSIGNED) as edad, COUNT(*) as total')
            ->groupBy('infoestudiantesifas.Curso_Solicitado', DB::raw('CAST(estudiantesifas.Edad AS UNSIGNED)'))
            ->orderBy(DB::raw('CAST(estudiantesifas.Edad AS UNSIGNED)'))
            ->get();

        $cursoInstrumento = (clone $base)
            ->whereRaw("TRIM(COALESCE(infoestudiantesifas.InstrumentoMusical, '')) <> ''")
            ->selectRaw('infoestudiantesifas.Curso_Solicitado as curso, TRIM(infoestudiantesifas.InstrumentoMusical) as instrumento, COUNT(*) as total')
            ->groupBy('infoestudiantesifas.Curso_Solicitado', DB::raw('TRIM(infoestudiantesifas.InstrumentoMusical)'))
            ->orderByDesc('total')
            ->get();

        // =============================
        // Por Curso_Solicitado + Paralelo_Solicitado (solo donde hay paralelo)
        // =============================
        $basePar = (clone $base)->whereRaw("TRIM(COALESCE(infoestudiantesifas.Paralelo_Solicitado, '')) <> ''");

        $cursoPar = (clone $basePar)
            ->selectRaw('infoestudiantesifas.Curso_Solicitado as curso, TRIM(infoestudiantesifas.Paralelo_Solicitado) as paralelo, COUNT(*) as total')
            ->groupBy('infoestudiantesifas.Curso_Solicitado', DB::raw('TRIM(infoestudiantesifas.Paralelo_Solicitado)'))
            ->orderByDesc('total')
            ->get();

        $cursoParSexo = (clone $basePar)
            ->selectRaw('infoestudiantesifas.Curso_Solicitado as curso, TRIM(infoestudiantesifas.Paralelo_Solicitado) as paralelo, UPPER(TRIM(COALESCE(estudiantesifas.Sexo, \'\'))) as sexo, COUNT(*) as total')
            ->groupBy('infoestudiantesifas.Curso_Solicitado', DB::raw('TRIM(infoestudiantesifas.Paralelo_Solicitado)'), DB::raw('UPPER(TRIM(COALESCE(estudiantesifas.Sexo, \'\')))'))
            ->get();

        $cursoParEdad = (clone $basePar)
            ->whereNotNull('estudiantesifas.Edad')
            ->whereRaw('TRIM(COALESCE(estudiantesifas.Edad, \'\')) <> \'\'')
            ->selectRaw('infoestudiantesifas.Curso_Solicitado as curso, TRIM(infoestudiantesifas.Paralelo_Solicitado) as paralelo, CAST(estudiantesifas.Edad AS UNSIGNED) as edad, COUNT(*) as total')
            ->groupBy('infoestudiantesifas.Curso_Solicitado', DB::raw('TRIM(infoestudiantesifas.Paralelo_Solicitado)'), DB::raw('CAST(estudiantesifas.Edad AS UNSIGNED)'))
            ->orderBy(DB::raw('CAST(estudiantesifas.Edad AS UNSIGNED)'))
            ->get();

        $cursoParInstrumento = (clone $basePar)
            ->whereRaw("TRIM(COALESCE(infoestudiantesifas.InstrumentoMusical, '')) <> ''")
            ->selectRaw('infoestudiantesifas.Curso_Solicitado as curso, TRIM(infoestudiantesifas.Paralelo_Solicitado) as paralelo, TRIM(infoestudiantesifas.InstrumentoMusical) as instrumento, COUNT(*) as total')
            ->groupBy('infoestudiantesifas.Curso_Solicitado', DB::raw('TRIM(infoestudiantesifas.Paralelo_Solicitado)'), DB::raw('TRIM(infoestudiantesifas.InstrumentoMusical)'))
            ->orderByDesc('total')
            ->get();

        // ============
        // Armado de respuesta (por curso)
        // ============
        $porCurso = [];
        foreach ($cursos as $row) {
            $cursoKey = $this->normalizarKey($row->curso, '(SIN CURSO)');
            $porCurso[$cursoKey] = [
                'curso' => $cursoKey,
                'tipo' => $this->cursoEsTecnicoSuperior($cursoKey) ? 'TECNICO_SUPERIOR' : 'OTRO',
                'total' => (int) $row->total,
                'sexo' => [],
                'edades' => [],
                'instrumentos' => [],
            ];
        }

        foreach ($cursoSexo as $row) {
            $cursoKey = $this->normalizarKey($row->curso, '(SIN CURSO)');
            if (!isset($porCurso[$cursoKey])) {
                $porCurso[$cursoKey] = [
                    'curso' => $cursoKey,
                    'tipo' => $this->cursoEsTecnicoSuperior($cursoKey) ? 'TECNICO_SUPERIOR' : 'OTRO',
                    'total' => 0,
                    'sexo' => [],
                    'edades' => [],
                    'instrumentos' => [],
                ];
            }
            $sexoKey = $this->normalizarKey($row->sexo, 'SIN DATO');
            $porCurso[$cursoKey]['sexo'][$sexoKey] = (int) $row->total;
        }

        foreach ($cursoEdad as $row) {
            $cursoKey = $this->normalizarKey($row->curso, '(SIN CURSO)');
            if (!isset($porCurso[$cursoKey])) continue;
            $porCurso[$cursoKey]['edades'][] = [
                'edad' => (int) $row->edad,
                'total' => (int) $row->total,
            ];
        }

        foreach ($cursoInstrumento as $row) {
            $cursoKey = $this->normalizarKey($row->curso, '(SIN CURSO)');
            if (!isset($porCurso[$cursoKey])) continue;
            $inst = $this->normalizarKey($row->instrumento, '');
            if ($inst === '') continue;
            $porCurso[$cursoKey]['instrumentos'][] = [
                'instrumento' => $inst,
                'total' => (int) $row->total,
            ];
        }

        // Ocultar instrumentos si no hay nadie (dejar []), y convertir maps a arrays
        $porCursoOut = array_values($porCurso);

        // ============
        // Armado de respuesta (por curso+paralelo)
        // ============
        $porCursoPar = [];
        foreach ($cursoPar as $row) {
            $cursoKey = $this->normalizarKey($row->curso, '(SIN CURSO)');
            $parKey = $this->normalizarKey($row->paralelo, '');
            if ($parKey === '') continue;
            $key = $cursoKey . '||' . $parKey;
            $porCursoPar[$key] = [
                'curso' => $cursoKey,
                'paralelo' => $parKey,
                'tipo' => $this->cursoEsTecnicoSuperior($cursoKey) ? 'TECNICO_SUPERIOR' : 'OTRO',
                'total' => (int) $row->total,
                'sexo' => [],
                'edades' => [],
                'instrumentos' => [],
            ];
        }

        foreach ($cursoParSexo as $row) {
            $cursoKey = $this->normalizarKey($row->curso, '(SIN CURSO)');
            $parKey = $this->normalizarKey($row->paralelo, '');
            if ($parKey === '') continue;
            $key = $cursoKey . '||' . $parKey;
            if (!isset($porCursoPar[$key])) continue;
            $sexoKey = $this->normalizarKey($row->sexo, 'SIN DATO');
            $porCursoPar[$key]['sexo'][$sexoKey] = (int) $row->total;
        }

        foreach ($cursoParEdad as $row) {
            $cursoKey = $this->normalizarKey($row->curso, '(SIN CURSO)');
            $parKey = $this->normalizarKey($row->paralelo, '');
            if ($parKey === '') continue;
            $key = $cursoKey . '||' . $parKey;
            if (!isset($porCursoPar[$key])) continue;
            $porCursoPar[$key]['edades'][] = [
                'edad' => (int) $row->edad,
                'total' => (int) $row->total,
            ];
        }

        foreach ($cursoParInstrumento as $row) {
            $cursoKey = $this->normalizarKey($row->curso, '(SIN CURSO)');
            $parKey = $this->normalizarKey($row->paralelo, '');
            if ($parKey === '') continue;
            $key = $cursoKey . '||' . $parKey;
            if (!isset($porCursoPar[$key])) continue;
            $inst = $this->normalizarKey($row->instrumento, '');
            if ($inst === '') continue;
            $porCursoPar[$key]['instrumentos'][] = [
                'instrumento' => $inst,
                'total' => (int) $row->total,
            ];
        }

        $porCursoParOut = array_values($porCursoPar);

        return response()->json([
            'meta' => [
                'anio_id' => $anioId,
                'include_sin_asignar' => $includeSinAsignar ? 1 : 0,
                'anio_scope' => strtolower(trim($anioScope)),
                'total' => (int) $total,
            ],
            'por_curso' => $porCursoOut,
            'por_curso_paralelo' => $porCursoParOut,
        ]);
    }
    //controllerPHPlcch infoestudiantesifas, $
    //#region Inicio Controller de Crud PHP de infoestudiantesifas
    public function index(Request $request)
    {
        $perPage = (int) $request->query('per_page', 10);
        if ($perPage < 1) {
            $perPage = 10;
        }
        if ($perPage > 200) {
            $perPage = 200;
        }

        $search = trim((string) $request->query('search', ''));
        $searchMode = strtolower(trim((string) $request->query('search_mode', 'all')));
        if (!in_array($searchMode, ['any', 'all'], true)) {
            $searchMode = 'all';
        }
        $sortBy = (string) $request->query('sort_by', 'FechInsc');
        $sortDir = strtolower((string) $request->query('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $anioId = $request->query('anio_id');
        $anioScope = (string) $request->query('anio_scope', 'default');
        $estudianteId = $request->query('estudiante_id');
        $includeSinAsignar = filter_var($request->query('include_sin_asignar', '0'), FILTER_VALIDATE_BOOLEAN);
        $institucionId = $request->query('instituciones_id');

        // Campos permitidos para ordenar (alias "Anio" se maneja con orderByRaw)
        $allowedSort = [
            'id',
            'FechInsc',
            'NombreInstitucion',
            'Ap_Paterno',
            'Ap_Materno',
            'Nombre',
            'Anio',
            'Categoria',
            'Curso_Solicitado',
            'Paralelo_Solicitado',
            'CantidadMateriasAsignadas',
            'Observacion',
            'Verificacion',
        ];
        if (!in_array($sortBy, $allowedSort, true)) {
            $sortBy = 'FechInsc';
        }

        $user = request()->user();
        $isSuperAdmin = empty($user?->instituciones_id);

        // Subquery (mismo que en el select) para ordenar por Anio si se requiere
        $anioSubquery = "COALESCE((
            SELECT MAX(a.Anio)
            FROM calificaciones c
            INNER JOIN materias m ON m.id = c.materias_id
            INNER JOIN plandeestudios p ON p.id = m.plandeestudios_id
            INNER JOIN anios a ON a.id = p.anio_id
            WHERE c.infoestudiantesifas_id = infoestudiantesifas.id
        ), 'SIN ASIGNAR')";

        $carreraSubquery = "(
            SELECT ca.NombreCarrera
            FROM calificaciones c
            INNER JOIN materias m ON m.id = c.materias_id
            INNER JOIN plandeestudios p ON p.id = m.plandeestudios_id
            INNER JOIN carreras ca ON ca.id = p.carreras_id
            INNER JOIN anios a ON a.id = p.anio_id
            WHERE c.infoestudiantesifas_id = infoestudiantesifas.id
            ORDER BY a.Anio DESC, p.id DESC
            LIMIT 1
        )";

        $resolucionSubquery = "(
            SELECT ca.Resolucion
            FROM calificaciones c
            INNER JOIN materias m ON m.id = c.materias_id
            INNER JOIN plandeestudios p ON p.id = m.plandeestudios_id
            INNER JOIN carreras ca ON ca.id = p.carreras_id
            INNER JOIN anios a ON a.id = p.anio_id
            WHERE c.infoestudiantesifas_id = infoestudiantesifas.id
            ORDER BY a.Anio DESC, p.id DESC
            LIMIT 1
        )";

        $query = Infoestudiantesifas::query()
            ->leftJoin('instituciones', 'infoestudiantesifas.instituciones_id', '=', 'instituciones.id')
            ->leftJoin('estudiantesifas', 'infoestudiantesifas.estudiantesifas_id', '=', 'estudiantesifas.id')
            ->select([
                'infoestudiantesifas.*',
                $isSuperAdmin ? 'instituciones.Nombre as NombreInstitucion' : DB::raw('NULL as NombreInstitucion'),
                'estudiantesifas.Ap_Paterno',
                'estudiantesifas.Ap_Materno',
                'estudiantesifas.Nombre',
                'estudiantesifas.CI',
                DB::raw($anioSubquery . " as Anio"),
                DB::raw($carreraSubquery . " as NombreCarrera"),
                DB::raw($resolucionSubquery . " as Resolucion"),
            ])
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('infoestudiantesifas.instituciones_id', $user->instituciones_id);
            })
            ->when($isSuperAdmin && $institucionId !== null && $institucionId !== '' && (int) $institucionId > 0, function ($q) use ($institucionId) {
                $q->where('infoestudiantesifas.instituciones_id', (int) $institucionId);
            })
            ->when($estudianteId !== null && $estudianteId !== '' && (int) $estudianteId > 0, function ($q) use ($estudianteId) {
                $q->where('infoestudiantesifas.estudiantesifas_id', (int) $estudianteId);
            })
            ->when($search !== '', function ($q) use ($search, $searchMode) {
                $tokens = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                // Ojo: array_filter() sin callback elimina valores "falsy" como "0".
                // Necesitamos conservar "0" para búsquedas por cantidad de asignaciones.
                $tokens = array_values(array_filter(array_unique(array_map('trim', $tokens)), function ($t) {
                    return (string) $t !== '';
                }));
                if (count($tokens) === 0) return;

                // Caso especial: si el usuario busca un número corto (1-2 dígitos),
                // se asume que quiere filtrar por CantidadMateriasAsignadas exacta.
                // Ej: "0" => solo los que tienen 0 asignaciones.
                $shortNumericTokens = array_values(array_filter($tokens, function ($t) {
                    return preg_match('/^\d{1,2}$/', (string) $t);
                }));

                if (count($shortNumericTokens) === 1) {
                    $cantidad = (int) $shortNumericTokens[0];
                    $q->where('infoestudiantesifas.CantidadMateriasAsignadas', $cantidad);

                    // Quitar este token para que no se aplique también como LIKE.
                    $tokens = array_values(array_filter($tokens, function ($t) use ($shortNumericTokens) {
                        return (string) $t !== (string) $shortNumericTokens[0];
                    }));

                    // Si solo era ese token, ya quedó el filtro exacto.
                    if (count($tokens) === 0) return;
                }

                $applyToken = function ($qq, string $like, bool $includeCi) {
                    $qq->where('estudiantesifas.Ap_Paterno', 'like', $like)
                        ->orWhere('estudiantesifas.Ap_Materno', 'like', $like)
                        ->orWhere('estudiantesifas.Nombre', 'like', $like)
                        ->when($includeCi, function ($qqq) use ($like) {
                            $qqq->orWhere('estudiantesifas.CI', 'like', $like);
                        })
                        ->orWhere('infoestudiantesifas.Matricula', 'like', $like)
                        ->orWhere('infoestudiantesifas.Categoria', 'like', $like)
                        ->orWhere('infoestudiantesifas.Curso_Solicitado', 'like', $like)
                        ->orWhere('infoestudiantesifas.Paralelo_Solicitado', 'like', $like)
                        ->orWhereRaw("CONCAT(TRIM(COALESCE(infoestudiantesifas.Curso_Solicitado,'')),' ',TRIM(COALESCE(infoestudiantesifas.Paralelo_Solicitado,''))) LIKE ?", [$like])
                        ->orWhere('infoestudiantesifas.Observacion', 'like', $like)
                        ->orWhere('infoestudiantesifas.Verificacion', 'like', $like)
                        ->orWhere('infoestudiantesifas.Turno', 'like', $like)
                        ->orWhere('infoestudiantesifas.InstrumentoMusical', 'like', $like)
                        ->orWhere('infoestudiantesifas.InstrumentoMusicalSecundario', 'like', $like)
                        ->orWhere('instituciones.Nombre', 'like', $like)
                        ->orWhereRaw('CAST(infoestudiantesifas.CantidadMateriasAsignadas AS CHAR) LIKE ?', [$like]);
                };

                if ($searchMode === 'any') {
                    // OR por tokens: cualquier token puede aparecer en cualquiera de los campos.
                    $q->where(function ($outer) use ($tokens, $applyToken) {
                        foreach ($tokens as $token) {
                            $like = '%' . $token . '%';
                            $includeCi = !preg_match('/^\d{1,2}$/', (string) $token);
                            $outer->orWhere(function ($qq) use ($like, $applyToken, $includeCi) {
                                $applyToken($qq, $like, $includeCi);
                            });
                        }
                    });
                } else {
                    // AND por tokens (comportamiento anterior): cada token debe aparecer en algún campo.
                    foreach ($tokens as $token) {
                        $like = '%' . $token . '%';
                        $includeCi = !preg_match('/^\d{1,2}$/', (string) $token);
                        $q->where(function ($qq) use ($like, $applyToken, $includeCi) {
                            $applyToken($qq, $like, $includeCi);
                        });
                    }
                }
            });

        // Filtro por gestión/año (y/o SIN ASIGNAR)
        $anioIdInt = (int) ($anioId ?? 0);
        $this->aplicarFiltroAnio($query, $anioIdInt, $includeSinAsignar, $anioScope);

        // Ordenamiento (mapeando alias a columnas reales)
        if ($sortBy === 'NombreInstitucion') {
            $query->orderBy('instituciones.Nombre', $sortDir);
        } elseif ($sortBy === 'Ap_Paterno') {
            $query->orderBy('estudiantesifas.Ap_Paterno', $sortDir);
        } elseif ($sortBy === 'Ap_Materno') {
            $query->orderBy('estudiantesifas.Ap_Materno', $sortDir);
        } elseif ($sortBy === 'Nombre') {
            $query->orderBy('estudiantesifas.Nombre', $sortDir);
        } elseif ($sortBy === 'Anio') {
            $query->orderByRaw($anioSubquery . ' ' . $sortDir);
        } else {
            $query->orderBy('infoestudiantesifas.' . $sortBy, $sortDir);
        }

        // Orden secundario estable
        if ($sortBy !== 'id') {
            $query->orderByDesc('infoestudiantesifas.id');
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }


    public function byEstudiante($estudianteId)
    {
        $user = request()->user();
        $isSuperAdmin = empty($user?->instituciones_id);

        $carreraSubquery = "(
            SELECT ca.NombreCarrera
            FROM calificaciones c
            INNER JOIN materias m ON m.id = c.materias_id
            INNER JOIN plandeestudios p ON p.id = m.plandeestudios_id
            INNER JOIN carreras ca ON ca.id = p.carreras_id
            INNER JOIN anios a ON a.id = p.anio_id
            WHERE c.infoestudiantesifas_id = infoestudiantesifas.id
            ORDER BY a.Anio DESC, p.id DESC
            LIMIT 1
        )";

        $resolucionSubquery = "(
            SELECT ca.Resolucion
            FROM calificaciones c
            INNER JOIN materias m ON m.id = c.materias_id
            INNER JOIN plandeestudios p ON p.id = m.plandeestudios_id
            INNER JOIN carreras ca ON ca.id = p.carreras_id
            INNER JOIN anios a ON a.id = p.anio_id
            WHERE c.infoestudiantesifas_id = infoestudiantesifas.id
            ORDER BY a.Anio DESC, p.id DESC
            LIMIT 1
        )";

        $query = Infoestudiantesifas::query()
            ->leftJoin('instituciones', 'infoestudiantesifas.instituciones_id', '=', 'instituciones.id')
            ->leftJoin('estudiantesifas', 'infoestudiantesifas.estudiantesifas_id', '=', 'estudiantesifas.id')
            ->select([
                'infoestudiantesifas.*',
                $isSuperAdmin ? 'instituciones.Nombre as NombreInstitucion' : DB::raw('NULL as NombreInstitucion'),
                'estudiantesifas.Ap_Paterno',
                'estudiantesifas.Ap_Materno',
                'estudiantesifas.Nombre',
                DB::raw("COALESCE((
                    SELECT MAX(a.Anio)
                    FROM calificaciones c
                    INNER JOIN materias m ON m.id = c.materias_id
                    INNER JOIN plandeestudios p ON p.id = m.plandeestudios_id
                    INNER JOIN anios a ON a.id = p.anio_id
                    WHERE c.infoestudiantesifas_id = infoestudiantesifas.id
                ), 'SIN ASIGNAR') as Anio"),
                DB::raw($carreraSubquery . " as NombreCarrera"),
                DB::raw($resolucionSubquery . " as Resolucion"),
            ])
            ->where('infoestudiantesifas.estudiantesifas_id', $estudianteId)
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('infoestudiantesifas.instituciones_id', $user->instituciones_id);
            })
            ->orderByDesc('infoestudiantesifas.FechInsc')
            ->orderByDesc('infoestudiantesifas.id');

        $infoestudiantesifas = $query->get();
        return response()->json(['data' => $infoestudiantesifas]);
    }

    public function pendientesAsignacion(Request $request)
    {
        $perPage = (int) $request->query('per_page', 10);
        if ($perPage < 1) {
            $perPage = 10;
        }
        if ($perPage > 50) {
            $perPage = 50;
        }

        $search = trim((string) $request->query('search', ''));

        $user = request()->user();
        $isSuperAdmin = empty($user?->instituciones_id);
        $institucionId = $request->query('instituciones_id');

        // Subquery para mostrar gestión por asignaciones (si no hay asignaciones => SIN ASIGNAR)
        $anioSubquery = "COALESCE((
            SELECT MAX(a.Anio)
            FROM calificaciones c
            INNER JOIN materias m ON m.id = c.materias_id
            INNER JOIN plandeestudios p ON p.id = m.plandeestudios_id
            INNER JOIN anios a ON a.id = p.anio_id
            WHERE c.infoestudiantesifas_id = infoestudiantesifas.id
        ), 'SIN ASIGNAR')";

        $query = Infoestudiantesifas::query()
            ->leftJoin('instituciones', 'infoestudiantesifas.instituciones_id', '=', 'instituciones.id')
            ->leftJoin('estudiantesifas', 'infoestudiantesifas.estudiantesifas_id', '=', 'estudiantesifas.id')
            ->select([
                'infoestudiantesifas.*',
                $isSuperAdmin ? 'instituciones.Nombre as NombreInstitucion' : DB::raw('NULL as NombreInstitucion'),
                'estudiantesifas.Ap_Paterno',
                'estudiantesifas.Ap_Materno',
                'estudiantesifas.Nombre',
                'estudiantesifas.CI',
                DB::raw($anioSubquery . " as Anio"),
            ])
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('infoestudiantesifas.instituciones_id', $user->instituciones_id);
            })
            ->when($isSuperAdmin && $institucionId !== null && $institucionId !== '' && (int) $institucionId > 0, function ($q) use ($institucionId) {
                $q->where('infoestudiantesifas.instituciones_id', (int) $institucionId);
            })
            // Pendientes = sin ninguna asignación en calificaciones
            ->whereNotExists(function ($qq) {
                $qq->select(DB::raw(1))
                    ->from('calificaciones as c')
                    ->whereColumn('c.infoestudiantesifas_id', 'infoestudiantesifas.id');
            })
            ->when($search !== '', function ($q) use ($search) {
                $like = '%' . $search . '%';
                $q->where(function ($qq) use ($like) {
                    $qq->where('estudiantesifas.Ap_Paterno', 'like', $like)
                        ->orWhere('estudiantesifas.Ap_Materno', 'like', $like)
                        ->orWhere('estudiantesifas.Nombre', 'like', $like)
                        ->orWhere('estudiantesifas.CI', 'like', $like)
                        ->orWhere('instituciones.Nombre', 'like', $like)
                        ->orWhere('infoestudiantesifas.Curso_Solicitado', 'like', $like)
                        ->orWhere('infoestudiantesifas.Paralelo_Solicitado', 'like', $like)
                        ->orWhere('infoestudiantesifas.Turno', 'like', $like);
                });
            })
            ->orderByDesc('infoestudiantesifas.FechInsc')
            ->orderByDesc('infoestudiantesifas.id');

        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }
    
    
    public function store(Request $request)
    {
        $infoestudiantesifas = $request->all();
        $user = request()->user();
        if ($user->instituciones_id) {
            $infoestudiantesifas['instituciones_id'] = $user->instituciones_id;
        }
        Infoestudiantesifas::insert($infoestudiantesifas);
        return response()->json(['data' => $infoestudiantesifas]);
    }
    
    public function show($id)
    {
        $user = request()->user();
        $infoestudiantesifas = Infoestudiantesifas::query()
            ->where('id', '=', $id)
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('instituciones_id', $user->instituciones_id);
            })
            ->firstOrFail();
        return response()->json(['data' => $infoestudiantesifas]);
    }
    
    
    public function update(Request $request)
    {
        $user = $request->user();

        $row = Infoestudiantesifas::query()
            ->where('id', '=', $request->id)
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('instituciones_id', $user->instituciones_id);
            })
            ->firstOrFail();

        $payload = $request->all();
        if (!empty($user?->instituciones_id)) {
            $payload['instituciones_id'] = $user->instituciones_id;
        }

        $row->update($payload);
        return response()->json(['data' => $row]);
    }
    
    public function destroy($id)
    {
        $user = request()->user();
        $row = Infoestudiantesifas::query()
            ->where('id', '=', $id)
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('instituciones_id', $user->instituciones_id);
            })
            ->firstOrFail();

        $row->delete();
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
    //#endregion Fin Controller de Crud PHP de infoestudiantesifas
}
