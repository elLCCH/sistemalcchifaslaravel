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

    private function digitsLikePattern(string $digits): string
    {
        // Permite coincidencia aunque el CI tenga separadores o sufijos.
        // Ej: 80287731 => %8%0%2%8%7%7%3%1%
        $digits = preg_replace('/\D+/', '', $digits) ?? '';
        if ($digits === '') {
            return '%';
        }
        return '%' . implode('%', str_split($digits)) . '%';
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

        $ci = trim((string) $validated['ci']);

        $query = Califhistorias::query()
            ->where('Institucion', $validated['institucion']);

        // Si el CI recibido es numérico (o fue normalizado a solo dígitos desde frontend),
        // buscamos por secuencia de dígitos para soportar CIs con separadores/sufijos.
        // Ej: 80287731 debe encontrar 8028773-1H.
        if ($ci !== '' && preg_match('/^\d+$/', $ci)) {
            $query->where('CI', 'like', $this->digitsLikePattern($ci));
        } else {
            $query->where('CI', $ci);
        }

        $query
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
        $qDigits = preg_replace('/\D+/', '', $q) ?? '';

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
            ->where(function ($sub) use ($q, $qDigits) {
                // Si el usuario escribe el CI completo (con guión/letras), respetamos substring.
                if ($q !== '') {
                    $sub->where('CI', 'like', "%{$q}%");
                }

                // Si vienen solo dígitos (o el CI contiene separadores/sufijos),
                // hacemos match por secuencia de dígitos.
                if ($qDigits !== '' && strlen($qDigits) >= 3) {
                    $sub->orWhere('CI', 'like', $this->digitsLikePattern($qDigits));
                }
            })
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

        $ci = trim((string) $validated['ci']);

        $pass = $this->passMark($request);
        $expr = $this->abandonoExpr($pass);

        $base = Califhistorias::query()
            ->where('Institucion', $validated['institucion']);

        if ($ci !== '' && preg_match('/^\d+$/', $ci)) {
            $base->where('CI', 'like', "%{$ci}%");
        } else {
            $base->where('CI', $ci);
        }

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

    // =====================================================
    // HISTORIAL UNIFICADO: historico (califhistorias) + nuevo sistema (calificaciones)
    // =====================================================

    public function historialUnificado(Request $request)
    {
        $validated = $request->validate([
            'ci'             => ['required', 'string'],
            'institucion'    => ['nullable', 'string'],
            'institucion_id' => ['nullable', 'integer'],
            'per_page'       => ['nullable', 'integer'],
        ]);

        $ciDigits = preg_replace('/\D+/', '', $validated['ci'] ?? '') ?? '';
        if ($ciDigits === '') {
            return response()->json(['data' => ['data' => [], 'total' => 0]]);
        }

        $ciPattern = $this->digitsLikePattern($ciDigits);
        $perPage   = min(max((int) ($validated['per_page'] ?? 300), 1), 1000);

        // --- 1. Datos históricos (califhistorias) ---
        $queryOld = Califhistorias::query()
            ->where('CI', 'like', $ciPattern);

        if (!empty($validated['institucion'])) {
            $queryOld->where('Institucion', $validated['institucion']);
        }

        $oldRows = $queryOld
            ->orderBy('Anio')
            ->orderByRaw("CAST(NULLIF(Rango,'') AS UNSIGNED) ASC")
            ->orderBy('NombreCurso')
            ->limit($perPage)
            ->get()
            ->map(fn ($r) => array_merge($r->toArray(), ['_fuente' => 'historico']));

        // --- 2. Datos del nuevo sistema (calificaciones) ---
        $newRows = collect([]);

        // Resolver institution_id
        $instId = null;
        if (!empty($validated['institucion_id'])) {
            $instId = (int) $validated['institucion_id'];
        } elseif (!empty($validated['institucion'])) {
            $instId = DB::table('instituciones')
                ->where('Nombre', $validated['institucion'])
                ->value('id');
        }

        if ($instId) {
            $newRows = DB::table('calificaciones as cal')
                ->join('infoestudiantesifas as info', 'cal.infoestudiantesifas_id', '=', 'info.id')
                ->join('estudiantesifas as est', 'info.estudiantesifas_id', '=', 'est.id')
                ->join('materias as mat', 'cal.materias_id', '=', 'mat.id')
                ->join('plandeestudios as pde', 'mat.plandeestudios_id', '=', 'pde.id')
                ->join('carreras as car', 'pde.carreras_id', '=', 'car.id')
                ->join('instituciones as ins', 'car.instituciones_id', '=', 'ins.id')
                ->join('anios as a', 'pde.anio_id', '=', 'a.id')
                ->leftJoin('planteldocentes as pd_esp', 'info.planteldocadmins_id', '=', 'pd_esp.id')
                ->where('car.instituciones_id', $instId)
                ->where('est.CI', 'like', $ciPattern)
                ->selectRaw("
                    ins.Nombre                                           AS Institucion,
                    a.Anio                                               AS Anio,
                    car.Resolucion                                       AS Malla,
                    'REGULAR'                                            AS Arrastre,
                    (SELECT NULLIF(TRIM(CONCAT(
                            COALESCE(pd2.Nombres,''), ' ',
                            COALESCE(pd2.Apellidos,''))), '')
                     FROM planteldocentesmaterias pdm2
                     JOIN planteldocentes pd2 ON pdm2.planteldocentes_id = pd2.id
                     WHERE pdm2.materias_id = mat.id
                     LIMIT 1)                                            AS DocenteMateria,
                    pde.LvlCurso                                         AS NivelCurso,
                    pde.NombreMateria                                    AS NombreCurso,
                    pde.SiglaMateria                                     AS Sigla,
                    CAST(pde.Rango AS CHAR)                              AS Rango,
                    pde.TipoMateria                                      AS Tipo,
                    CAST(pde.Horas AS CHAR)                              AS Horas,
                    'NUEVO'                                              AS Categoria,
                    NULLIF(TRIM(CONCAT(
                        COALESCE(pd_esp.Nombres,''), ' ',
                        COALESCE(pd_esp.Apellidos,''))), '')             AS Docente_Especialidad,
                    '0'                                                  AS Docente_Practica,
                    CAST(COALESCE(cal.Teorico1,  NULL) AS CHAR)             AS Teorica1,
                    CAST(COALESCE(cal.Teorico2,  NULL) AS CHAR)             AS Teorica2,
                    CAST(COALESCE(cal.Teorico3,  NULL) AS CHAR)             AS Teorica3,
                    CAST(COALESCE(cal.Teorico4,  NULL) AS CHAR)             AS Teorica4,
                    CAST(COALESCE(cal.Practico1, NULL) AS CHAR)             AS Practica1,
                    CAST(COALESCE(cal.Practico2, NULL) AS CHAR)             AS Practica2,
                    CAST(COALESCE(cal.Practico3, NULL) AS CHAR)             AS Practica3,
                    CAST(COALESCE(cal.Practico4, NULL) AS CHAR)             AS Practica4,
                    CAST(COALESCE(cal.PromTeorico, NULL) AS CHAR)          AS PromEvT,
                    CAST(COALESCE(cal.PromPractico, NULL) AS CHAR)          AS PromEvP,
                    CAST(COALESCE(cal.Promedio, NULL) AS CHAR)          AS Promedio,
                    CAST(COALESCE(cal.PruebaRecuperacion, '') AS CHAR)   AS PruebaRecuperacion,
                    est.Ap_Paterno                                       AS Ap_Paterno,
                    est.Ap_Materno                                       AS Ap_Materno,
                    est.Nombre                                           AS Nombre,
                    est.Sexo                                             AS Sexo,
                    est.CI                                               AS CI,
                    info.InstrumentoMusical                              AS Especialidad,
                    cal.EstadoRegistroMateria                            AS Observacion
                ")
                ->limit($perPage)
                ->get()
                ->map(fn ($r) => array_merge((array) $r, ['_fuente' => 'actual']));
        }

        // --- 3. Mezclar y ordenar ---
        $all    = $oldRows->concat($newRows);
        $sorted = $all->sort(function ($a, $b) {
            $aA = is_array($a) ? $a : (array) $a;
            $bA = is_array($b) ? $b : (array) $b;

            $anioA = (int) ($aA['Anio'] ?? 0);
            $anioB = (int) ($bA['Anio'] ?? 0);
            if ($anioA !== $anioB) return $anioA <=> $anioB;

            $rangoA = (int) ($aA['Rango'] ?? 0);
            $rangoB = (int) ($bA['Rango'] ?? 0);
            if ($rangoA !== $rangoB) return $rangoA <=> $rangoB;

            return strcmp((string) ($aA['NombreCurso'] ?? ''), (string) ($bA['NombreCurso'] ?? ''));
        })->values();

        return response()->json([
            'data' => [
                'data'  => $sorted,
                'total' => $sorted->count(),
            ],
        ]);
    }
}
