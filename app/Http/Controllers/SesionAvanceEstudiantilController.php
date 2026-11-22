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
use Illuminate\Validation\ValidationException;

class SesionAvanceEstudiantilController extends Controller
{
    private const MODOS_POR_TIPO = [
        'ESPECIALIDAD' => ['MODO INSTRUMENTOS DE ESPECIALIDAD', 'MODO INSTRUMENTO DE ESPECIALIDAD'],
        'PRACTICA_CONJUNTOS' => ['MODO PRÁCTICA DE CONJUNTOS', 'MODO PRACTICA DE CONJUNTOS', 'MODO PRÁCTICA DE CONJUNTO', 'MODO PRACTICA DE CONJUNTO'],
        'COMPLEMENTARIO' => ['MODO INSTRUMENTO COMPLEMENTARIO', 'MODO OTROS'],
    ];

    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }

    /** Obtener el ID del año predeterminado. */
    private ?int $anioPredeterminadoIdCache = null;

    private function getAnioPredeterminadoId(): ?int
    {
        if ($this->anioPredeterminadoIdCache === null) {
            $this->anioPredeterminadoIdCache = DB::table('anios')
                ->where('Predeterminado', 'PREDETERMINADO')
                ->orderByDesc('id')
                ->value('id');
        }
        return $this->anioPredeterminadoIdCache;
    }

    /** Resolver anio_id desde request o usar predeterminado. */
    private function resolveAnioId(Request $request): int
    {
        $anioId = $request->query('anios_id');
        if ($anioId !== null && $anioId !== '') {
            $anioId = (int) $anioId;
        } else {
            $anioId = $this->getAnioPredeterminadoId();
        }
        if (!$anioId || $anioId < 1) {
            throw ValidationException::withMessages(['anios_id' => 'Seleccione una gestión o configure una gestión predeterminada.']);
        }
        return $anioId;
    }

    /** Filtrar por materia de la gestión y del tipo de asignación correspondiente. */
    private function scopeAnio($query, int $anioId, string $tipo, string $infoAlias = 'info'): void
    {
        $modos = self::MODOS_POR_TIPO[$tipo] ?? [];
        $query->whereExists(function ($sub) use ($anioId, $infoAlias, $modos) {
            $sub->select(DB::raw(1))
                ->from('calificaciones as _cal')
                ->join('materias as _m', '_cal.materias_id', '=', '_m.id')
                ->join('plandeestudios as _p', '_m.plandeestudios_id', '=', '_p.id')
                ->whereColumn('_cal.infoestudiantesifas_id', "$infoAlias.id")
                ->where('_p.anio_id', $anioId)
                ->whereIn('_p.ModoMateria', $modos);
        });
    }

    private function gestionesPorInscripcion(array $infoIds): array
    {
        if (!$infoIds) return [];

        $rows = DB::table('calificaciones as cal')
            ->join('materias as mat', 'cal.materias_id', '=', 'mat.id')
            ->join('plandeestudios as plan', 'mat.plandeestudios_id', '=', 'plan.id')
            ->join('anios as anio', 'plan.anio_id', '=', 'anio.id')
            ->whereIn('cal.infoestudiantesifas_id', $infoIds)
            ->select('cal.infoestudiantesifas_id', 'plan.ModoMateria', 'anio.id', 'anio.Anio')
            ->distinct()
            ->get();

        $result = [];
        foreach ($rows as $row) {
            foreach (self::MODOS_POR_TIPO as $tipo => $modos) {
                if (in_array(mb_strtoupper(trim((string) $row->ModoMateria), 'UTF-8'), $modos, true)) {
                    $result[(int) $row->infoestudiantesifas_id][$tipo][(int) $row->id] = $row->Anio;
                    break;
                }
            }
        }
        return $result;
    }

    private function aplicarGestionSesiones($query, array $gestiones, int $anioId): void
    {
        $permitidos = [];
        foreach ($gestiones as $infoId => $tipos) {
            foreach ($tipos as $tipo => $anios) {
                if (!array_key_exists($anioId, $anios)) continue;
                if (count($anios) > 1) {
                    $ambiguas = (clone $query)->where('sesiones_avance_estudiantil.infoestudiantesifas_id', $infoId)
                        ->where('sesiones_avance_estudiantil.tipo_asignacion', $tipo)->exists();
                    if ($ambiguas) {
                        throw ValidationException::withMessages([
                            'anios_id' => 'Una inscripción tiene materias del mismo tipo en varias gestiones. Solicite revisar su asignación antes de consultar las sesiones.',
                        ]);
                    }
                    continue;
                }
                $permitidos[$tipo][] = $infoId;
            }
        }

        $query->where(function ($filtro) use ($permitidos) {
            foreach ($permitidos as $tipo => $ids) {
                $filtro->orWhere(function ($sub) use ($tipo, $ids) {
                    $sub->where('sesiones_avance_estudiantil.tipo_asignacion', $tipo)
                        ->whereIn('sesiones_avance_estudiantil.infoestudiantesifas_id', $ids);
                });
            }
            if (!$permitidos) $filtro->whereRaw('1 = 0');
        });
    }

    private function agregarGestionSesiones($sesiones, array $gestiones): void
    {
        foreach ($sesiones as $sesion) {
            $anios = $gestiones[(int) $sesion->infoestudiantesifas_id][$sesion->tipo_asignacion] ?? [];
            $sesion->setAttribute('gestion_id', count($anios) === 1 ? array_key_first($anios) : null);
            $sesion->setAttribute('gestion_nombre', count($anios) === 1 ? reset($anios) : null);
            $sesion->setAttribute('gestion_ambigua', count($anios) > 1);
        }
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
        $anioId    = $this->resolveAnioId($request);

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
        $qEsp = DB::table('infoestudiantesifas as info')
            ->join('estudiantesifas as e', 'info.estudiantesifas_id', '=', 'e.id')
            ->join('instituciones as i', 'info.instituciones_id', '=', 'i.id')
            ->where('info.planteldocadmins_id', $docenteId)
            ->where('info.instituciones_id', $instId);
        $this->scopeAnio($qEsp, $anioId, 'ESPECIALIDAD');
        $especialidad = $qEsp->select($selectBase)
            ->orderBy('e.Ap_Paterno')
            ->orderBy('e.Ap_Materno')
            ->orderBy('e.Nombre')
            ->get()
            ->map(fn ($r) => (array) $r + ['tipo_asignacion' => 'ESPECIALIDAD']);

        // 2) Práctica de Conjuntos (planteldocadmins_idPC)
        $qPra = DB::table('infoestudiantesifas as info')
            ->join('estudiantesifas as e', 'info.estudiantesifas_id', '=', 'e.id')
            ->join('instituciones as i', 'info.instituciones_id', '=', 'i.id')
            ->where('info.planteldocadmins_idPC', $docenteId)
            ->where('info.instituciones_id', $instId);
        $this->scopeAnio($qPra, $anioId, 'PRACTICA_CONJUNTOS');
        $practica = $qPra->select($selectBase)
            ->orderBy('e.Ap_Paterno')
            ->orderBy('e.Ap_Materno')
            ->orderBy('e.Nombre')
            ->get()
            ->map(fn ($r) => (array) $r + ['tipo_asignacion' => 'PRACTICA_CONJUNTOS']);

        // 3) Instrumento Complementario (planteldocadmins_idOtros)
        $qCom = DB::table('infoestudiantesifas as info')
            ->join('estudiantesifas as e', 'info.estudiantesifas_id', '=', 'e.id')
            ->join('instituciones as i', 'info.instituciones_id', '=', 'i.id')
            ->where('info.planteldocadmins_idOtros', $docenteId)
            ->where('info.instituciones_id', $instId);
        $this->scopeAnio($qCom, $anioId, 'COMPLEMENTARIO');
        $complementario = $qCom->select($selectBase)
            ->orderBy('e.Ap_Paterno')
            ->orderBy('e.Ap_Materno')
            ->orderBy('e.Nombre')
            ->get()
            ->map(fn ($r) => (array) $r + ['tipo_asignacion' => 'COMPLEMENTARIO']);

        $anioNombre = $anioId ? DB::table('anios')->where('id', $anioId)->value('Anio') : null;

        return response()->json([
            'especialidad'    => $especialidad->values(),
            'practica'        => $practica->values(),
            'complementario'  => $complementario->values(),
            'anio_id'         => $anioId,
            'anio_nombre'     => $anioNombre,
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
    private function queryMisEstudiantesBase($userOrDocenteId, string $tipo, ?int $instId, int $anioId)
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

        $q = DB::table('infoestudiantesifas as info')
            ->join('estudiantesifas as e', 'info.estudiantesifas_id', '=', 'e.id')
            ->join('instituciones as i', 'info.instituciones_id', '=', 'i.id')
            ->where("info.$col", $docenteId)
            ->where('info.instituciones_id', $instId);
        $this->scopeAnio($q, $anioId, $tipo);
        return $q->select($selectBase);
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

        $anioId = $this->resolveAnioId($request);

        $base = DB::table('infoestudiantesifas as info')->where('info.instituciones_id', $instId);
        $qEsp = (clone $base)->where('info.planteldocadmins_id', $docenteId);
        $qPra = (clone $base)->where('info.planteldocadmins_idPC', $docenteId);
        $qCom = (clone $base)->where('info.planteldocadmins_idOtros', $docenteId);
        $this->scopeAnio($qEsp, $anioId, 'ESPECIALIDAD');
        $this->scopeAnio($qPra, $anioId, 'PRACTICA_CONJUNTOS');
        $this->scopeAnio($qCom, $anioId, 'COMPLEMENTARIO');
        $especialidad = $qEsp->count();
        $practica = $qPra->count();
        $complement = $qCom->count();

        $anioNombre = $anioId ? DB::table('anios')->where('id', $anioId)->value('Anio') : null;

        return response()->json([
            'especialidad'   => (int) $especialidad,
            'practica'       => (int) $practica,
            'complementario' => (int) $complement,
            'anio_id'        => $anioId,
            'anio_nombre'    => $anioNombre,
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

        $anioId = $this->resolveAnioId($request);

        $q = DB::table('infoestudiantesifas as info')
            ->where("info.$col", $docenteId)
            ->where('info.instituciones_id', $instId);
        $this->scopeAnio($q, $anioId, $tipo);

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
        $anioId    = $this->resolveAnioId($request);

        $rows = DB::table('infoestudiantesifas as info')
            ->where("info.$col", $docenteId)
            ->where('info.instituciones_id', $instId);
        $this->scopeAnio($rows, $anioId, $tipo);
        $rows = $rows->select([
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
        $anioId = $this->resolveAnioId($request);

        if ($isAdmin) {
            $adminDocenteId = (int) $request->query('planteldocentes_id', 0);
            if ($adminDocenteId <= 0) {
                return response()->json(['message' => 'Debe indicar planteldocentes_id.'], 422);
            }
            $docente = Planteldocentes::find($adminDocenteId);
            if (!$docente) return response()->json(['message' => 'Docente no encontrado.'], 404);
            $q = $this->queryMisEstudiantesBase($docente->id, $tipo, (int) $docente->instituciones_id, $anioId);
            $docenteId = (int) $docente->id;
        } else {
            $q = $this->queryMisEstudiantesBase($user, $tipo, null, $anioId);
            $docenteId = (int) $user->id;
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

        $diaSemana = $request->query('dia_semana');
        $horaActual = $request->query('hora_actual');

        if (!empty($diaSemana)) {
            $q->whereExists(function ($horarios) use ($tipo, $diaSemana, $horaActual) {
                $horarios->select(DB::raw(1))
                    ->from('horarios_estudiantes as he')
                    ->whereColumn('he.infoestudiantesifas_id', 'info.id')
                    ->where('he.tipo_asignacion', $tipo);

                if (!empty($horaActual)) {
                    $diaAnterior = (int) $diaSemana === 1 ? 7 : (int) $diaSemana - 1;
                    $horarios->where(function ($rango) use ($diaSemana, $diaAnterior, $horaActual) {
                        $rango->where(function ($normal) use ($diaSemana, $horaActual) {
                            $normal->where('he.dia_semana', (int) $diaSemana)
                                ->whereRaw('SUBSTR(he.hora_inicio, 1, 5) <= SUBSTR(he.hora_fin, 1, 5)')
                                ->whereRaw('SUBSTR(he.hora_inicio, 1, 5) <= ?', [$horaActual])
                                ->whereRaw('SUBSTR(he.hora_fin, 1, 5) >= ?', [$horaActual]);
                        })->orWhere(function ($inicio) use ($diaSemana, $horaActual) {
                            $inicio->where('he.dia_semana', (int) $diaSemana)
                                ->whereRaw('SUBSTR(he.hora_inicio, 1, 5) > SUBSTR(he.hora_fin, 1, 5)')
                                ->whereRaw('SUBSTR(he.hora_inicio, 1, 5) <= ?', [$horaActual]);
                        })->orWhere(function ($fin) use ($diaAnterior, $horaActual) {
                            $fin->where('he.dia_semana', $diaAnterior)
                                ->whereRaw('SUBSTR(he.hora_inicio, 1, 5) > SUBSTR(he.hora_fin, 1, 5)')
                                ->whereRaw('SUBSTR(he.hora_fin, 1, 5) >= ?', [$horaActual]);
                        });
                    });
                } else {
                    $horarios->where('he.dia_semana', (int) $diaSemana);
                }
            });
        }
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

        if ($request->boolean('agrupar_curso')) {
            $q->orderByRaw("TRIM(COALESCE(info.Curso_Solicitado, ''))")
                ->orderByRaw("TRIM(COALESCE(info.Paralelo_Solicitado, ''))");
        }
        $q->orderBy('e.Ap_Paterno')->orderBy('e.Ap_Materno')->orderBy('e.Nombre')->orderBy('info.id');

        $paginated = $q->paginate($perPage);
        // 2. ADJUNTAR LOS HORARIOS A LOS ESTUDIANTES OBTENIDOS
        $estudiantesIds = collect($paginated->items())->pluck('infoestudiantesifas_id')->toArray();
        if (!empty($estudiantesIds)) {
            $ultimosAvances = DB::table('sesiones_avance_estudiantil as sesion')
                ->whereIn('sesion.infoestudiantesifas_id', $estudiantesIds)
                ->where('sesion.planteldocentes_id', $docenteId)
                ->where('sesion.tipo_asignacion', $tipo)
                ->where('sesion.id', '=', function ($ultima) {
                    $ultima->select('anterior.id')
                        ->from('sesiones_avance_estudiantil as anterior')
                        ->whereColumn('anterior.infoestudiantesifas_id', 'sesion.infoestudiantesifas_id')
                        ->whereColumn('anterior.planteldocentes_id', 'sesion.planteldocentes_id')
                        ->whereColumn('anterior.tipo_asignacion', 'sesion.tipo_asignacion')
                        ->orderByDesc('anterior.fecha')
                        ->orderByDesc('anterior.id')
                        ->limit(1);
                })
                ->get(['sesion.infoestudiantesifas_id', 'sesion.avance_texto'])
                ->keyBy('infoestudiantesifas_id');

            $horarios = DB::table('horarios_estudiantes')
                ->whereIn('infoestudiantesifas_id', $estudiantesIds)
                ->where('tipo_asignacion', $tipo)
                ->orderBy('dia_semana')
                ->get()
                ->groupBy('infoestudiantesifas_id');

            foreach ($paginated->items() as $item) {
                $item->horarios = $horarios->get($item->infoestudiantesifas_id, []);
                $avance = $ultimosAvances->get($item->infoestudiantesifas_id)?->avance_texto;
                $texto = $avance === null ? null : mb_strtoupper(html_entity_decode(strip_tags($avance), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $sinContenido = $texto === null || (
                    preg_match('/[^\s\x{00A0}\x{200B}\x{FEFF}]/u', $texto) === 0
                    && !preg_match('/<(?:img|video|audio|iframe|object|embed|svg|canvas)\b/i', $avance)
                );
                $item->estado_avance = match (true) {
                    $sinContenido => 'sin_avance',
                    str_contains($texto, 'RETIRADO') => 'retirado',
                    str_contains($texto, 'ABANDONO') => 'abandono',
                    default => 'normal',
                };
            }
        }

        return response()->json($paginated);
        // return response()->json($paginated);
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
        $anioId = $this->resolveAnioId($request);

        if ($isAdmin) {
            $adminDocenteId = (int) $request->query('planteldocentes_id', 0);
            if ($adminDocenteId <= 0) return response()->json(['message' => 'Debe indicar planteldocentes_id.'], 422);
            $docente = Planteldocentes::find($adminDocenteId);
            if (!$docente) return response()->json(['message' => 'Docente no encontrado.'], 404);
            $q = $this->queryMisEstudiantesBase($docente->id, $tipo, (int) $docente->instituciones_id, $anioId);
        } else {
            $q = $this->queryMisEstudiantesBase($user, $tipo, null, $anioId);
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

    private function queryGestorHorarios(Request $request, string $tipo)
    {
        $user = $request->user();
        $anioId = $this->resolveAnioId($request);
        if ($user instanceof Planteldocentes) {
            return $this->queryMisEstudiantesBase($user, $tipo, null, $anioId);
        }
        if ($user instanceof Planteladministrativos || $user instanceof Usuarioslcchs) {
            $docente = Planteldocentes::find((int) $request->query('planteldocentes_id', 0));
            if ($docente) {
                return $this->queryMisEstudiantesBase($docente->id, $tipo, (int) $docente->instituciones_id, $anioId);
            }
        }
        return null;
    }

    public function horariosGestor(Request $request)
    {
        $tipo = (string) $request->query('tipo_asignacion', '');
        $q = $this->queryGestorHorarios($request, $tipo);
        if (!$q) return response()->json(['message' => 'No se pudo acceder a los estudiantes de esta asignación.'], 403);

        $estudiantes = $q->orderBy('info.Curso_Solicitado')->orderBy('info.Paralelo_Solicitado')
            ->orderBy('e.Ap_Paterno')->orderBy('e.Ap_Materno')->orderBy('e.Nombre')->get();
        $horarios = DB::table('horarios_estudiantes')
            ->whereIn('infoestudiantesifas_id', $estudiantes->pluck('infoestudiantesifas_id'))
            ->where('tipo_asignacion', $tipo)
            ->orderBy('dia_semana')->orderBy('hora_inicio')->get()
            ->groupBy('infoestudiantesifas_id');
        foreach ($estudiantes as $estudiante) {
            $estudiante->horarios = $horarios->get($estudiante->infoestudiantesifas_id, collect());
        }
        return response()->json(['data' => $estudiantes]);
    }

    public function guardarHorariosGestor(Request $request)
    {
        $tipo = (string) $request->query('tipo_asignacion', '');
        $q = $this->queryGestorHorarios($request, $tipo);
        if (!$q) return response()->json(['message' => 'No se pudo acceder a los estudiantes de esta asignación.'], 403);

        $datos = $request->validate([
            'estudiantes' => 'required|array|min:1',
            'estudiantes.*.id' => 'required|integer|distinct',
            'estudiantes.*.horarios' => 'present|array',
            'estudiantes.*.horarios.*.dia_semana' => 'required|integer|between:1,7',
            'estudiantes.*.horarios.*.hora_inicio' => 'present|nullable|date_format:H:i',
            'estudiantes.*.horarios.*.hora_fin' => 'present|nullable|date_format:H:i',
        ]);
        $ids = array_column($datos['estudiantes'], 'id');
        $autorizados = $q->whereIn('info.id', $ids)->get()->pluck('infoestudiantesifas_id')
            ->map(fn ($id) => (int) $id)->all();
        if (count($autorizados) !== count($ids)) {
            return response()->json(['message' => 'Hay estudiantes que no pertenecen a esta asignación.'], 403);
        }

        DB::transaction(function () use ($datos, $ids, $tipo) {
            DB::table('horarios_estudiantes')->whereIn('infoestudiantesifas_id', $ids)
                ->where('tipo_asignacion', $tipo)->delete();
            $filas = [];
            foreach ($datos['estudiantes'] as $estudiante) {
                foreach ($estudiante['horarios'] as $horario) {
                    $filas[] = [
                        'infoestudiantesifas_id' => (int) $estudiante['id'],
                        'tipo_asignacion' => $tipo,
                        'dia_semana' => (int) $horario['dia_semana'],
                        'hora_inicio' => $horario['hora_inicio'],
                        'hora_fin' => $horario['hora_fin'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }
            foreach (array_chunk($filas, 500) as $bloque) {
                DB::table('horarios_estudiantes')->insert($bloque);
            }
        });

        return response()->json(['message' => 'Horarios actualizados correctamente.']);
    }

    public function calificacionesGrupales(Request $request)
    {
        $user = $request->user();
        if (!($user instanceof Planteldocentes) && !($user instanceof Planteladministrativos) && !($user instanceof Usuarioslcchs)) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $datos = $request->validate([
            'info_ids' => 'required|array|min:1|max:200',
            'info_ids.*' => 'required|integer|min:1|distinct',
            'tipo_asignacion' => 'required|in:ESPECIALIDAD,PRACTICA_CONJUNTOS,COMPLEMENTARIO',
            'anios_id' => 'nullable|integer|min:1',
            'planteldocentes_id' => 'nullable|integer|min:1',
        ]);
        if ($user instanceof Planteldocentes) {
            $docente = $user;
        } else {
            $docente = Planteldocentes::find((int) ($datos['planteldocentes_id'] ?? 0));
            $esSuper = $user instanceof Usuarioslcchs && empty($user->instituciones_id);
            if (!$docente || (!$esSuper && ((int) $user->instituciones_id < 1 || (int) $user->instituciones_id !== (int) $docente->instituciones_id))) {
                return response()->json(['message' => 'Docente no autorizado.'], 403);
            }
        }
        $anioId = $this->resolveAnioId($request);
        $ids = array_map('intval', $datos['info_ids']);
        $autorizados = $this->queryMisEstudiantesBase($docente, $datos['tipo_asignacion'], null, $anioId)
            ->whereIn('info.id', $ids)->get()->pluck('infoestudiantesifas_id')->map(fn ($id) => (int) $id)->all();
        if (count($autorizados) !== count($ids)) {
            return response()->json(['message' => 'Hay estudiantes que no pertenecen a esta asignación o gestión.'], 403);
        }

        $calificaciones = DB::table('calificaciones as cal')
            ->join('materias as m', 'cal.materias_id', '=', 'm.id')
            ->join('plandeestudios as p', 'm.plandeestudios_id', '=', 'p.id')
            ->join('carreras as c', 'p.carreras_id', '=', 'c.id')
            ->leftJoin('anios as a', 'p.anio_id', '=', 'a.id')
            ->whereIn('cal.infoestudiantesifas_id', $ids)
            ->where('p.anio_id', $anioId)
            ->where('c.instituciones_id', (int) $docente->instituciones_id)
            ->select('cal.*', 'm.Paralelo as MateriaParalelo', 'p.NombreMateria', 'p.SiglaMateria',
                'p.LvlCurso', 'a.Anio', 'c.NombreCarrera', 'c.CantidadEvaluaciones')
            ->orderBy('p.RangoLvlCurso')->orderBy('p.Rango')->orderBy('p.NombreMateria')->orderBy('cal.id')
            ->get();

        $porEstudiante = array_fill_keys($ids, []);
        foreach ($calificaciones as $cal) {
            $porEstudiante[(int) $cal->infoestudiantesifas_id][(int) $cal->materias_id] = [
                'materia_id' => (int) $cal->materias_id,
                'nombre_materia' => $cal->NombreMateria,
                'sigla_materia' => $cal->SiglaMateria,
                'lvl_curso' => $cal->LvlCurso,
                'paralelo' => $cal->MateriaParalelo,
                'anio' => $cal->Anio,
                'carrera' => $cal->NombreCarrera,
                'cantidad_evaluaciones' => $cal->CantidadEvaluaciones,
                'calificacion' => $cal,
            ];
        }
        $resultado = [];
        foreach ($ids as $id) {
            $resultado[] = ['infoestudiantesifas_id' => $id, 'materias' => array_values($porEstudiante[$id])];
        }
        return response()->json(['data' => $resultado]);
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

        $request->validate(['anios_id' => ['nullable', 'integer', 'min:1']]);
        $query = SesionAvanceEstudiantil::where('infoestudiantesifas_id', $infoId)
            ->where('planteldocentes_id', $docenteId)
            ->where('tipo_asignacion', $tipo);

        if ($eval !== null && $eval !== '') {
            $query->where('evaluacion', (int) $eval);
        }
        $gestiones = $this->gestionesPorInscripcion([$infoId]);
        if ($request->filled('anios_id')) {
            $this->aplicarGestionSesiones($query, $gestiones, (int) $request->query('anios_id'));
        }

        $perPage = (int) $request->query('per_page', 0);
        if ($perPage > 0) {
            $paginated = $query->orderBy('fecha', 'desc')
                ->orderBy('id', 'desc')
                ->paginate($perPage);
            $this->agregarGestionSesiones($paginated->items(), $gestiones);
            return response()->json($paginated);
        }

        $sesiones = $query->orderBy('fecha', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        $this->agregarGestionSesiones($sesiones, $gestiones);
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
        $validated = $request->validate(['anios_id' => ['nullable', 'integer', 'min:1']]);
        $user = $request->user();

        if ($user instanceof \App\Models\Estudiantesifas) {
            // 1. Obtener TODOS los IDs de inscripción (infoestudiantesifas_id) del estudiante.
            // Esto soluciona el problema de los múltiples institutos.
            $infoQuery = DB::table('infoestudiantesifas as info')
                ->where('info.estudiantesifas_id', (int) $user->id);
            
            // El estudiante conserva el acceso a su historial de años pasados.
            
            $infoIds = $infoQuery->pluck('info.id')->toArray();

            // Si el estudiante no tiene inscripciones, devolvemos un data vacío (200 OK) 
            // para que Angular no rompa con un error 404.
            if (empty($infoIds)) {
                return response()->json(['data' => []]);
            }
        } else {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $tipo = $request->query('tipo_asignacion', '');
        $eval = $request->query('evaluacion', null);

        // 2. Buscar las sesiones usando whereIn (para abarcar todos sus institutos)
        // y haciendo un JOIN para adjuntar el nombre y logo de la institución
        $query = SesionAvanceEstudiantil::query()
            ->select(
                'sesiones_avance_estudiantil.*', 
                'inst.Nombre as institucion_nombre', 
                'inst.Logo as institucion_logo', 
                'inst.ColorBajo as institucion_color'
            )
            ->join('infoestudiantesifas as info', 'sesiones_avance_estudiantil.infoestudiantesifas_id', '=', 'info.id')
            ->join('instituciones as inst', 'info.instituciones_id', '=', 'inst.id')
            ->whereIn('sesiones_avance_estudiantil.infoestudiantesifas_id', $infoIds);

        if ($tipo) {
            $query->where('sesiones_avance_estudiantil.tipo_asignacion', $tipo);
        }
        if ($eval !== null && $eval !== '') {
            $query->where('sesiones_avance_estudiantil.evaluacion', (int) $eval);
        }
        $gestiones = $this->gestionesPorInscripcion($infoIds);
        if (isset($validated['anios_id'])) {
            $this->aplicarGestionSesiones($query, $gestiones, (int) $validated['anios_id']);
        }

        $perPage = (int) $request->query('per_page', 0);
        if ($perPage > 0) {
            $paginated = $query->orderBy('sesiones_avance_estudiantil.fecha', 'desc')
                ->orderBy('sesiones_avance_estudiantil.id', 'desc')
                ->paginate($perPage);
            $this->agregarGestionSesiones($paginated->items(), $gestiones);
            return response()->json($paginated);
        }

        $sesiones = $query->orderBy('sesiones_avance_estudiantil.fecha', 'desc')
            ->orderBy('sesiones_avance_estudiantil.id', 'desc')
            ->get();

        $this->agregarGestionSesiones($sesiones, $gestiones);
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
        $anioId = $this->resolveAnioId($request);
        $estudiantesIds = $this->obtenerIdsEstudiantes($user, $tipo, $anioId, $cursos);
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

        try {
            $hoy = now()->toDateString();

            $logs = TerminarClaseLog::where('planteldocentes_id', (int) $user->id)
                ->whereDate('created_at', $hoy)
                ->orderByDesc('id')
                ->get(['id', 'tipo_asignacion', 'evaluacion', 'fecha', 'cantidad_creadas', 'deshecho_at', 'created_at']);

            return response()->json(['data' => $logs]);
        } catch (\Throwable $e) {
            return response()->json(['data' => []]);
        }
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
        if (!($user instanceof \App\Models\Planteldocentes)) {
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
        $actualizadas = 0;
        $idsCreados = [];

        // Ordenar fechas cronológicamente
        sort($fechas);

        // EXTRAER HORARIOS DE LOS ESTUDIANTES SELECCIONADOS (Optimizado en una sola consulta)
        $horarios = \Illuminate\Support\Facades\DB::table('horarios_estudiantes')
            ->whereIn('infoestudiantesifas_id', $infoIds)
            ->where('tipo_asignacion', $tipo)
            ->get()
            ->groupBy('infoestudiantesifas_id');

        foreach ($infoIds as $infoId) {
            $infoId = (int) $infoId;
            if ($infoId <= 0) continue;

            // Obtener los días de la semana permitidos (1=Lunes a 7=Domingo) para este estudiante
            $horariosEst = $horarios->get($infoId, collect());
            $diasPermitidos = $horariosEst->pluck('dia_semana')->toArray();

            // Si el estudiante no tiene horario registrado, saltamos para evitar llenarlo de faltas erróneas
            if (empty($diasPermitidos)) {
                continue; 
            }

            foreach ($fechas as $fecha) {
                $fecha = trim((string) $fecha);
                if (!$fecha) continue;

                // VALIDACIÓN DEL HORARIO INDIVIDUAL
                $diaFecha = (int) date('N', strtotime($fecha)); // Obtiene el día de 1 a 7
                if (!in_array($diaFecha, $diasPermitidos)) {
                    continue; // El estudiante NO tiene clases en este día de la semana, lo ignoramos
                }

                // La fecha identifica la clase. La evaluación elegida debe aplicarse
                // también a una sesión existente con otra evaluación.
                $sesionExistente = SesionAvanceEstudiantil::where('infoestudiantesifas_id', $infoId)
                    ->where('planteldocentes_id', $docenteId)
                    ->where('tipo_asignacion', $tipo)
                    ->where('fecha', $fecha)
                    ->orderByDesc('id')
                    ->first();

                if ($sesionExistente) {
                    if ((int) $sesionExistente->evaluacion !== $eval) {
                        $sesionExistente->update(['evaluacion' => $eval]);
                        $actualizadas++;
                    }
                    continue;
                }

                // Buscar última sesión anterior a esta fecha para copiar avance_texto,
                // incluso si pertenece a otra evaluación.
                $ultimaAnterior = SesionAvanceEstudiantil::where('infoestudiantesifas_id', $infoId)
                    ->where('planteldocentes_id', $docenteId)
                    ->where('tipo_asignacion', $tipo)
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

        // Registrar log para poder usar deshacerEstabilizar
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
            'message' => "Estabilización completada. Se crearon $creadas registros de falta y se actualizaron $actualizadas evaluaciones basados estrictamente en los horarios individuales.",
            'creadas' => $creadas,
            'actualizadas' => $actualizadas,
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
    private function obtenerIdsEstudiantes($user, string $tipo, int $anioId, array $cursoKeys = []): array
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

        $this->scopeAnio($q, $anioId, $tipo, 'infoestudiantesifas');

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
