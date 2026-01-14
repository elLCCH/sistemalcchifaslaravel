<?php

namespace App\Http\Controllers;

use App\Http\Middleware\UpdateTokenExpiration;
use App\Models\Califhistorias;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class CalifhistoriasConsultaController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }

    private function perPage(Request $request): int
    {
        $perPage = (int) $request->query('per_page', 25);
        if ($perPage < 1) {
            $perPage = 25;
        }
        if ($perPage > 200) {
            $perPage = 200;
        }
        return $perPage;
    }

    private function page(Request $request): int
    {
        $page = (int) $request->query('page', 1);
        return $page < 1 ? 1 : $page;
    }

    private function passMark(Request $request): float
    {
        // Nota mínima para aprobar. Por defecto 61.
        $pass = (float) $request->query('pass_mark', 61);
        if ($pass < 0) {
            $pass = 0;
        }
        if ($pass > 100) {
            $pass = 100;
        }
        return $pass;
    }

    private function applyFilters($query, array $filters)
    {
        return $query
            ->when(!empty($filters['anio'] ?? null), fn($q) => $q->where('Anio', $filters['anio']))
            ->when(!empty($filters['malla'] ?? null), fn($q) => $q->where('Malla', $filters['malla']))
            ->when(!empty($filters['nivel_curso'] ?? null), fn($q) => $q->where('NivelCurso', $filters['nivel_curso']))
            ->when(!empty($filters['nombre_curso'] ?? null), fn($q) => $q->where('NombreCurso', $filters['nombre_curso']))
            ->when(!empty($filters['docente_materia'] ?? null), fn($q) => $q->where('DocenteMateria', $filters['docente_materia']))
            ->when(!empty($filters['docente_especialidad'] ?? null), fn($q) => $q->where('Docente_Especialidad', $filters['docente_especialidad']))
            ->when(!empty($filters['ci'] ?? null), fn($q) => $q->where('CI', $filters['ci']));
    }

    private function numCast(string $column): string
    {
        // Columnas están como varchar. Convertimos de forma segura.
        // NULLIF evita castear '' a 0.
        return "CAST(NULLIF($column,'') AS DECIMAL(10,2))";
    }

    private function abandonoExpr(float $pass): array
    {
        $prom = $this->numCast('Promedio');
        $rec = $this->numCast('PruebaRecuperacion');

        // Abandono: Promedio=0 y NO tiene recup (NULL o 0).
        // Si recup existe (>0) aunque Promedio sea 0, lo tratamos como no abandono.
        $abandono = "($prom = 0 AND ($rec IS NULL OR $rec = 0))";

        // Aprobado: no abandono y (Promedio>=pass o Recup>=pass)
        $aprobado = "(NOT $abandono AND (($prom >= $pass) OR ($rec >= $pass)))";

        // Reprobado: no abandono y (Promedio<pass y Recup < pass o NULL)
        $reprobado = "(NOT $abandono AND ($prom < $pass) AND ($rec IS NULL OR $rec < $pass))";

        // Nota efectiva (para promedio general): si abandono => NULL, si no => max(prom, recup)
        $efectiva = "(CASE WHEN $abandono THEN NULL ELSE GREATEST($prom, COALESCE($rec, -1)) END)";

        return [
            'abandono' => $abandono,
            'aprobado' => $aprobado,
            'reprobado' => $reprobado,
            'efectiva' => $efectiva,
        ];
    }

    private function orderByNombreNullFirst($query)
    {
        // NULL primero, luego alfabético.
        return $query->orderByRaw(
            'Ap_Paterno IS NULL DESC, Ap_Paterno ASC, Ap_Materno IS NULL DESC, Ap_Materno ASC, Nombre IS NULL DESC, Nombre ASC'
        );
    }

    // =====================================================
    // OPCIONES PARA SELECTS (DISTINCT)
    // =====================================================

    public function opcionesInstituciones()
    {
        $items = Califhistorias::query()
            ->select('Institucion')
            ->whereNotNull('Institucion')
            ->where('Institucion', '<>', '')
            ->distinct()
            ->orderBy('Institucion')
            ->pluck('Institucion');

        return response()->json(['data' => $items]);
    }

    public function opcionesAnios(Request $request)
    {
        $validated = $request->validate([
            'institucion' => ['required', 'string'],
        ]);

        $items = Califhistorias::query()
            ->select('Anio')
            ->where('Institucion', $validated['institucion'])
            ->whereNotNull('Anio')
            ->where('Anio', '<>', '')
            ->distinct()
            ->orderBy('Anio')
            ->pluck('Anio');

        return response()->json(['data' => $items]);
    }

    public function opcionesMallas(Request $request)
    {
        $validated = $request->validate([
            'institucion' => ['required', 'string'],
            'anio' => ['nullable', 'string'],
        ]);

        $items = Califhistorias::query()
            ->select('Malla')
            ->where('Institucion', $validated['institucion'])
            ->when(!empty($validated['anio']), fn($q) => $q->where('Anio', $validated['anio']))
            ->whereNotNull('Malla')
            ->where('Malla', '<>', '')
            ->distinct()
            ->orderBy('Malla')
            ->pluck('Malla');

        return response()->json(['data' => $items]);
    }

    public function opcionesNiveles(Request $request)
    {
        $validated = $request->validate([
            'institucion' => ['required', 'string'],
            'anio' => ['nullable', 'string'],
            'malla' => ['nullable', 'string'],
        ]);

        $items = Califhistorias::query()
            ->select('NivelCurso')
            ->where('Institucion', $validated['institucion'])
            ->when(!empty($validated['anio']), fn($q) => $q->where('Anio', $validated['anio']))
            ->when(!empty($validated['malla']), fn($q) => $q->where('Malla', $validated['malla']))
            ->whereNotNull('NivelCurso')
            ->where('NivelCurso', '<>', '')
            ->distinct()
            ->orderBy('NivelCurso')
            ->pluck('NivelCurso');

        return response()->json(['data' => $items]);
    }

    public function opcionesCursos(Request $request)
    {
        $validated = $request->validate([
            'institucion' => ['required', 'string'],
            'anio' => ['nullable', 'string'],
            'malla' => ['nullable', 'string'],
            'nivel_curso' => ['nullable', 'string'],
        ]);

        $items = Califhistorias::query()
            ->select('NombreCurso')
            ->where('Institucion', $validated['institucion'])
            ->when(!empty($validated['anio']), fn($q) => $q->where('Anio', $validated['anio']))
            ->when(!empty($validated['malla']), fn($q) => $q->where('Malla', $validated['malla']))
            ->when(!empty($validated['nivel_curso']), fn($q) => $q->where('NivelCurso', $validated['nivel_curso']))
            ->whereNotNull('NombreCurso')
            ->where('NombreCurso', '<>', '')
            ->distinct()
            ->orderBy('NombreCurso')
            ->pluck('NombreCurso');

        return response()->json(['data' => $items]);
    }

    public function opcionesDocentes(Request $request)
    {
        $validated = $request->validate([
            'institucion' => ['required', 'string'],
            'anio' => ['nullable', 'string'],
            'malla' => ['nullable', 'string'],
        ]);

        $items = Califhistorias::query()
            ->select('DocenteMateria')
            ->where('Institucion', $validated['institucion'])
            ->when(!empty($validated['anio']), fn($q) => $q->where('Anio', $validated['anio']))
            ->when(!empty($validated['malla']), fn($q) => $q->where('Malla', $validated['malla']))
            ->whereNotNull('DocenteMateria')
            ->where('DocenteMateria', '<>', '')
            ->distinct()
            ->orderBy('DocenteMateria')
            ->pluck('DocenteMateria');

        return response()->json(['data' => $items]);
    }

    public function opcionesDocentesEspecialidad(Request $request)
    {
        $validated = $request->validate([
            'institucion' => ['required', 'string'],
            'anio' => ['nullable', 'string'],
            'malla' => ['nullable', 'string'],
        ]);

        $items = Califhistorias::query()
            ->select('Docente_Especialidad')
            ->where('Institucion', $validated['institucion'])
            ->where('NombreCurso', 'like', '%INSTRUMENTO DE ESPECIALIDAD%')
            ->when(!empty($validated['anio']), fn($q) => $q->where('Anio', $validated['anio']))
            ->when(!empty($validated['malla']), fn($q) => $q->where('Malla', $validated['malla']))
            ->whereNotNull('Docente_Especialidad')
            ->where('Docente_Especialidad', '<>', '')
            ->distinct()
            ->orderBy('Docente_Especialidad')
            ->pluck('Docente_Especialidad');

        return response()->json(['data' => $items]);
    }

    public function opcionesCIs(Request $request)
    {
        $validated = $request->validate([
            'institucion' => ['required', 'string'],
            'q' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer'],
        ]);

        $limit = (int) ($validated['limit'] ?? 200);
        if ($limit < 1) {
            $limit = 200;
        }
        if ($limit > 2000) {
            $limit = 2000;
        }

        $q = trim((string) ($validated['q'] ?? ''));

        $items = Califhistorias::query()
            ->select('CI')
            ->where('Institucion', $validated['institucion'])
            ->whereNotNull('CI')
            ->where('CI', '<>', '')
            ->when($q !== '', fn($qq) => $qq->where('CI', 'like', "%{$q}%"))
            ->distinct()
            ->orderBy('CI')
            ->limit($limit)
            ->pluck('CI');

        return response()->json(['data' => $items]);
    }

    // =====================================================
    // CONSULTAS (PAGINADAS) - SOLO LECTURA
    // =====================================================

    public function porCurso(Request $request)
    {
        $validated = $request->validate([
            'institucion' => ['required', 'string'],
            'anio' => ['nullable', 'string'],
            'malla' => ['nullable', 'string'],
            'nivel_curso' => ['nullable', 'string'],
            'nombre_curso' => ['nullable', 'string'],
        ]);

        $query = Califhistorias::query()
            ->where('Institucion', $validated['institucion']);

        $query = $this->applyFilters($query, $validated)
            ->orderBy('Anio')
            ->orderBy('Malla')
            ->orderBy('NivelCurso')
            ->orderBy('NombreCurso');

        $query = $this->orderByNombreNullFirst($query);

        $items = $query->paginate($this->perPage($request), ['*'], 'page', $this->page($request));
        return response()->json(['data' => $items]);
    }

    public function estudiantesPorNombre(Request $request)
    {
        $validated = $request->validate([
            'institucion' => ['required', 'string'],
            'nombre' => ['required', 'string'],
            'limit' => ['nullable', 'integer'],
        ]);

        $limit = (int) ($validated['limit'] ?? 50);
        if ($limit < 1) {
            $limit = 50;
        }
        if ($limit > 300) {
            $limit = 300;
        }

        $nombre = trim((string) $validated['nombre']);
        $tokens = preg_split('/\s+/', $nombre, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $query = Califhistorias::query()
            ->select([
                'CI',
                'Ap_Paterno',
                'Ap_Materno',
                'Nombre',
            ])
            ->where('Institucion', $validated['institucion'])
            ->whereNotNull('CI')
            ->where('CI', '<>', '')
            ->distinct();

        foreach ($tokens as $t) {
            $query->where(function ($q) use ($t) {
                $q->where('Ap_Paterno', 'like', "%{$t}%")
                    ->orWhere('Ap_Materno', 'like', "%{$t}%")
                    ->orWhere('Nombre', 'like', "%{$t}%")
                    ->orWhere('CI', 'like', "%{$t}%");
            });
        }

        $query = $this->orderByNombreNullFirst($query)->orderBy('CI');

        $items = $query->limit($limit)->get();

        return response()->json(['data' => $items]);
    }

    public function porDocente(Request $request)
    {
        $validated = $request->validate([
            'institucion' => ['required', 'string'],
            'anio' => ['nullable', 'string'],
            'malla' => ['nullable', 'string'],
            'docente_materia' => ['nullable', 'string'],
        ]);

        $query = Califhistorias::query()
            ->where('Institucion', $validated['institucion']);

        $query = $this->applyFilters($query, $validated)
            ->orderBy('Anio')
            ->orderBy('Malla')
            ->orderBy('NivelCurso')
            ->orderBy('NombreCurso');

        $query = $this->orderByNombreNullFirst($query);

        $items = $query->paginate($this->perPage($request), ['*'], 'page', $this->page($request));
        return response()->json(['data' => $items]);
    }

    public function porDocenteEspecialidad(Request $request)
    {
        $validated = $request->validate([
            'institucion' => ['required', 'string'],
            'anio' => ['nullable', 'string'],
            'malla' => ['nullable', 'string'],
            'docente_especialidad' => ['nullable', 'string'],
        ]);

        $query = Califhistorias::query()
            ->where('Institucion', $validated['institucion']);

        // Tipo 11: solo la materia que contiene "INSTRUMENTO DE ESPECIALIDAD"
        $query->where('NombreCurso', 'like', '%INSTRUMENTO DE ESPECIALIDAD%');

        $query = $this->applyFilters($query, $validated)
            ->orderBy('Anio')
            ->orderBy('Malla')
            ->orderBy('NivelCurso')
            ->orderBy('NombreCurso');

        $query = $this->orderByNombreNullFirst($query);

        $items = $query->paginate($this->perPage($request), ['*'], 'page', $this->page($request));
        return response()->json(['data' => $items]);
    }

    public function porEstudiante(Request $request)
    {
        $validated = $request->validate([
            'institucion' => ['required', 'string'],
            'ci' => ['required', 'string'],
        ]);

        $query = Califhistorias::query()
            ->where('Institucion', $validated['institucion'])
            ->where('CI', $validated['ci'])
            ->orderBy('Anio')
            ->orderBy('Malla')
            ->orderBy('NivelCurso')
            // Orden del curso: según Rango (1,2,3,...) y luego NombreCurso.
            ->orderByRaw('CAST(NULLIF(Rango,\'\') AS UNSIGNED) ASC')
            ->orderBy('NombreCurso');

        $items = $query->paginate($this->perPage($request), ['*'], 'page', $this->page($request));
        return response()->json(['data' => $items]);
    }

    public function estudiantesPorCi(Request $request)
    {
        $validated = $request->validate([
            'institucion' => ['required', 'string'],
            'q' => ['required', 'string'],
            'limit' => ['nullable', 'integer'],
        ]);

        $limit = (int) ($validated['limit'] ?? 50);
        if ($limit < 1) {
            $limit = 50;
        }
        if ($limit > 300) {
            $limit = 300;
        }

        $q = trim((string) $validated['q']);

        $query = Califhistorias::query()
            ->select([
                'CI',
                'Ap_Paterno',
                'Ap_Materno',
                'Nombre',
            ])
            ->where('Institucion', $validated['institucion'])
            ->whereNotNull('CI')
            ->where('CI', '<>', '')
            ->where('CI', 'like', "%{$q}%")
            ->distinct();

        $query = $this->orderByNombreNullFirst($query)->orderBy('CI');

        return response()->json(['data' => $query->limit($limit)->get()]);
    }

    // =====================================================
    // ESTADISTICAS
    // =====================================================

    public function estadisticasGeneral(Request $request)
    {
        $validated = $request->validate([
            'institucion' => ['required', 'string'],
            'anio' => ['nullable', 'string'],
            'malla' => ['nullable', 'string'],
        ]);

        $pass = $this->passMark($request);
        $expr = $this->abandonoExpr($pass);

        $base = Califhistorias::query()
            ->where('Institucion', $validated['institucion']);

        $base = $this->applyFilters($base, $validated);

        $stats = $base->clone()
            ->selectRaw('COUNT(*) as total_registros')
            ->selectRaw('COUNT(DISTINCT CI) as total_estudiantes')
            ->selectRaw('AVG(' . $this->numCast('PromEvT') . ') as prom_teorica')
            ->selectRaw('AVG(' . $this->numCast('PromEvP') . ') as prom_practica')
            ->selectRaw('AVG(' . $expr['efectiva'] . ') as prom_general')
            ->selectRaw('SUM(CASE WHEN ' . $expr['abandono'] . ' THEN 1 ELSE 0 END) as abandonos')
            ->selectRaw('SUM(CASE WHEN ' . $expr['aprobado'] . ' THEN 1 ELSE 0 END) as aprobados')
            ->selectRaw('SUM(CASE WHEN ' . $expr['reprobado'] . ' THEN 1 ELSE 0 END) as reprobados')
            ->first();

        $porCurso = $base->clone()
            ->select([
                'NombreCurso',
                'NivelCurso',
                DB::raw('COUNT(*) as total_registros'),
                DB::raw('COUNT(DISTINCT CI) as total_estudiantes'),
                DB::raw('AVG(' . $expr['efectiva'] . ') as prom_general'),
                DB::raw('SUM(CASE WHEN ' . $expr['abandono'] . ' THEN 1 ELSE 0 END) as abandonos'),
                DB::raw('SUM(CASE WHEN ' . $expr['aprobado'] . ' THEN 1 ELSE 0 END) as aprobados'),
                DB::raw('SUM(CASE WHEN ' . $expr['reprobado'] . ' THEN 1 ELSE 0 END) as reprobados'),
            ])
            ->groupBy('NombreCurso', 'NivelCurso')
            ->orderByDesc('total_estudiantes')
            ->orderBy('NombreCurso')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => [
                'filtros' => [
                    'institucion' => $validated['institucion'],
                    'anio' => $validated['anio'] ?? null,
                    'malla' => $validated['malla'] ?? null,
                    'pass_mark' => $pass,
                ],
                'general' => $stats,
                'por_curso' => $porCurso,
            ],
        ]);
    }

    public function estadisticasPorDocente(Request $request)
    {
        $validated = $request->validate([
            'institucion' => ['required', 'string'],
            'anio' => ['nullable', 'string'],
            'malla' => ['nullable', 'string'],
            'docente_materia' => ['nullable', 'string'],
        ]);

        $pass = $this->passMark($request);
        $expr = $this->abandonoExpr($pass);

        $base = Califhistorias::query()
            ->where('Institucion', $validated['institucion']);

        $base = $this->applyFilters($base, $validated);

        $stats = $base->clone()
            ->selectRaw('COUNT(*) as total_registros')
            ->selectRaw('COUNT(DISTINCT CI) as total_estudiantes')
            ->selectRaw('AVG(' . $this->numCast('PromEvT') . ') as prom_teorica')
            ->selectRaw('AVG(' . $this->numCast('PromEvP') . ') as prom_practica')
            ->selectRaw('AVG(' . $expr['efectiva'] . ') as prom_general')
            ->selectRaw('SUM(CASE WHEN ' . $expr['abandono'] . ' THEN 1 ELSE 0 END) as abandonos')
            ->selectRaw('SUM(CASE WHEN ' . $expr['aprobado'] . ' THEN 1 ELSE 0 END) as aprobados')
            ->selectRaw('SUM(CASE WHEN ' . $expr['reprobado'] . ' THEN 1 ELSE 0 END) as reprobados')
            ->first();

        $porCurso = $base->clone()
            ->select([
                'NombreCurso',
                'NivelCurso',
                DB::raw('COUNT(*) as total_registros'),
                DB::raw('COUNT(DISTINCT CI) as total_estudiantes'),
                DB::raw('AVG(' . $expr['efectiva'] . ') as prom_general'),
                DB::raw('SUM(CASE WHEN ' . $expr['abandono'] . ' THEN 1 ELSE 0 END) as abandonos'),
                DB::raw('SUM(CASE WHEN ' . $expr['aprobado'] . ' THEN 1 ELSE 0 END) as aprobados'),
                DB::raw('SUM(CASE WHEN ' . $expr['reprobado'] . ' THEN 1 ELSE 0 END) as reprobados'),
            ])
            ->groupBy('NombreCurso', 'NivelCurso')
            ->orderByDesc('total_estudiantes')
            ->orderBy('NombreCurso')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => [
                'filtros' => [
                    'institucion' => $validated['institucion'],
                    'anio' => $validated['anio'] ?? null,
                    'malla' => $validated['malla'] ?? null,
                    'docente_materia' => $validated['docente_materia'] ?? null,
                    'pass_mark' => $pass,
                ],
                'general' => $stats,
                'por_curso' => $porCurso,
            ],
        ]);
    }

    public function estadisticasPorEstudiante(Request $request)
    {
        $validated = $request->validate([
            'institucion' => ['required', 'string'],
            'ci' => ['required', 'string'],
        ]);

        $pass = $this->passMark($request);
        $expr = $this->abandonoExpr($pass);

        $base = Califhistorias::query()
            ->where('Institucion', $validated['institucion'])
            ->where('CI', $validated['ci']);

        $stats = $base->clone()
            ->selectRaw('COUNT(*) as total_registros')
            ->selectRaw('AVG(' . $this->numCast('PromEvT') . ') as prom_teorica')
            ->selectRaw('AVG(' . $this->numCast('PromEvP') . ') as prom_practica')
            ->selectRaw('AVG(' . $expr['efectiva'] . ') as prom_general')
            ->selectRaw('SUM(CASE WHEN ' . $expr['abandono'] . ' THEN 1 ELSE 0 END) as abandonos')
            ->selectRaw('SUM(CASE WHEN ' . $expr['aprobado'] . ' THEN 1 ELSE 0 END) as aprobados')
            ->selectRaw('SUM(CASE WHEN ' . $expr['reprobado'] . ' THEN 1 ELSE 0 END) as reprobados')
            ->first();

        $porAnio = $base->clone()
            ->select([
                'Anio',
                'Malla',
                DB::raw('COUNT(*) as total_registros'),
                DB::raw('AVG(' . $expr['efectiva'] . ') as prom_general'),
                DB::raw('SUM(CASE WHEN ' . $expr['abandono'] . ' THEN 1 ELSE 0 END) as abandonos'),
                DB::raw('SUM(CASE WHEN ' . $expr['aprobado'] . ' THEN 1 ELSE 0 END) as aprobados'),
                DB::raw('SUM(CASE WHEN ' . $expr['reprobado'] . ' THEN 1 ELSE 0 END) as reprobados'),
            ])
            ->groupBy('Anio', 'Malla')
            ->orderBy('Anio')
            ->orderBy('Malla')
            ->get();

        return response()->json([
            'data' => [
                'filtros' => [
                    'institucion' => $validated['institucion'],
                    'ci' => $validated['ci'],
                    'pass_mark' => $pass,
                ],
                'general' => $stats,
                'por_anio_malla' => $porAnio,
            ],
        ]);
    }

    public function estadisticasPorCurso(Request $request)
    {
        $validated = $request->validate([
            'institucion' => ['required', 'string'],
            'anio' => ['nullable', 'string'],
            'malla' => ['nullable', 'string'],
            // Ajuste pedido: #7 por NivelCurso
            'nivel_curso' => ['required', 'string'],
        ]);

        $pass = $this->passMark($request);
        $expr = $this->abandonoExpr($pass);

        $base = Califhistorias::query()
            ->where('Institucion', $validated['institucion']);

        $base = $this->applyFilters($base, $validated);

        $stats = $base->clone()
            ->selectRaw('COUNT(*) as total_registros')
            ->selectRaw('COUNT(DISTINCT CI) as total_estudiantes')
            ->selectRaw('AVG(' . $this->numCast('PromEvT') . ') as prom_teorica')
            ->selectRaw('AVG(' . $this->numCast('PromEvP') . ') as prom_practica')
            ->selectRaw('AVG(' . $expr['efectiva'] . ') as prom_general')
            ->selectRaw('SUM(CASE WHEN ' . $expr['abandono'] . ' THEN 1 ELSE 0 END) as abandonos')
            ->selectRaw('SUM(CASE WHEN ' . $expr['aprobado'] . ' THEN 1 ELSE 0 END) as aprobados')
            ->selectRaw('SUM(CASE WHEN ' . $expr['reprobado'] . ' THEN 1 ELSE 0 END) as reprobados')
            ->first();

        // Dentro del nivel, puede ser útil ver por curso (NombreCurso)
        $porDocente = $base->clone()
            ->select([
                'DocenteMateria',
                DB::raw('COUNT(*) as total_registros'),
                DB::raw('COUNT(DISTINCT CI) as total_estudiantes'),
                DB::raw('AVG(' . $expr['efectiva'] . ') as prom_general'),
                DB::raw('SUM(CASE WHEN ' . $expr['abandono'] . ' THEN 1 ELSE 0 END) as abandonos'),
                DB::raw('SUM(CASE WHEN ' . $expr['aprobado'] . ' THEN 1 ELSE 0 END) as aprobados'),
                DB::raw('SUM(CASE WHEN ' . $expr['reprobado'] . ' THEN 1 ELSE 0 END) as reprobados'),
            ])
            ->groupBy('DocenteMateria')
            ->orderByDesc('total_estudiantes')
            ->orderBy('DocenteMateria')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => [
                'filtros' => [
                    'institucion' => $validated['institucion'],
                    'anio' => $validated['anio'] ?? null,
                    'malla' => $validated['malla'] ?? null,
                    'nivel_curso' => $validated['nivel_curso'],
                    'pass_mark' => $pass,
                ],
                'general' => $stats,
                'por_docente' => $porDocente,
            ],
        ]);
    }
}
