<?php

namespace App\Http\Controllers;

use App\Http\Middleware\UpdateTokenExpiration;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class MonitoreoCalificacionesController extends Controller
{
    private const MODOS = [
        'MODO INSTRUMENTOS DE ESPECIALIDAD' => 'planteldocadmins_id',
        'MODO PRÁCTICA DE CONJUNTOS' => 'planteldocadmins_idPC',
        'MODO INSTRUMENTO COMPLEMENTARIO' => 'planteldocadmins_idOtros',
    ];

    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }

    private function validarContexto(Request $request): array
    {
        $data = $request->validate([
            'anio_id' => ['required', 'integer', 'min:1'],
            'institucion_id' => ['required', 'integer', 'min:1'],
        ]);
        if (!empty($request->user()->instituciones_id) && (int) $request->user()->instituciones_id !== (int) $data['institucion_id']) {
            abort(403);
        }
        return $data;
    }

    public function opciones(Request $request)
    {
        $data = $this->validarContexto($request);
        $materias = DB::table('materias as m')
            ->join('plandeestudios as p', 'p.id', '=', 'm.plandeestudios_id')
            ->join('carreras as ca', 'ca.id', '=', 'p.carreras_id')
            ->where('p.anio_id', $data['anio_id'])
            ->where('ca.instituciones_id', $data['institucion_id'])
            ->select('ca.Resolucion', 'p.LvlCurso', 'm.Paralelo', 'm.Turno')
            ->distinct()->get();

        $opciones = function (string $campo) use ($materias) {
            return $materias->map(fn ($row) => trim((string) ($row->$campo ?? '')))
                ->filter(fn ($valor) => $valor !== '')->unique()->sort()->values();
        };
        $docentes = DB::table('planteldocentes')->where('instituciones_id', $data['institucion_id'])
            ->select('id', 'Nombres', 'Apellidos')->orderBy('Apellidos')->orderBy('Nombres')->get();

        return response()->json([
            'resoluciones' => $opciones('Resolucion'),
            'sin_resolucion' => $materias->contains(fn ($row) => trim((string) ($row->Resolucion ?? '')) === ''),
            'cursos' => $opciones('LvlCurso'),
            'paralelos' => $opciones('Paralelo'),
            'turnos' => $opciones('Turno'),
            'docentes' => $docentes,
        ]);
    }

    private function cantidadEvaluaciones($valor): ?int
    {
        $texto = strtoupper(trim((string) ($valor ?? '')));
        if (preg_match('/\b(CERO|UN|UNO|UNA|DOS|TRES|CUATRO)\b/u', $texto, $coincidencia)) {
            return ['CERO' => 0, 'UN' => 1, 'UNO' => 1, 'UNA' => 1, 'DOS' => 2, 'TRES' => 3, 'CUATRO' => 4][$coincidencia[1]];
        }
        if (preg_match('/\d+/', $texto, $coincidencia)) {
            $cantidad = (int) $coincidencia[0];
            return $cantidad <= 4 ? $cantidad : null;
        }
        return null;
    }

    private function aplicarFiltrosAcademicos(Builder $query, array $filtros): void
    {
        foreach (['resolucion' => 'ca.Resolucion', 'curso' => 'p.LvlCurso', 'paralelo' => 'm.Paralelo', 'turno' => 'm.Turno'] as $filtro => $columna) {
            if (!empty($filtros[$filtro])) {
                $valor = $filtro === 'resolucion' && $filtros[$filtro] === '__SIN_RESOLUCION__' ? '' : trim($filtros[$filtro]);
                $query->whereRaw("TRIM(COALESCE($columna, '')) = ?", [$valor]);
            }
        }
        if (!empty($filtros['materia'])) $query->where('p.NombreMateria', 'like', '%' . $filtros['materia'] . '%');
    }

    public function buscar(Request $request)
    {
        $contexto = $this->validarContexto($request);
        $filtros = $request->validate([
            'resolucion' => ['nullable', 'string', 'max:255'],
            'curso' => ['nullable', 'string', 'max:100'],
            'paralelo' => ['nullable', 'string', 'max:100'],
            'turno' => ['nullable', 'string', 'max:100'],
            'materia' => ['nullable', 'string', 'max:100'],
            'estudiante' => ['nullable', 'string', 'max:100'],
            'docente_id' => ['nullable', 'integer', 'min:1'],
            'evaluaciones' => ['nullable', 'array', 'max:4'],
            'evaluaciones.*' => ['integer', 'between:1,4', 'distinct'],
            'incluir_promedios' => ['required', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $evaluaciones = array_map('intval', $filtros['evaluaciones'] ?? []);
        $incluirPromedios = (bool) $filtros['incluir_promedios'];
        if (!$evaluaciones && !$incluirPromedios) {
            return response()->json(['message' => 'Seleccione al menos una evaluación o incluya los promedios.'], 422);
        }

        $consultaCarreras = DB::table('carreras as ca')
            ->join('plandeestudios as p', 'p.carreras_id', '=', 'ca.id')
            ->join('materias as m', 'm.plandeestudios_id', '=', 'p.id')
            ->where('ca.instituciones_id', $contexto['institucion_id'])
            ->where('p.anio_id', $contexto['anio_id'])
            ->select('ca.id', 'ca.NombreCarrera', 'ca.CantidadEvaluaciones')->distinct();
        $this->aplicarFiltrosAcademicos($consultaCarreras, $filtros);
        $carreras = $consultaCarreras->get();
        $sinConfiguracion = $evaluaciones
            ? $carreras->filter(fn ($carrera) => $this->cantidadEvaluaciones($carrera->CantidadEvaluaciones) === null)
                ->map(fn ($carrera) => trim((string) $carrera->NombreCarrera) ?: "Carrera $carrera->id")->values()->all()
            : [];
        $carrerasPorEvaluacion = [];
        foreach ($evaluaciones as $numero) {
            $carrerasPorEvaluacion[$numero] = $carreras
                ->filter(function ($carrera) use ($numero) {
                    $cantidad = $this->cantidadEvaluaciones($carrera->CantidadEvaluaciones);
                    return $cantidad !== null && $cantidad >= $numero;
                })
                ->pluck('id')->all();
        }

        $query = DB::table('calificaciones as cal')
            ->join('infoestudiantesifas as info', 'info.id', '=', 'cal.infoestudiantesifas_id')
            ->join('estudiantesifas as e', 'e.id', '=', 'info.estudiantesifas_id')
            ->join('materias as m', 'm.id', '=', 'cal.materias_id')
            ->join('plandeestudios as p', 'p.id', '=', 'm.plandeestudios_id')
            ->join('carreras as ca', 'ca.id', '=', 'p.carreras_id')
            ->where('p.anio_id', $contexto['anio_id'])
            ->where('ca.instituciones_id', $contexto['institucion_id'])
            ->where('info.instituciones_id', $contexto['institucion_id']);

        $this->aplicarFiltrosAcademicos($query, $filtros);
        if (!empty($filtros['estudiante'])) {
            foreach (preg_split('/\s+/u', trim($filtros['estudiante']), -1, PREG_SPLIT_NO_EMPTY) as $palabra) {
                $texto = '%' . $palabra . '%';
                $query->where(function ($sub) use ($texto) {
                    $sub->where('e.Ap_Paterno', 'like', $texto)->orWhere('e.Ap_Materno', 'like', $texto)
                        ->orWhere('e.Nombre', 'like', $texto)->orWhere('e.CI', 'like', $texto);
                });
            }
        }
        if (!empty($filtros['docente_id'])) {
            $docenteId = $filtros['docente_id'];
            if (!DB::table('planteldocentes')->where('id', $docenteId)->where('instituciones_id', $contexto['institucion_id'])->exists()) {
                abort(404);
            }
            $query->where(function ($sub) use ($docenteId) {
                foreach (self::MODOS as $modo => $campo) {
                    $sub->orWhere(fn ($especial) => $especial->where('p.ModoMateria', $modo)->where("info.$campo", $docenteId));
                }
                $sub->orWhere(function ($normal) use ($docenteId) {
                    $normal->where(fn ($modo) => $modo->whereNotIn('p.ModoMateria', array_keys(self::MODOS))->orWhereNull('p.ModoMateria'))
                        ->whereExists(fn ($asignacion) => $asignacion->selectRaw('1')->from('planteldocentesmaterias as pdm')
                            ->whereColumn('pdm.materias_id', 'm.id')->where('pdm.planteldocentes_id', $docenteId));
                });
            });
        }
        $query->where(function ($vacios) use ($carrerasPorEvaluacion, $incluirPromedios) {
            $vacios->whereRaw('1 = 0');
            foreach ($carrerasPorEvaluacion as $numero => $ids) {
                if (!$ids) continue;
                $vacios->orWhere(function ($evaluacion) use ($numero, $ids) {
                    $evaluacion->whereIn('ca.id', $ids)
                        ->where(fn ($celdas) => $celdas->whereNull("cal.Teorico$numero")->orWhereNull("cal.Practico$numero"));
                });
            }
            if ($incluirPromedios) {
                $vacios->orWhereNull('cal.PromTeorico')->orWhereNull('cal.PromPractico')->orWhereNull('cal.Promedio');
            }
        });

        $pagina = $query->select([
            'cal.id', 'cal.materias_id', 'cal.EstadoRegistroMateria',
            'cal.Teorico1', 'cal.Teorico2', 'cal.Teorico3', 'cal.Teorico4',
            'cal.Practico1', 'cal.Practico2', 'cal.Practico3', 'cal.Practico4',
            'cal.PromTeorico', 'cal.PromPractico', 'cal.Promedio',
            'e.Ap_Paterno', 'e.Ap_Materno', 'e.Nombre', 'e.CI',
            'p.NombreMateria', 'p.ModoMateria', 'p.LvlCurso', 'm.Paralelo', 'm.Turno',
            'ca.Resolucion', 'ca.CantidadEvaluaciones',
            'info.InstrumentoMusical', 'info.InstrumentoMusicalSecundario',
            'info.planteldocadmins_id', 'info.planteldocadmins_idPC', 'info.planteldocadmins_idOtros',
        ])->orderBy('p.RangoLvlCurso')->orderBy('p.LvlCurso')->orderBy('m.Paralelo')
            ->orderBy('p.NombreMateria')->orderBy('e.Ap_Paterno')->orderBy('e.Ap_Materno')->orderBy('e.Nombre')
            ->orderBy('cal.id')->paginate(50, ['*'], 'page', (int) ($filtros['page'] ?? 1));

        $materiasIds = $pagina->getCollection()->pluck('materias_id')->unique()->all();
        $asignaciones = DB::table('planteldocentesmaterias as pdm')
            ->whereIn('pdm.materias_id', $materiasIds)
            ->select('pdm.materias_id', 'pdm.planteldocentes_id')->get();
        $docentesIds = $asignaciones->pluck('planteldocentes_id')
            ->concat($pagina->getCollection()->flatMap(fn ($fila) => [
                $fila->planteldocadmins_id, $fila->planteldocadmins_idPC, $fila->planteldocadmins_idOtros,
            ]))->filter()->unique()->values()->all();
        $docentes = DB::table('planteldocentes')->where('instituciones_id', $contexto['institucion_id'])
            ->whereIn('id', $docentesIds)
            ->select('id', 'Nombres', 'Apellidos')->get()->keyBy('id');
        $asignaciones = $asignaciones->groupBy('materias_id');

        $filas = $pagina->getCollection()->map(function ($fila) use ($evaluaciones, $incluirPromedios, $asignaciones, $docentes) {
            $vacios = [];
            foreach ($evaluaciones as $numero) {
                $cantidad = $this->cantidadEvaluaciones($fila->CantidadEvaluaciones);
                if ($cantidad === null || $numero > $cantidad) continue;
                if ($fila->{"Teorico$numero"} === null) $vacios[] = "Teórico $numero";
                if ($fila->{"Practico$numero"} === null) $vacios[] = "Práctico $numero";
            }
            if ($incluirPromedios) {
                foreach (['PromTeorico' => 'Promedio teórico', 'PromPractico' => 'Promedio práctico', 'Promedio' => 'Promedio final'] as $campo => $label) {
                    if ($fila->$campo === null) $vacios[] = $label;
                }
            }
            $campoDocente = self::MODOS[trim((string) $fila->ModoMateria)] ?? null;
            $ids = $campoDocente
                ? ($fila->$campoDocente ? [(int) $fila->$campoDocente] : [])
                : ($asignaciones->get($fila->materias_id) ?? collect())->pluck('planteldocentes_id')->unique()->all();
            $nombres = array_map(function ($id) use ($docentes) {
                $docente = $docentes->get($id);
                return $docente ? trim("$docente->Apellidos $docente->Nombres") : "Docente no disponible (ID $id)";
            }, $ids);
            return [
                'id' => $fila->id, 'materia' => $fila->NombreMateria, 'modo' => $fila->ModoMateria,
                'curso' => $fila->LvlCurso, 'paralelo' => $fila->Paralelo, 'turno' => $fila->Turno,
                'resolucion' => $fila->Resolucion, 'estado' => $fila->EstadoRegistroMateria,
                'estudiante' => trim(implode(' ', array_filter([$fila->Ap_Paterno, $fila->Ap_Materno, $fila->Nombre]))),
                'ci' => $fila->CI, 'docentes' => $nombres,
                'instrumento' => $campoDocente === 'planteldocadmins_id' ? $fila->InstrumentoMusical
                    : ($campoDocente === 'planteldocadmins_idOtros' ? $fila->InstrumentoMusicalSecundario : null),
                'vacios' => $vacios,
            ];
        });

        return response()->json(['data' => $filas, 'carreras_sin_cantidad' => $sinConfiguracion, 'meta' => [
            'current_page' => $pagina->currentPage(), 'last_page' => $pagina->lastPage(), 'total' => $pagina->total(),
        ]]);
    }
}
