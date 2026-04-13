<?php

namespace App\Http\Controllers;

use App\Models\Anios;
use App\Models\Infoestudiantesifas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;
use App\Models\Planteladministrativos;
use App\Services\AccionesGrupales\AsignarMateriasPorHistorialService;
use App\Services\AccionesGrupales\ModificacionGrupalInscripcionesService;
use App\Services\AccionesGrupales\RollbackAsignacionesLoteService;
class InfoestudiantesifasController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }

    private function aplicarFiltroAnio($q, int $anioIdInt, bool $includeSinAsignar, string $anioScope = 'default'): void
    {
        $anioScope = strtolower(trim($anioScope));
        if (!in_array($anioScope, ['default', 'assigned', 'unassigned', 'both'], true)) {
            $anioScope = 'default';
        }

        // Nuevo modo: siempre mezclar ASIGNADOS del año + SIN ASIGNAR del año
        // (ignora el check include_sin_asignar del frontend).
        if ($anioScope === 'both') {
            $includeSinAsignar = true;
            $anioScope = 'default';
        }

        if ($anioIdInt <= 0) return;

        // Filtramos por el mismo "Anio" que se muestra en la tabla (MAX(a.Anio)).
        // A partir de 2026 se requiere que al seleccionar una gestión base (ej. "2026")
        // se incluyan también sus subgestiones ("2026/1", "2026/2", ...),
        // para que no se excluyan estudiantes ya asignados a un subperiodo.
        $anioValor = trim((string) (Anios::query()->where('id', $anioIdInt)->value('Anio') ?? ''));
        if ($anioValor === '') {
            return;
        }

        $anioBase = null;
        if (preg_match('/^(\d{4})/', $anioValor, $m)) {
            $anioBase = (int) $m[1];
        }
        $anioEsBase = !str_contains($anioValor, '/');

        $anioLabelSubquery = "COALESCE((
            SELECT MAX(a.Anio)
            FROM calificaciones c
            INNER JOIN materias m ON m.id = c.materias_id
            INNER JOIN plandeestudios p ON p.id = m.plandeestudios_id
            INNER JOIN anios a ON a.id = p.anio_id
            WHERE c.infoestudiantesifas_id = infoestudiantesifas.id
        ), 'SIN ASIGNAR')";

        $aplicarMatchGestion = function ($builder) use ($anioLabelSubquery, $anioValor, $anioEsBase) {
            if ($anioEsBase) {
                $builder->where(function ($ww) use ($anioLabelSubquery, $anioValor) {
                    $ww->whereRaw($anioLabelSubquery . ' = ?', [$anioValor])
                       ->orWhereRaw($anioLabelSubquery . ' LIKE ?', [$anioValor . '/%']);
                });
            } else {
                $builder->whereRaw($anioLabelSubquery . ' = ?', [$anioValor]);
            }
        };

        if ($anioScope === 'unassigned') {
            // Solo SIN ASIGNAR: no tiene ninguna calificación.
            // Se acota al año base por fecha de inscripción para que al filtrar por 2026
            // no aparezcan "SIN ASIGNAR" de otros años.
            $q->whereNotExists(function ($qq) {
                $qq->select(DB::raw(1))
                    ->from('calificaciones as c')
                    ->whereColumn('c.infoestudiantesifas_id', 'infoestudiantesifas.id');
            });
            if (!empty($anioBase)) {
                $q->whereRaw('YEAR(COALESCE(infoestudiantesifas.FechInsc, infoestudiantesifas.created_at)) = ?', [$anioBase]);
            }
            return;
        }

        if ($anioScope === 'assigned') {
            // Solo ASIGNADOS cuyo "Anio" calculado coincide con la gestión seleccionada.
            // Si la gestión es base (2026) se incluye también 2026/1, 2026/2, ...
            $q->where(function ($w) use ($aplicarMatchGestion) {
                $aplicarMatchGestion($w);
            });
            return;
        }

        if ($includeSinAsignar) {
            $q->where(function ($outer) use ($aplicarMatchGestion, $anioBase) {
                $outer->where(function ($w) use ($aplicarMatchGestion) {
                    $aplicarMatchGestion($w);
                });

                $outer->orWhere(function ($w) use ($anioBase) {
                    $w->whereNotExists(function ($qq) {
                        $qq->select(DB::raw(1))
                            ->from('calificaciones as c')
                            ->whereColumn('c.infoestudiantesifas_id', 'infoestudiantesifas.id');
                    });
                    if (!empty($anioBase)) {
                        $w->whereRaw('YEAR(COALESCE(infoestudiantesifas.FechInsc, infoestudiantesifas.created_at)) = ?', [$anioBase]);
                    }
                });
            });
        } else {
            $q->where(function ($w) use ($aplicarMatchGestion) {
                $aplicarMatchGestion($w);
            });
        }
    }

    private function normalizarKey($value, string $fallback): string
    {
        $v = trim((string) ($value ?? ''));
        return $v !== '' ? $v : $fallback;
    }

    private function resolverModoMateriaPorFiltroDocente($docenteId, $docenteIdPC, $docenteIdOtros): ?string
    {
        if ($docenteIdPC !== null && $docenteIdPC !== '' && (int) $docenteIdPC > 0) {
            return 'MODO PRACTICA DE CONJUNTOS';
        }

        if ($docenteIdOtros !== null && $docenteIdOtros !== '' && (int) $docenteIdOtros > 0) {
            return 'MODO INSTRUMENTO COMPLEMENTARIO';
        }

        if ($docenteId !== null && $docenteId !== '' && (int) $docenteId > 0) {
            return 'MODO INSTRUMENTOS DE ESPECIALIDAD';
        }

        return null;
    }

    private function resolverAliasModoMateria(?string $modoMateria): array
    {
        $modoNormalizado = trim((string) $modoMateria);
        if ($modoNormalizado === '') {
            return [];
        }

        $modoNormalizado = function_exists('mb_strtoupper')
            ? mb_strtoupper($modoNormalizado, 'UTF-8')
            : strtoupper($modoNormalizado);

        return match ($modoNormalizado) {
            'MODO PRACTICA DE CONJUNTOS', 'MODO PRÁCTICA DE CONJUNTOS' => [
                'MODO PRACTICA DE CONJUNTOS',
                'MODO PRÁCTICA DE CONJUNTOS',
            ],
            'MODO INSTRUMENTO COMPLEMENTARIO', 'MODO OTROS' => [
                'MODO INSTRUMENTO COMPLEMENTARIO',
                'MODO OTROS',
            ],
            default => [$modoNormalizado],
        };
    }

    /**
     * Genera fragmento SQL para filtrar por ModoMateria en plandeestudios.
     * @param string $plandeAlias Alias de la tabla plandeestudios en el subquery ('pe' o 'p').
     */
    private function buildModoMateriaFilterSQL(?string $modoMateria, string $plandeAlias = 'pe'): string
    {
        if (empty($modoMateria)) {
            return '';
        }

        $modosPermitidos = $this->resolverAliasModoMateria($modoMateria);
        $quotedModos = array_map(static fn ($modo) => DB::getPdo()->quote($modo), $modosPermitidos);

        return ' AND UPPER(TRIM(' . $plandeAlias . '.ModoMateria)) IN (' . implode(', ', $quotedModos) . ')';
    }

    /**
     * Keywords de NombreMateria asociados a cada modo, para fallback cuando
     * ModoMateria no está configurado correctamente en plandeestudios.
     */
    private function getNombreMateriaKeywordsForMode(?string $modoMateria): array
    {
        $modoNorm = function_exists('mb_strtoupper')
            ? mb_strtoupper(trim((string) $modoMateria), 'UTF-8')
            : strtoupper(trim((string) $modoMateria));

        return match (true) {
            in_array($modoNorm, ['MODO INSTRUMENTOS DE ESPECIALIDAD'], true) => [
                'INSTRUMENTO DE ESPECIALIDAD',
                'INSTRUMENTOS DE ESPECIALIDAD',
            ],
            in_array($modoNorm, ['MODO INSTRUMENTO COMPLEMENTARIO', 'MODO OTROS'], true) => [
                'INSTRUMENTO COMPLEMENTARIO',
            ],
            in_array($modoNorm, ['MODO PRACTICA DE CONJUNTOS', 'MODO PRÁCTICA DE CONJUNTOS'], true) => [
                'PRÁCTICA COLECTIVA',
                'PRACTICA COLECTIVA',
                'PRÁCTICA DE CONJUNTOS',
                'PRACTICA DE CONJUNTOS',
                'ENSAMBLE',
            ],
            default => [],
        };
    }

    private function buildSiglaMateriaSubquery(?string $modoMateria): string
    {
        if (empty($modoMateria)) {
            return "(
                SELECT pe.SiglaMateria
                FROM calificaciones c
                INNER JOIN materias m ON m.id = c.materias_id
                INNER JOIN plandeestudios pe ON pe.id = m.plandeestudios_id
                INNER JOIN anios a ON a.id = pe.anio_id
                WHERE c.infoestudiantesifas_id = infoestudiantesifas.id
                ORDER BY a.Anio DESC, pe.id DESC
                LIMIT 1
            )";
        }

        // Condición primaria: filtrar por ModoMateria
        $modosPermitidos = $this->resolverAliasModoMateria($modoMateria);
        $quotedModos = array_map(static fn ($modo) => DB::getPdo()->quote($modo), $modosPermitidos);
        $modoCondition = 'UPPER(TRIM(pe.ModoMateria)) IN (' . implode(', ', $quotedModos) . ')';

        // Condición fallback: filtrar por NombreMateria (para cuando ModoMateria no está configurado)
        $keywords = $this->getNombreMateriaKeywordsForMode($modoMateria);
        $nombreConditions = array_map(
            fn ($kw) => 'UPPER(pe.NombreMateria) LIKE ' . DB::getPdo()->quote('%' . mb_strtoupper($kw, 'UTF-8') . '%'),
            $keywords
        );

        $orCondition = $modoCondition;
        if (!empty($nombreConditions)) {
            $orCondition = '(' . $modoCondition . ' OR ' . implode(' OR ', $nombreConditions) . ')';
        }

        return "(
            SELECT pe.SiglaMateria
            FROM calificaciones c
            INNER JOIN materias m ON m.id = c.materias_id
            INNER JOIN plandeestudios pe ON pe.id = m.plandeestudios_id
            INNER JOIN anios a ON a.id = pe.anio_id
            WHERE c.infoestudiantesifas_id = infoestudiantesifas.id
                AND pe.SiglaMateria IS NOT NULL AND TRIM(pe.SiglaMateria) <> ''
                AND " . $orCondition . "
            ORDER BY a.Anio DESC, pe.id DESC
            LIMIT 1
        )";
    }

    private function cursoEsTecnicoSuperior(string $cursoSolicitado): bool
    {
        return stripos($cursoSolicitado, 'SUPERIOR') !== false;
    }

    private function getInstitucionIdFromRequest(Request $request, $user): int
    {
        // Si el usuario está ligado a una institución, se impone.
        if (!empty($user?->instituciones_id)) {
            return (int) $user->instituciones_id;
        }

        return (int) $request->input('instituciones_id', 0);
    }

    private function getAnioFromFecha(?string $fecha): int
    {
        try {
            return Carbon::parse($fecha ?: Carbon::now()->toDateString())->year;
        } catch (\Throwable $e) {
            return Carbon::now()->year;
        }
    }

    private function existeInscripcionMismaInstMismoAnio(int $estudianteId, int $institucionId, int $anio, ?int $ignoreId = null): ?array
    {
        if ($estudianteId <= 0 || $institucionId <= 0 || $anio <= 0) return null;

        $q = Infoestudiantesifas::query()
            ->where('estudiantesifas_id', $estudianteId)
            ->where('instituciones_id', $institucionId)
            ->whereRaw('YEAR(COALESCE(FechInsc, created_at)) = ?', [$anio]);

        if ($ignoreId) {
            $q->where('id', '<>', $ignoreId);
        }

        $row = $q->orderByDesc('id')->first();
        if (!$row) return null;

        return [
            'id' => (int) $row->id,
            'FechInsc' => $row->FechInsc,
            'instituciones_id' => (int) $row->instituciones_id,
            'estudiantesifas_id' => (int) $row->estudiantesifas_id,
        ];
    }

    private function validarDocentesMismaInstitucion(array $payload, int $institucionId): ?\Illuminate\Http\JsonResponse
    {
        if ($institucionId <= 0) return null;

        $fields = ['planteldocadmins_id', 'planteldocadmins_idPC', 'planteldocadmins_idOtros'];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $payload)) continue;
            if ($payload[$field] === null || $payload[$field] === '' || (int) $payload[$field] === 0) continue;

            $docenteId = (int) $payload[$field];
            $ok = DB::table('planteldocentes')
                ->where('id', $docenteId)
                ->where('instituciones_id', $institucionId)
                ->exists();

            if (!$ok) {
                return response()->json([
                    'message' => 'Docente no pertenece a la institución del registro.',
                    'field' => $field,
                    'docente_id' => $docenteId,
                    'instituciones_id' => $institucionId,
                ], 422);
            }
        }

        return null;
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

        $institucionId = (int) $request->query('instituciones_id', 0);

        $base = Infoestudiantesifas::query()
            ->leftJoin('estudiantesifas', 'infoestudiantesifas.estudiantesifas_id', '=', 'estudiantesifas.id')
            ->when(!$isSuperAdmin && !empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('infoestudiantesifas.instituciones_id', $user->instituciones_id);
            });

        // Para superadmin, permitir filtrar por institución cuando se envía el parámetro.
        if ($isSuperAdmin && $institucionId > 0) {
            $base->where('infoestudiantesifas.instituciones_id', $institucionId);
        }

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
        // Por Curso_Solicitado + Paralelo_Solicitado
        // Nota: Paralelo puede ser NULL/vacío (lo tratamos como "SIN DETERMINAR")
        // =============================
        $basePar = (clone $base);

        $cursoPar = (clone $basePar)
            ->selectRaw('infoestudiantesifas.Curso_Solicitado as curso, TRIM(COALESCE(infoestudiantesifas.Paralelo_Solicitado, \'\')) as paralelo, COUNT(*) as total')
            ->groupBy('infoestudiantesifas.Curso_Solicitado', DB::raw('TRIM(COALESCE(infoestudiantesifas.Paralelo_Solicitado, \'\'))'))
            ->orderByDesc('total')
            ->get();

        $cursoParSexo = (clone $basePar)
            ->selectRaw('infoestudiantesifas.Curso_Solicitado as curso, TRIM(COALESCE(infoestudiantesifas.Paralelo_Solicitado, \'\')) as paralelo, UPPER(TRIM(COALESCE(estudiantesifas.Sexo, \'\'))) as sexo, COUNT(*) as total')
            ->groupBy('infoestudiantesifas.Curso_Solicitado', DB::raw('TRIM(COALESCE(infoestudiantesifas.Paralelo_Solicitado, \'\'))'), DB::raw('UPPER(TRIM(COALESCE(estudiantesifas.Sexo, \'\')))'))
            ->get();

        $cursoParEdad = (clone $basePar)
            ->whereNotNull('estudiantesifas.Edad')
            ->whereRaw('TRIM(COALESCE(estudiantesifas.Edad, \'\')) <> \'\'')
            ->selectRaw('infoestudiantesifas.Curso_Solicitado as curso, TRIM(COALESCE(infoestudiantesifas.Paralelo_Solicitado, \'\')) as paralelo, CAST(estudiantesifas.Edad AS UNSIGNED) as edad, COUNT(*) as total')
            ->groupBy('infoestudiantesifas.Curso_Solicitado', DB::raw('TRIM(COALESCE(infoestudiantesifas.Paralelo_Solicitado, \'\'))'), DB::raw('CAST(estudiantesifas.Edad AS UNSIGNED)'))
            ->orderBy(DB::raw('CAST(estudiantesifas.Edad AS UNSIGNED)'))
            ->get();

        $cursoParInstrumento = (clone $basePar)
            ->whereRaw("TRIM(COALESCE(infoestudiantesifas.InstrumentoMusical, '')) <> ''")
            ->selectRaw('infoestudiantesifas.Curso_Solicitado as curso, TRIM(COALESCE(infoestudiantesifas.Paralelo_Solicitado, \'\')) as paralelo, TRIM(infoestudiantesifas.InstrumentoMusical) as instrumento, COUNT(*) as total')
            ->groupBy('infoestudiantesifas.Curso_Solicitado', DB::raw('TRIM(COALESCE(infoestudiantesifas.Paralelo_Solicitado, \'\'))'), DB::raw('TRIM(infoestudiantesifas.InstrumentoMusical)'))
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
            $parValue = $this->normalizarKey($row->paralelo, '');
            $parLabel = $parValue !== '' ? $parValue : 'SIN DETERMINAR';
            $key = $cursoKey . '||' . $parLabel;
            $porCursoPar[$key] = [
                'curso' => $cursoKey,
                'paralelo' => $parValue,
                'paralelo_label' => $parLabel,
                'tipo' => $this->cursoEsTecnicoSuperior($cursoKey) ? 'TECNICO_SUPERIOR' : 'OTRO',
                'total' => (int) $row->total,
                'sexo' => [],
                'edades' => [],
                'instrumentos' => [],
            ];
        }

        foreach ($cursoParSexo as $row) {
            $cursoKey = $this->normalizarKey($row->curso, '(SIN CURSO)');
            $parValue = $this->normalizarKey($row->paralelo, '');
            $parLabel = $parValue !== '' ? $parValue : 'SIN DETERMINAR';
            $key = $cursoKey . '||' . $parLabel;
            if (!isset($porCursoPar[$key])) continue;
            $sexoKey = $this->normalizarKey($row->sexo, 'SIN DATO');
            $porCursoPar[$key]['sexo'][$sexoKey] = (int) $row->total;
        }

        foreach ($cursoParEdad as $row) {
            $cursoKey = $this->normalizarKey($row->curso, '(SIN CURSO)');
            $parValue = $this->normalizarKey($row->paralelo, '');
            $parLabel = $parValue !== '' ? $parValue : 'SIN DETERMINAR';
            $key = $cursoKey . '||' . $parLabel;
            if (!isset($porCursoPar[$key])) continue;
            $porCursoPar[$key]['edades'][] = [
                'edad' => (int) $row->edad,
                'total' => (int) $row->total,
            ];
        }

        foreach ($cursoParInstrumento as $row) {
            $cursoKey = $this->normalizarKey($row->curso, '(SIN CURSO)');
            $parValue = $this->normalizarKey($row->paralelo, '');
            $parLabel = $parValue !== '' ? $parValue : 'SIN DETERMINAR';
            $key = $cursoKey . '||' . $parLabel;
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

    // =============================
    // Acciones grupales: asignación automática por historial
    // =============================
    public function accionesGrupalesAsignarMaterias(Request $request, AsignarMateriasPorHistorialService $svc)
    {
        $validated = $request->validate([
            'anio_id' => ['required', 'integer'],
            'instituciones_id' => ['nullable', 'integer'],
            'resolucion' => ['required', 'string', 'max:100'],
            'nivel' => ['required', 'string', 'max:100'],
            'modo' => ['nullable', 'string', 'max:20'],
            'cursos' => ['required', 'array', 'min:1', 'max:200'],
            'cursos.*.curso' => ['required', 'string', 'max:60'],
            'cursos.*.paralelo' => ['nullable', 'string', 'max:20'],
        ]);

        $user = $request->user();

        if (!($user instanceof Planteladministrativos)) {
            return response()->json(['message' => 'Solo planteladministrativos pueden ejecutar acciones grupales.'], 403);
        }

        $res = $svc->ejecutar($validated, $user);

        if (!($res['ok'] ?? false)) {
            return response()->json(['message' => $res['message'] ?? 'No se pudo ejecutar la acción'], 422);
        }

        return response()->json($res);
    }

    public function accionesGrupalesRollbackAsignacion(Request $request, RollbackAsignacionesLoteService $svc)
    {
        $validated = $request->validate([
            'uuid' => ['nullable', 'string', 'max:40'],
        ]);

        $user = $request->user();

        if (!($user instanceof Planteladministrativos)) {
            return response()->json(['message' => 'Solo planteladministrativos pueden ejecutar acciones grupales.'], 403);
        }

        $res = $svc->ejecutar($validated, $user);

        if (!($res['ok'] ?? false)) {
            return response()->json(['message' => $res['message'] ?? 'No se pudo revertir la acción'], 422);
        }

        return response()->json($res);
    }

    // =============================
    // Verificación masiva: comparar solicitado vs asignación oficial (Año + Resolución/Nivel)
    // =============================
    public function accionesGrupalesVerificarAsignacion(Request $request)
    {
        $validated = $request->validate([
            'anio_id' => ['required', 'integer'],
            'instituciones_id' => ['nullable', 'integer'],
            // Se ignoran resolución/nivel: verificación solo por Año
            'estudiante_id' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],

            // Filtros globales (aplican a TODO el año, no solo a la página)
            'filtro_estado' => ['nullable', 'string', 'in:MIXTO,OK,NO,mixto,ok,no'],
            'cursos' => ['nullable', 'array', 'max:300'],
            'cursos.*' => ['nullable', 'string', 'max:60'],
            'paralelos' => ['nullable', 'array', 'max:300'],
            'paralelos.*' => ['nullable', 'string', 'max:20'],
            'turnos' => ['nullable', 'array', 'max:300'],
            'turnos.*' => ['nullable', 'string', 'max:60'],
        ]);

        $user = $request->user();
        if (!($user instanceof Planteladministrativos)) {
            return response()->json(['message' => 'Solo planteladministrativos pueden ejecutar esta verificación.'], 403);
        }

        $anioId = (int) ($validated['anio_id'] ?? 0);
        if ($anioId <= 0) {
            return response()->json(['message' => 'anio_id es requerido'], 422);
        }

        // Requisito: esta pantalla verifica SOLO estudiantes ASIGNADOS en el AÑO seleccionado.
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 20);
        if ($perPage <= 0) $perPage = 20;

        // Etiqueta de gestión (para incluir subgestiones como 2026/1, 2026/2, ...)
        $anioValor = trim((string) (Anios::query()->where('id', $anioId)->value('Anio') ?? ''));
        if ($anioValor === '') {
            return response()->json(['message' => 'No se pudo resolver el año seleccionado'], 422);
        }
        $anioEsBase = !str_contains($anioValor, '/');

        $isSuperAdmin = empty($user?->instituciones_id);
        $institucionId = 0;
        if (!empty($user?->instituciones_id)) {
            $institucionId = (int) $user->instituciones_id;
        } else {
            $institucionId = (int) ($validated['instituciones_id'] ?? 0);
        }

        if ($institucionId <= 0) {
            return response()->json(['message' => 'instituciones_id es requerido'], 422);
        }

        // Base: traer TODAS las inscripciones asignadas del año (sin paginar), luego filtrar y paginar en memoria.
        // Motivo: los filtros OK/NO y curso/paralelo/turno deben afectar el total y la paginación.
        $base = Infoestudiantesifas::query()
            ->leftJoin('estudiantesifas as est', 'infoestudiantesifas.estudiantesifas_id', '=', 'est.id')
            ->when(!$isSuperAdmin && !empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('infoestudiantesifas.instituciones_id', $user->instituciones_id);
            });

        // Superadmin: puede filtrar por instituciones_id
        if ($isSuperAdmin && $institucionId > 0) {
            $base->where('infoestudiantesifas.instituciones_id', $institucionId);
        }

        // Solo ASIGNADOS dentro del Año seleccionado.
        $base->whereExists(function ($qq) use ($anioValor, $anioEsBase) {
            $qq->select(DB::raw(1))
                ->from('calificaciones as c')
                ->join('materias as m', 'm.id', '=', 'c.materias_id')
                ->join('plandeestudios as p', 'p.id', '=', 'm.plandeestudios_id')
                ->join('anios as a', 'a.id', '=', 'p.anio_id')
                ->whereColumn('c.infoestudiantesifas_id', 'infoestudiantesifas.id')
                ->where(function ($w) use ($anioValor, $anioEsBase) {
                    if ($anioEsBase) {
                        $w->where('a.Anio', $anioValor)->orWhere('a.Anio', 'LIKE', $anioValor . '/%');
                    } else {
                        $w->where('a.Anio', $anioValor);
                    }
                });
        });

        $estudianteId = (int) ($validated['estudiante_id'] ?? 0);
        if ($estudianteId > 0) {
            $base->where('infoestudiantesifas.estudiantesifas_id', $estudianteId);
        }

        $base->select([
            'infoestudiantesifas.id as id',
            'infoestudiantesifas.estudiantesifas_id as estudiantesifas_id',
            'infoestudiantesifas.Curso_Solicitado as Curso_Solicitado',
            'infoestudiantesifas.Paralelo_Solicitado as Paralelo_Solicitado',
            'infoestudiantesifas.Turno as Turno',
            'infoestudiantesifas.Verificacion as Verificacion',
            'est.Ap_Paterno as Ap_Paterno',
            'est.Ap_Materno as Ap_Materno',
            'est.Nombre as Nombre',
            'est.CI as CI',
        ]);

        $allItems = $base->orderBy('est.Ap_Paterno')->orderBy('est.Ap_Materno')->orderBy('est.Nombre')->get();
        $allCount = $allItems->count();

        $infoIds = [];
        foreach ($allItems as $it) {
            $id = (int) ($it->id ?? 0);
            if ($id > 0) $infoIds[] = $id;
        }
        $infoIds = array_values(array_unique($infoIds));

        $asigMap = []; // info_id => ['total' => int, 'cursos' => set, 'paralelos' => set, 'turnos' => set]

        if (count($infoIds) > 0) {
            $rows = DB::table('calificaciones as c')
                ->join('materias as m', 'm.id', '=', 'c.materias_id')
                ->join('plandeestudios as p', 'p.id', '=', 'm.plandeestudios_id')
                ->join('anios as a', 'a.id', '=', 'p.anio_id')
                ->whereIn('c.infoestudiantesifas_id', $infoIds)
                ->where(function ($w) use ($anioValor, $anioEsBase) {
                    if ($anioEsBase) {
                        $w->where('a.Anio', $anioValor)->orWhere('a.Anio', 'LIKE', $anioValor . '/%');
                    } else {
                        $w->where('a.Anio', $anioValor);
                    }
                })
                ->select([
                    'c.infoestudiantesifas_id as info_id',
                    DB::raw("TRIM(COALESCE(p.LvlCurso, '')) as curso"),
                    DB::raw("TRIM(COALESCE(m.Paralelo, '')) as paralelo"),
                    DB::raw("TRIM(COALESCE(m.Turno, '')) as turno"),
                    DB::raw('COUNT(*) as total'),
                ])
                ->groupBy('c.infoestudiantesifas_id', 'curso', 'paralelo', 'turno')
                ->get();

            foreach ($rows as $r) {
                $iid = (int) ($r->info_id ?? 0);
                if ($iid <= 0) continue;
                if (!isset($asigMap[$iid])) {
                    $asigMap[$iid] = [
                        'total' => 0,
                        'cursos' => [],
                        'paralelos' => [],
                        'turnos' => [],
                    ];
                }
                $asigMap[$iid]['total'] += (int) ($r->total ?? 0);
                $curso = trim((string) ($r->curso ?? ''));
                $paralelo = trim((string) ($r->paralelo ?? ''));
                $turno = trim((string) ($r->turno ?? ''));
                if ($curso !== '') $asigMap[$iid]['cursos'][$curso] = true;
                // paralelo/turno: permitir '' como valor real (SIN DETERMINAR / SIN TURNO)
                $asigMap[$iid]['paralelos'][$paralelo] = true;
                $asigMap[$iid]['turnos'][$turno] = true;
            }
        }

        $outAll = [];

        // Helpers de normalización (para filtros)
        $normCurso = function ($v) {
            return trim((string) ($v ?? ''));
        };
        $normParalelo = function ($v) {
            $p = trim((string) ($v ?? ''));
            return $p === '' ? 'SIN DETERMINAR' : $p;
        };
        $normTurno = function ($v) {
            $t = trim((string) ($v ?? ''));
            return $t === '' ? 'SIN DETERMINAR' : $t;
        };

        // Construir salida completa con ok/oficial
        foreach ($allItems as $it) {
            $id = (int) ($it->id ?? 0);
            $cursoSol = trim((string) ($it->Curso_Solicitado ?? ''));
            $parSol = trim((string) ($it->Paralelo_Solicitado ?? ''));
            $turnoSol = trim((string) ($it->Turno ?? ''));

            $asig = $asigMap[$id] ?? null;
            $ofCurso = '';
            $ofPar = '';
            $ofTurno = '';
            $asigTotal = 0;
            $detalle = '';
            $ok = false;

            if (!$asig) {
                $detalle = 'SIN ASIGNACIONES EN EL AÑO SELECCIONADO';
            } else {
                $asigTotal = (int) ($asig['total'] ?? 0);

                $cursosVals = array_keys($asig['cursos'] ?? []);
                $parVals = array_keys($asig['paralelos'] ?? []);
                $turnoVals = array_keys($asig['turnos'] ?? []);

                sort($cursosVals);
                sort($parVals);
                sort($turnoVals);

                $ofCurso = count($cursosVals) === 1 ? (string) $cursosVals[0] : 'MIXTO';
                $ofPar = count($parVals) === 1 ? (string) $parVals[0] : 'MIXTO';
                $ofTurno = count($turnoVals) === 1 ? (string) $turnoVals[0] : 'MIXTO';

                if ($ofCurso === 'MIXTO' || $ofPar === 'MIXTO' || $ofTurno === 'MIXTO') {
                    $detalle = 'ASIGNACIÓN OFICIAL MIXTA';
                }

                $ok = ($ofCurso !== 'MIXTO' && $ofPar !== 'MIXTO' && $ofTurno !== 'MIXTO')
                    && ($cursoSol === trim($ofCurso))
                    && ($parSol === trim($ofPar))
                    && ($turnoSol === trim($ofTurno));

                if (!$ok && $detalle === '') {
                    $detalle = 'NO COINCIDE';
                }
            }

            $outAll[] = [
                'id' => $id,
                'estudiantesifas_id' => (int) ($it->estudiantesifas_id ?? 0),
                'Ap_Paterno' => (string) ($it->Ap_Paterno ?? ''),
                'Ap_Materno' => (string) ($it->Ap_Materno ?? ''),
                'Nombre' => (string) ($it->Nombre ?? ''),
                'CI' => (string) ($it->CI ?? ''),
                'Curso_Solicitado' => $cursoSol,
                'Paralelo_Solicitado' => $parSol,
                'Turno' => $turnoSol,
                'Verificacion' => (string) ($it->Verificacion ?? ''),
                'oficial' => [
                    'Curso' => $ofCurso,
                    'Paralelo' => $ofPar,
                    'Turno' => $ofTurno,
                    'asignaciones_count' => $asigTotal,
                ],
                'ok' => $ok ? 1 : 0,
                'detalle' => $detalle,

                // Normalizados (para filtrar sin recalcular en frontend)
                '_n' => [
                    'curso' => $normCurso($cursoSol),
                    'paralelo' => $normParalelo($parSol),
                    'turno' => $normTurno($turnoSol),
                ],
            ];
        }

        // Opciones de filtros globales (de TODO el año, no de la página)
        $optCursos = [];
        $optParalelos = [];
        $optTurnos = [];
        foreach ($outAll as $r) {
            $c = (string) (($r['_n']['curso'] ?? '') ?: '');
            $p = (string) (($r['_n']['paralelo'] ?? '') ?: '');
            $t = (string) (($r['_n']['turno'] ?? '') ?: '');
            if ($c !== '') $optCursos[$c] = true;
            if ($p !== '') $optParalelos[$p] = true;
            if ($t !== '') $optTurnos[$t] = true;
        }
        $optCursos = array_keys($optCursos);
        $optParalelos = array_keys($optParalelos);
        $optTurnos = array_keys($optTurnos);
        sort($optCursos);
        sort($optParalelos);
        sort($optTurnos);

        // Aplicar filtros solicitados
        $filtroEstado = strtoupper(trim((string) ($validated['filtro_estado'] ?? 'MIXTO')));
        if (!in_array($filtroEstado, ['MIXTO', 'OK', 'NO'], true)) $filtroEstado = 'MIXTO';

        $selCursos = array_values(array_filter(array_map(function ($x) { return trim((string) $x); }, (array) ($validated['cursos'] ?? [])), function ($x) { return $x !== ''; }));
        $selParalelos = array_values(array_filter(array_map(function ($x) { return trim((string) $x); }, (array) ($validated['paralelos'] ?? [])), function ($x) { return $x !== ''; }));
        $selTurnos = array_values(array_filter(array_map(function ($x) { return trim((string) $x); }, (array) ($validated['turnos'] ?? [])), function ($x) { return $x !== ''; }));

        $setCursos = count($selCursos) ? array_fill_keys($selCursos, true) : null;
        $setParalelos = count($selParalelos) ? array_fill_keys($selParalelos, true) : null;
        $setTurnos = count($selTurnos) ? array_fill_keys($selTurnos, true) : null;

        $filtered = [];
        foreach ($outAll as $r) {
            $ok = (int) ($r['ok'] ?? 0) === 1;
            if ($filtroEstado === 'OK' && !$ok) continue;
            if ($filtroEstado === 'NO' && $ok) continue;

            $nc = (string) ($r['_n']['curso'] ?? '');
            $np = (string) ($r['_n']['paralelo'] ?? '');
            $nt = (string) ($r['_n']['turno'] ?? '');

            if ($setCursos !== null && !isset($setCursos[$nc])) continue;
            if ($setParalelos !== null && !isset($setParalelos[$np])) continue;
            if ($setTurnos !== null && !isset($setTurnos[$nt])) continue;

            // no exponer normalizados
            unset($r['_n']);
            $filtered[] = $r;
        }

        $totalFiltered = count($filtered);
        $lastPage = (int) max(1, (int) ceil($totalFiltered / $perPage));
        if ($page > $lastPage) $page = $lastPage;
        if ($page < 1) $page = 1;

        $offset = ($page - 1) * $perPage;
        $pageItems = array_slice($filtered, $offset, $perPage);

        return response()->json([
            'meta' => [
                'anio_id' => $anioId,
                'anio_label' => $anioValor,
                'instituciones_id' => $institucionId,
                'anio_scope' => 'assigned',

                'filtro_estado' => $filtroEstado,
                'cursos' => $selCursos,
                'paralelos' => $selParalelos,
                'turnos' => $selTurnos,

                'filter_options' => [
                    'cursos' => $optCursos,
                    'paralelos' => $optParalelos,
                    'turnos' => $optTurnos,
                ],

                'total_year' => (int) $allCount,
                'current_page' => (int) $page,
                'last_page' => (int) $lastPage,
                'per_page' => (int) $perPage,
                'total' => (int) $totalFiltered,
                'from' => $totalFiltered > 0 ? (int) ($offset + 1) : 0,
                'to' => $totalFiltered > 0 ? (int) min($offset + $perPage, $totalFiltered) : 0,
            ],
            'data' => $pageItems,
        ]);
    }

    // =============================
    // Acciones grupales: modificación de inscripciones (curso/paralelo/turno + quitar asignaciones)
    // =============================
    public function accionesGrupalesModificarInscripciones(Request $request, ModificacionGrupalInscripcionesService $svc)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['required', 'integer'],

            'cambiar_curso' => ['nullable', 'boolean'],
            'nuevo_curso' => ['nullable', 'string', 'max:60'],

            'cambiar_paralelo' => ['nullable', 'boolean'],
            'nuevo_paralelo' => ['nullable', 'string', 'max:20'],

            'cambiar_turno' => ['nullable', 'boolean'],
            'nuevo_turno' => ['nullable', 'string', 'max:60'],

            'quitar_asignaciones' => ['nullable', 'boolean'],
            'anio_id_asignaciones' => ['exclude_unless:quitar_asignaciones,1,true', 'required', 'integer'],
            'resolucion' => ['exclude_unless:quitar_asignaciones,1,true', 'required', 'string', 'max:100'],
        ]);

        $res = $svc->ejecutar($validated, $request->user());

        if (!($res['ok'] ?? false)) {
            return response()->json(['message' => $res['message'] ?? 'No se pudo ejecutar la acción'], 422);
        }

        return response()->json($res);
    }

    public function estadisticasDetalle(Request $request)
    {
        $anioId = (int) $request->query('anio_id', 0);
        if ($anioId <= 0) {
            return response()->json(['message' => 'anio_id es requerido'], 422);
        }

        $curso = trim((string) $request->query('curso', ''));
        if ($curso === '') {
            return response()->json(['message' => 'curso es requerido'], 422);
        }

        // paralelo: puede ser "" para representar SIN DETERMINAR
        $paralelo = (string) $request->query('paralelo', '');
        $paralelo = trim($paralelo);
        $usarParalelo = filter_var($request->query('usar_paralelo', '0'), FILTER_VALIDATE_BOOLEAN);

        $sexoFiltro = strtoupper(trim((string) $request->query('sexo', '')));
        // Valores esperados: MASCULINO, FEMENINO, OTROS (o vacío)

        $edadFiltro = $request->query('edad', null);
        $edadFiltro = ($edadFiltro === null || $edadFiltro === '') ? null : (int) $edadFiltro;

        $instrumentoFiltro = trim((string) $request->query('instrumento', ''));
        $instrumentoFiltroUpper = $instrumentoFiltro !== '' ? mb_strtoupper($instrumentoFiltro, 'UTF-8') : '';

        $includeSinAsignar = filter_var($request->query('include_sin_asignar', '0'), FILTER_VALIDATE_BOOLEAN);
        $anioScope = (string) $request->query('anio_scope', 'default');

        $user = request()->user();
        $isSuperAdmin = empty($user?->instituciones_id);

        $institucionId = (int) $request->query('instituciones_id', 0);

        $base = Infoestudiantesifas::query()
            ->leftJoin('estudiantesifas', 'infoestudiantesifas.estudiantesifas_id', '=', 'estudiantesifas.id')
            ->when(!$isSuperAdmin && !empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('infoestudiantesifas.instituciones_id', $user->instituciones_id);
            });

        if ($isSuperAdmin && $institucionId > 0) {
            $base->where('infoestudiantesifas.instituciones_id', $institucionId);
        }

        $this->aplicarFiltroAnio($base, $anioId, $includeSinAsignar, $anioScope);

        $base->where('infoestudiantesifas.Curso_Solicitado', $curso);

        if ($usarParalelo) {
            if ($paralelo === '') {
                $base->whereRaw("TRIM(COALESCE(infoestudiantesifas.Paralelo_Solicitado, '')) = ''");
            } else {
                $base->whereRaw("TRIM(COALESCE(infoestudiantesifas.Paralelo_Solicitado, '')) = ?", [$paralelo]);
            }
        }

        if ($sexoFiltro === 'MASCULINO' || $sexoFiltro === 'FEMENINO') {
            $base->whereRaw("UPPER(TRIM(COALESCE(estudiantesifas.Sexo, ''))) = ?", [$sexoFiltro]);
        } elseif ($sexoFiltro === 'OTROS') {
            $base->whereRaw("UPPER(TRIM(COALESCE(estudiantesifas.Sexo, ''))) NOT IN ('MASCULINO','FEMENINO')");
        }

        if (!empty($edadFiltro) && $edadFiltro > 0) {
            // Edad viene como string en algunos registros; normalizamos a unsigned para comparar.
            $base->whereNotNull('estudiantesifas.Edad')
                ->whereRaw("TRIM(COALESCE(estudiantesifas.Edad, '')) <> ''")
                ->whereRaw("CAST(estudiantesifas.Edad AS UNSIGNED) = ?", [$edadFiltro]);
        }

        if ($instrumentoFiltroUpper !== '') {
            $base->whereRaw("UPPER(TRIM(COALESCE(infoestudiantesifas.InstrumentoMusical, ''))) = ?", [$instrumentoFiltroUpper]);
        }

        $rows = $base
            ->select([
                'estudiantesifas.Ap_Paterno',
                'estudiantesifas.Ap_Materno',
                'estudiantesifas.Nombre',
                'estudiantesifas.CI',
                'estudiantesifas.Sexo',
                'estudiantesifas.Edad',
            ])
            ->orderBy('estudiantesifas.Ap_Paterno')
            ->orderBy('estudiantesifas.Ap_Materno')
            ->orderBy('estudiantesifas.Nombre')
            ->get();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'anio_id' => $anioId,
                'curso' => $curso,
                'paralelo' => $usarParalelo ? $paralelo : null,
                'sexo' => $sexoFiltro !== '' ? $sexoFiltro : null,
                'edad' => $edadFiltro,
                'instrumento' => $instrumentoFiltroUpper !== '' ? $instrumentoFiltroUpper : null,
                'total' => (int) $rows->count(),
            ],
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
        $relevance = filter_var($request->query('relevance', '0'), FILTER_VALIDATE_BOOLEAN);
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

        $docenteId = $request->query('planteldocadmins_id');
        $docenteIdPC = $request->query('planteldocadmins_idPC');
        $docenteIdOtros = $request->query('planteldocadmins_idOtros');
        $includeSinDocente = filter_var($request->query('include_sin_docente', '0'), FILTER_VALIDATE_BOOLEAN);

        $instrumentoMusical = trim((string) $request->query('InstrumentoMusical', ''));
        $instrumentoMusicalSecundario = trim((string) $request->query('InstrumentoMusicalSecundario', ''));

        $cursoSolicitadoFiltro = trim((string) $request->query('Curso_Solicitado', ''));
        $paraleloSolicitadoFiltro = trim((string) $request->query('Paralelo_Solicitado', ''));
        $paraleloSolicitadoEmpty = filter_var($request->query('Paralelo_Solicitado_empty', '0'), FILTER_VALIDATE_BOOLEAN);
        $turnoFiltro = trim((string) $request->query('Turno', ''));
        $cursoAsignadoFiltro = trim((string) $request->query('CursoAsignado', ''));
        $paraleloAsignadoFiltro = trim((string) $request->query('ParaleloAsignado', ''));

        // Elegimos el campo de docente a filtrar según el parámetro recibido.
        // Prioridad: PC -> Otros -> Especialidad.
        $docenteCampo = null;
        $docenteFiltro = null;
        if ($docenteIdPC !== null && $docenteIdPC !== '') {
            $docenteCampo = 'infoestudiantesifas.planteldocadmins_idPC';
            $docenteFiltro = $docenteIdPC;
        } elseif ($docenteIdOtros !== null && $docenteIdOtros !== '') {
            $docenteCampo = 'infoestudiantesifas.planteldocadmins_idOtros';
            $docenteFiltro = $docenteIdOtros;
        } elseif ($docenteId !== null && $docenteId !== '') {
            $docenteCampo = 'infoestudiantesifas.planteldocadmins_id';
            $docenteFiltro = $docenteId;
        }

        $modoMateriaFiltro = $this->resolverModoMateriaPorFiltroDocente($docenteId, $docenteIdPC, $docenteIdOtros);

        // Campos permitidos para ordenar (alias "Anio" se maneja con orderByRaw)
        $allowedSort = [
            'id',
            'FechInsc',
            'NombreInstitucion',
            'Ap_Paterno',
            'Ap_Materno',
            'Nombre',
            'NombreEstudiante',
            'Anio',
            'Categoria',
            'Curso_Solicitado',
            'Paralelo_Solicitado',
            'CursoAsignado',
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

        $cursoAsignadoSubquery = "(
            SELECT pe.LvlCurso
            FROM calificaciones c
            INNER JOIN materias m ON m.id = c.materias_id
            INNER JOIN plandeestudios pe ON pe.id = m.plandeestudios_id
            INNER JOIN anios a ON a.id = pe.anio_id
            WHERE c.infoestudiantesifas_id = infoestudiantesifas.id
            ORDER BY a.Anio DESC, pe.id DESC
            LIMIT 1
        )";

        $paraleloAsignadoSubquery = "(
            SELECT m.Paralelo
            FROM calificaciones c
            INNER JOIN materias m ON m.id = c.materias_id
            INNER JOIN plandeestudios pe ON pe.id = m.plandeestudios_id
            INNER JOIN anios a ON a.id = pe.anio_id
            WHERE c.infoestudiantesifas_id = infoestudiantesifas.id
            ORDER BY a.Anio DESC, pe.id DESC
            LIMIT 1
        )";

        $siglaMateriaSubquery = $this->buildSiglaMateriaSubquery($modoMateriaFiltro);

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
                'estudiantesifas.Sexo',
                'estudiantesifas.Edad',
                'estudiantesifas.Celular',
                'estudiantesifas.NumCelP',
                'estudiantesifas.NumCelM',
                DB::raw($anioSubquery . " as Anio"),
                DB::raw($carreraSubquery . " as NombreCarrera"),
                DB::raw($resolucionSubquery . " as Resolucion"),
                DB::raw($cursoAsignadoSubquery . " as CursoAsignado"),
                DB::raw($paraleloAsignadoSubquery . " as ParaleloAsignado"),
                DB::raw($siglaMateriaSubquery . " as SiglaMateria"),
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
            ->when($cursoSolicitadoFiltro !== '', function ($q) use ($cursoSolicitadoFiltro) {
                $q->whereRaw('TRIM(COALESCE(infoestudiantesifas.Curso_Solicitado, \'\')) = ?', [$cursoSolicitadoFiltro]);
            })
            ->when($paraleloSolicitadoEmpty, function ($q) {
                $q->whereRaw("TRIM(COALESCE(infoestudiantesifas.Paralelo_Solicitado, '')) = ''");
            })
            ->when(!$paraleloSolicitadoEmpty && $paraleloSolicitadoFiltro !== '', function ($q) use ($paraleloSolicitadoFiltro) {
                $q->whereRaw('TRIM(COALESCE(infoestudiantesifas.Paralelo_Solicitado, \'\')) = ?', [$paraleloSolicitadoFiltro]);
            })
            ->when($turnoFiltro !== '', function ($q) use ($turnoFiltro) {
                $q->whereRaw('TRIM(COALESCE(infoestudiantesifas.Turno, \'\')) = ?', [$turnoFiltro]);
            })
            ->when($instrumentoMusical !== '', function ($q) use ($instrumentoMusical) {
                $q->whereRaw('TRIM(COALESCE(infoestudiantesifas.InstrumentoMusical, \'\')) = ?', [$instrumentoMusical]);
            })
            ->when($cursoAsignadoFiltro !== '', function ($q) use ($cursoAsignadoFiltro, $cursoAsignadoSubquery) {
                $q->whereRaw('TRIM(COALESCE(' . $cursoAsignadoSubquery . ", '')) = ?", [$cursoAsignadoFiltro]);
            })
            ->when($paraleloAsignadoFiltro !== '', function ($q) use ($paraleloAsignadoFiltro, $paraleloAsignadoSubquery) {
                $q->whereRaw('TRIM(COALESCE(' . $paraleloAsignadoSubquery . ", '')) = ?", [$paraleloAsignadoFiltro]);
            })
            ->when($instrumentoMusicalSecundario !== '', function ($q) use ($instrumentoMusicalSecundario) {
                $q->whereRaw('TRIM(COALESCE(infoestudiantesifas.InstrumentoMusicalSecundario, \'\')) = ?', [$instrumentoMusicalSecundario]);
            })
            ->when($docenteCampo !== null && $docenteFiltro !== null && $docenteFiltro !== '' && (int) $docenteFiltro > 0, function ($q) use ($docenteCampo, $docenteFiltro, $includeSinDocente) {
                $docId = (int) $docenteFiltro;
                if ($includeSinDocente) {
                    $q->where(function ($w) use ($docenteCampo, $docId) {
                        $w->where($docenteCampo, $docId)
                          ->orWhereNull($docenteCampo)
                          ->orWhere($docenteCampo, 0);
                    });
                } else {
                    $q->where($docenteCampo, $docId);
                }
            })
            ->when($docenteCampo !== null && ($docenteFiltro === '0' || (is_numeric($docenteFiltro) && (int) $docenteFiltro === 0)) && $includeSinDocente, function ($q) use ($docenteCampo) {
                $q->where(function ($w) use ($docenteCampo) {
                    $w->whereNull($docenteCampo)
                      ->orWhere($docenteCampo, 0);
                });
            })
            ->when($search !== '', function ($q) use ($search, $searchMode) {
                // Soporte de frases exactas entre comillas: "NO SE PUDO AUTOASIGNAR"
                $phrases = [];
                if (preg_match_all('/"([^"]+)"/', $search, $mm)) {
                    foreach (($mm[1] ?? []) as $ph) {
                        $ph = trim((string) $ph);
                        if ($ph !== '') $phrases[] = $ph;
                    }
                }
                $searchWithoutPhrases = trim((string) preg_replace('/"[^"]+"/', ' ', $search));

                $tokens = preg_split('/\s+/', $searchWithoutPhrases, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                // Ojo: array_filter() sin callback elimina valores "falsy" como "0".
                // Necesitamos conservar "0" para búsquedas por cantidad de asignaciones.
                $tokens = array_values(array_filter(array_unique(array_map('trim', $tokens)), function ($t) {
                    return (string) $t !== '';
                }));
                if (count($tokens) === 0 && count($phrases) === 0) return;

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

                // Primero aplicar frases exactas como AND obligatorias.
                foreach ($phrases as $ph) {
                    $likePhrase = '%' . $ph . '%';
                    $q->where(function ($qq) use ($likePhrase, $applyToken) {
                        $applyToken($qq, $likePhrase, true);
                    });
                }

                // Si no quedan tokens, ya quedó filtrado por frases.
                if (count($tokens) === 0) return;

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

        // Orden por relevancia (mejores coincidencias arriba) cuando se busca.
        // Se activa explícitamente con ?relevance=1 para no interferir con ordenamientos manuales.
        if ($relevance && $search !== '') {
            $searchNorm = strtoupper(trim(str_replace('"', '', $search)));
            // tokens para score: frases entre comillas + tokens sueltos
            $tokensScore = [];
            if (preg_match_all('/"([^"]+)"/', $search, $mm2)) {
                foreach (($mm2[1] ?? []) as $ph) {
                    $ph = trim((string) $ph);
                    if ($ph !== '') $tokensScore[] = $ph;
                }
            }
            $searchWithoutPhrases2 = trim((string) preg_replace('/"[^"]+"/', ' ', $search));
            $more = preg_split('/\s+/', $searchWithoutPhrases2, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $tokensScore = array_merge($tokensScore, $more);
            $tokensScore = array_values(array_filter(array_map('trim', $tokensScore), function ($t) {
                return (string) $t !== '';
            }));

            $fullNameExpr = "UPPER(CONCAT_WS(' ', COALESCE(estudiantesifas.Ap_Paterno,''), COALESCE(estudiantesifas.Ap_Materno,''), COALESCE(estudiantesifas.Nombre,'')))";
            $ciExpr = "UPPER(COALESCE(estudiantesifas.CI,''))";
            $matriculaExpr = "UPPER(COALESCE(infoestudiantesifas.Matricula,''))";
            $instExpr = "UPPER(COALESCE(instituciones.Nombre,''))";
            $cursoParExpr = "UPPER(CONCAT(TRIM(COALESCE(infoestudiantesifas.Curso_Solicitado,'')),' ',TRIM(COALESCE(infoestudiantesifas.Paralelo_Solicitado,''))))";

            $scoreSql = "(CASE\n"
                . " WHEN {$ciExpr} = ? THEN 5000\n"
                . " WHEN {$ciExpr} LIKE CONCAT(?, '%') THEN 3500\n"
                . " WHEN {$matriculaExpr} = ? THEN 3200\n"
                . " WHEN {$matriculaExpr} LIKE CONCAT(?, '%') THEN 2500\n"
                . " WHEN {$fullNameExpr} = ? THEN 2400\n"
                . " WHEN {$fullNameExpr} LIKE CONCAT(?, '%') THEN 1800\n"
                . " ELSE 0\n"
                . " END";

            $bindings = [$searchNorm, $searchNorm, $searchNorm, $searchNorm, $searchNorm, $searchNorm];

            foreach ($tokensScore as $tok) {
                $tokUp = strtoupper((string) $tok);
                $scoreSql .= " + 90 * ((LENGTH({$fullNameExpr}) - LENGTH(REPLACE({$fullNameExpr}, ?, ''))) / GREATEST(LENGTH(?), 1))";
                $bindings[] = $tokUp;
                $bindings[] = $tokUp;

                $scoreSql .= " + 60 * ((LENGTH({$matriculaExpr}) - LENGTH(REPLACE({$matriculaExpr}, ?, ''))) / GREATEST(LENGTH(?), 1))";
                $bindings[] = $tokUp;
                $bindings[] = $tokUp;

                $scoreSql .= " + 25 * ((LENGTH({$cursoParExpr}) - LENGTH(REPLACE({$cursoParExpr}, ?, ''))) / GREATEST(LENGTH(?), 1))";
                $bindings[] = $tokUp;
                $bindings[] = $tokUp;

                $scoreSql .= " + 10 * ((LENGTH({$instExpr}) - LENGTH(REPLACE({$instExpr}, ?, ''))) / GREATEST(LENGTH(?), 1))";
                $bindings[] = $tokUp;
                $bindings[] = $tokUp;

                if (preg_match('/\d/', $tokUp)) {
                    $scoreSql .= " + 120 * ((LENGTH({$ciExpr}) - LENGTH(REPLACE({$ciExpr}, ?, ''))) / GREATEST(LENGTH(?), 1))";
                    $bindings[] = $tokUp;
                    $bindings[] = $tokUp;
                }
            }

            $scoreSql .= ")";

            $query->orderByRaw($scoreSql . ' DESC', $bindings);
        }

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
        } elseif ($sortBy === 'NombreEstudiante') {
            $apPat = "TRIM(COALESCE(estudiantesifas.Ap_Paterno,''))";
            $apMat = "TRIM(COALESCE(estudiantesifas.Ap_Materno,''))";
            $nom = "TRIM(COALESCE(estudiantesifas.Nombre,''))";

            // Vacíos arriba, luego orden paterno -> materno -> nombre.
            $query->orderByRaw("({$apPat} = '') DESC");
            $query->orderByRaw("{$apPat} {$sortDir}");
            $query->orderByRaw("({$apMat} = '') DESC");
            $query->orderByRaw("{$apMat} {$sortDir}");
            $query->orderByRaw("({$nom} = '') DESC");
            $query->orderByRaw("{$nom} {$sortDir}");
        } elseif ($sortBy === 'Anio') {
            $query->orderByRaw($anioSubquery . ' ' . $sortDir);
        } elseif ($sortBy === 'CursoAsignado') {
            $expr = 'TRIM(COALESCE(' . $cursoAsignadoSubquery . ", ''))";
            $query->orderByRaw("({$expr} = '') DESC");
            $query->orderByRaw("{$expr} {$sortDir}");
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


    public function conteoDocentes(Request $request)
    {
        $anioId = $request->query('anio_id');
        $anioScope = (string) $request->query('anio_scope', 'default');
        $includeSinAsignar = filter_var($request->query('include_sin_asignar', '0'), FILTER_VALIDATE_BOOLEAN);
        $institucionId = $request->query('instituciones_id');

        $instrumentoMusical = trim((string) $request->query('InstrumentoMusical', ''));
        $instrumentoMusicalSecundario = trim((string) $request->query('InstrumentoMusicalSecundario', ''));

        $docenteField = trim((string) $request->query('docente_field', 'planteldocadmins_id'));
        $allowed = ['planteldocadmins_id', 'planteldocadmins_idPC', 'planteldocadmins_idOtros'];
        if (!in_array($docenteField, $allowed, true)) {
            $docenteField = 'planteldocadmins_id';
        }
        $docenteCampo = 'infoestudiantesifas.' . $docenteField;

        $user = request()->user();
        $isSuperAdmin = empty($user?->instituciones_id);

        $base = Infoestudiantesifas::query()
            ->leftJoin('instituciones', 'infoestudiantesifas.instituciones_id', '=', 'instituciones.id')
            ->leftJoin('estudiantesifas', 'infoestudiantesifas.estudiantesifas_id', '=', 'estudiantesifas.id')
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('infoestudiantesifas.instituciones_id', $user->instituciones_id);
            })
            ->when($isSuperAdmin && $institucionId !== null && $institucionId !== '' && (int) $institucionId > 0, function ($q) use ($institucionId) {
                $q->where('infoestudiantesifas.instituciones_id', (int) $institucionId);
            })
            ->when($instrumentoMusical !== '', function ($q) use ($instrumentoMusical) {
                $q->whereRaw('TRIM(COALESCE(infoestudiantesifas.InstrumentoMusical, \'\')) = ?', [$instrumentoMusical]);
            })
            ->when($instrumentoMusicalSecundario !== '', function ($q) use ($instrumentoMusicalSecundario) {
                $q->whereRaw('TRIM(COALESCE(infoestudiantesifas.InstrumentoMusicalSecundario, \'\')) = ?', [$instrumentoMusicalSecundario]);
            });

        $anioIdInt = (int) ($anioId ?? 0);
        $this->aplicarFiltroAnio($base, $anioIdInt, $includeSinAsignar, $anioScope);

        $rows = (clone $base)
            ->whereNotNull($docenteCampo)
            ->where($docenteCampo, '<>', 0)
            ->selectRaw("{$docenteCampo} as docente_id, COUNT(*) as total")
            ->groupBy($docenteCampo)
            ->get();

        return response()->json([
            'data' => $rows,
        ]);
    }

    public function optionsCursosDocente(Request $request)
    {
        $anioId = $request->query('anio_id');
        $anioScope = (string) $request->query('anio_scope', 'default');
        $includeSinAsignar = filter_var($request->query('include_sin_asignar', '0'), FILTER_VALIDATE_BOOLEAN);
        $institucionId = $request->query('instituciones_id');

        $docenteId = $request->query('planteldocadmins_id');
        $docenteIdPC = $request->query('planteldocadmins_idPC');
        $docenteIdOtros = $request->query('planteldocadmins_idOtros');

        $instrumentoMusical = trim((string) $request->query('InstrumentoMusical', ''));
        $instrumentoMusicalSecundario = trim((string) $request->query('InstrumentoMusicalSecundario', ''));

        // Elegimos el campo de docente a filtrar según el parámetro recibido.
        $docenteCampo = null;
        $docenteFiltro = null;
        if ($docenteIdPC !== null && $docenteIdPC !== '') {
            $docenteCampo = 'infoestudiantesifas.planteldocadmins_idPC';
            $docenteFiltro = $docenteIdPC;
        } elseif ($docenteIdOtros !== null && $docenteIdOtros !== '') {
            $docenteCampo = 'infoestudiantesifas.planteldocadmins_idOtros';
            $docenteFiltro = $docenteIdOtros;
        } elseif ($docenteId !== null && $docenteId !== '') {
            $docenteCampo = 'infoestudiantesifas.planteldocadmins_id';
            $docenteFiltro = $docenteId;
        }

        $cursoAsignadoSubquery = "(
            SELECT pe.LvlCurso
            FROM calificaciones c
            INNER JOIN materias m ON m.id = c.materias_id
            INNER JOIN plandeestudios pe ON pe.id = m.plandeestudios_id
            INNER JOIN anios a ON a.id = pe.anio_id
            WHERE c.infoestudiantesifas_id = infoestudiantesifas.id
            ORDER BY a.Anio DESC, pe.id DESC
            LIMIT 1
        )";

        $user = request()->user();
        $isSuperAdmin = empty($user?->instituciones_id);

        $base = Infoestudiantesifas::query()
            ->leftJoin('instituciones', 'infoestudiantesifas.instituciones_id', '=', 'instituciones.id')
            ->leftJoin('estudiantesifas', 'infoestudiantesifas.estudiantesifas_id', '=', 'estudiantesifas.id')
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('infoestudiantesifas.instituciones_id', $user->instituciones_id);
            })
            ->when($isSuperAdmin && $institucionId !== null && $institucionId !== '' && (int) $institucionId > 0, function ($q) use ($institucionId) {
                $q->where('infoestudiantesifas.instituciones_id', (int) $institucionId);
            })
            ->when($instrumentoMusical !== '', function ($q) use ($instrumentoMusical) {
                $q->whereRaw('TRIM(COALESCE(infoestudiantesifas.InstrumentoMusical, \'\')) = ?', [$instrumentoMusical]);
            })
            ->when($instrumentoMusicalSecundario !== '', function ($q) use ($instrumentoMusicalSecundario) {
                $q->whereRaw('TRIM(COALESCE(infoestudiantesifas.InstrumentoMusicalSecundario, \'\')) = ?', [$instrumentoMusicalSecundario]);
            });

        if ($docenteCampo !== null && $docenteFiltro !== null && $docenteFiltro !== '' && (int) $docenteFiltro > 0) {
            $base->where($docenteCampo, (int) $docenteFiltro);
        }

        $anioIdInt = (int) ($anioId ?? 0);
        $this->aplicarFiltroAnio($base, $anioIdInt, $includeSinAsignar, $anioScope);

        $cursoSolicitado = (clone $base)
            ->selectRaw("TRIM(COALESCE(infoestudiantesifas.Curso_Solicitado, '')) as curso")
            ->whereRaw("TRIM(COALESCE(infoestudiantesifas.Curso_Solicitado, '')) <> ''")
            ->groupByRaw("TRIM(COALESCE(infoestudiantesifas.Curso_Solicitado, ''))")
            ->orderByRaw("TRIM(COALESCE(infoestudiantesifas.Curso_Solicitado, ''))")
            ->pluck('curso')
            ->values();

        $exprAsignado = "TRIM(COALESCE({$cursoAsignadoSubquery}, ''))";
        $cursoAsignado = (clone $base)
            ->selectRaw("{$exprAsignado} as curso")
            ->whereRaw("{$exprAsignado} <> ''")
            ->groupByRaw($exprAsignado)
            ->orderByRaw($exprAsignado)
            ->pluck('curso')
            ->values();

        return response()->json([
            'data' => [
                'Curso_Solicitado' => $cursoSolicitado,
                'CursoAsignado' => $cursoAsignado,
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
        $user = $request->user();

        $data = $request->validate([
            'estudiantesifas_id' => ['required', 'integer', 'min:1'],
            'planteldocadmins_id' => ['nullable', 'integer'],
            'planteldocadmins_idPC' => ['nullable', 'integer'],
            'planteldocadmins_idOtros' => ['nullable', 'integer'],
            'instituciones_id' => ['nullable', 'integer'],
            'FechInsc' => ['nullable', 'date'],
            'Verificacion' => ['nullable', 'string', 'max:100'],
            'Anotaciones' => ['nullable', 'string'],
            'Notas' => ['nullable', 'string'],
            'Observacion' => ['nullable', 'string'],
            'Matricula' => ['nullable', 'string', 'max:30'],
            'Categoria' => ['nullable', 'string', 'max:50'],
            'Turno' => ['nullable', 'string', 'max:20'],
            'Curso_Solicitado' => ['nullable', 'string', 'max:60'],
            'Paralelo_Solicitado' => ['nullable', 'string', 'max:30'],
            'CantidadMateriasAsignadas' => ['nullable', 'integer'],
            'InstrumentoMusical' => ['nullable', 'string', 'max:100'],
            'InstrumentoMusicalSecundario' => ['nullable', 'string', 'max:100'],
            'FotoPago' => ['nullable', 'string', 'max:250'],
        ]);

        $institucionId = $this->getInstitucionIdFromRequest($request, $user);
        if ($institucionId <= 0) {
            return response()->json(['message' => 'instituciones_id es requerido'], 422);
        }
        $data['instituciones_id'] = $institucionId;

        if ($resp = $this->validarDocentesMismaInstitucion($data, $institucionId)) {
            return $resp;
        }

        $anio = Carbon::now()->year;
        $force = filter_var($request->query('force', $request->input('force', '0')), FILTER_VALIDATE_BOOLEAN);

        $dup = $this->existeInscripcionMismaInstMismoAnio((int) $data['estudiantesifas_id'], $institucionId, $anio, null);
        if ($dup && !$force) {
            return response()->json([
                'message' => 'Ya existe una inscripción de este estudiante en esta institución en la gestión actual.',
                'requires_confirmation' => true,
                'duplicate' => $dup,
                'anio' => $anio,
            ], 409);
        }

        $row = Infoestudiantesifas::create($data);
        return response()->json(['data' => $row], 201);
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

        $payload = $request->validate([
            'estudiantesifas_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'planteldocadmins_id' => ['nullable', 'integer'],
            'planteldocadmins_idPC' => ['nullable', 'integer'],
            'planteldocadmins_idOtros' => ['nullable', 'integer'],
            'instituciones_id' => ['nullable', 'integer'],
            'FechInsc' => ['nullable', 'date'],
            'Verificacion' => ['nullable', 'string', 'max:100'],
            'Anotaciones' => ['nullable', 'string'],
            'Notas' => ['nullable', 'string'],
            'Observacion' => ['nullable', 'string'],
            'Matricula' => ['nullable', 'string', 'max:30'],
            'Categoria' => ['nullable', 'string', 'max:50'],
            'Turno' => ['nullable', 'string', 'max:20'],
            'Curso_Solicitado' => ['nullable', 'string', 'max:60'],
            'Paralelo_Solicitado' => ['nullable', 'string', 'max:30'],
            'CantidadMateriasAsignadas' => ['nullable', 'integer'],
            'InstrumentoMusical' => ['nullable', 'string', 'max:100'],
            'InstrumentoMusicalSecundario' => ['nullable', 'string', 'max:100'],
            'FotoPago' => ['nullable', 'string', 'max:250'],
        ]);

        $institucionId = $this->getInstitucionIdFromRequest($request, $user);
        if ($institucionId > 0) {
            $payload['instituciones_id'] = $institucionId;
        }

        $estudianteId = (int) ($payload['estudiantesifas_id'] ?? $row->estudiantesifas_id);
        $instId = (int) ($payload['instituciones_id'] ?? $row->instituciones_id);
        $anio = Carbon::now()->year;

        if ($resp = $this->validarDocentesMismaInstitucion($payload, $instId)) {
            return $resp;
        }

        $force = filter_var($request->query('force', $request->input('force', '0')), FILTER_VALIDATE_BOOLEAN);
        $dup = $this->existeInscripcionMismaInstMismoAnio($estudianteId, $instId, $anio, (int) $row->id);
        if ($dup && !$force) {
            return response()->json([
                'message' => 'Ya existe una inscripción de este estudiante en esta institución en la gestión actual.',
                'requires_confirmation' => true,
                'duplicate' => $dup,
                'anio' => $anio,
            ], 409);
        }

        $row->update($payload);
        return response()->json(['data' => $row]);
    }

    public function bulkUpdateDocente(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'min:1'],
            'field' => ['required', 'in:planteldocadmins_id,planteldocadmins_idPC,planteldocadmins_idOtros'],
            'docente_id' => ['required', 'integer', 'min:1'],
            'instituciones_id' => ['nullable', 'integer'],
        ]);

        $institucionId = $this->getInstitucionIdFromRequest($request, $user);
        if ($institucionId <= 0) {
            return response()->json(['message' => 'instituciones_id es requerido'], 422);
        }

        $docenteId = (int) $validated['docente_id'];
        $okDoc = DB::table('planteldocentes')
            ->where('id', $docenteId)
            ->where('instituciones_id', $institucionId)
            ->exists();
        if (!$okDoc) {
            return response()->json([
                'message' => 'Docente no pertenece a la institución.',
                'docente_id' => $docenteId,
                'instituciones_id' => $institucionId,
            ], 422);
        }

        $field = (string) $validated['field'];
        $ids = array_values(array_unique(array_map('intval', $validated['ids'] ?? [])));

        $affected = Infoestudiantesifas::query()
            ->where('instituciones_id', $institucionId)
            ->whereIn('id', $ids)
            ->update([$field => $docenteId]);

        return response()->json([
            'success' => true,
            'affected' => (int) $affected,
            'field' => $field,
            'docente_id' => $docenteId,
            'instituciones_id' => $institucionId,
        ]);
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


    function CargarInformacionInscripcione1s(Request $request) {
        $user = $request->user(); //$user?->instituciones_id
        //Ap_Paterno,Ap_Materno,Nombre,CI,FechNac,Sexo,Direccion,Edad,  Area,Carrera,Malla, Curso_Solicitado,Nivel,Turno, Matricula,Categoria, created_at,updated_at,FechInsc
        // $data = DB::select('SELECT estudiantesifas.Ap_Paterno,estudiantesifas.Ap_Materno,estudiantesifas.Nombre,estudiantesifas.CI,estudiantesifas.FechaNac,estudiantesifas.Sexo,estudiantesifas.Direccion,estudiantesifas.Edad,
        //     infoestudiantesifas.Curso_Solicitado,infoestudiantesifas.Turno, infoestudiantesifas.Matricula,infoestudiantesifas.Categoria, infoestudiantesifas.FechInsc,
        //     carreras.Area,carreras.NombreCarrera,carreras.Resolucion
        //     FROM infoestudiantesifas
        //     INNER JOIN estudiantesifas ON estudiantesifas.id = infoestudiantesifas.estudiantesifas_id
        //     INNER JOIN instituciones ON instituciones.id = infoestudiantesifas.instituciones_id
        //     INNER JOIN carreras ON carreras.instituciones_id = instituciones.id
        //     INNER JOIN plandeestudios ON plandeestudios.carreras_id = carreras.id
        //     WHERE infoestudiantesifas.instituciones_id = '.$user?->instituciones_id.' AND 
        // ');
        

    }
    

    //#endregion Fin Controller de Crud PHP de infoestudiantesifas
}
