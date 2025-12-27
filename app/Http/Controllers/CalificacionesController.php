<?php

namespace App\Http\Controllers;

use App\Models\calificaciones;
use App\Models\infoestudiantesifas;
use App\Models\materias;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;

class CalificacionesController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }
    //#region Inicio Controller de Crud PHP de calificaciones
    public function index()
    {
        $calificaciones = calificaciones::all();
        return response()->json(['data' => $calificaciones]);
    }

    public function byInfo(Request $request, $infoId)
    {
        $user = $request->user();
        if (!$user || !$user->instituciones_id) {
            abort(404);
        }

        $exists = infoestudiantesifas::query()
            ->where('id', $infoId)
            ->where('instituciones_id', $user->instituciones_id)
            ->exists();

        if (!$exists) {
            abort(404);
        }

        $query = calificaciones::query()
            ->join('materias', 'calificaciones.materias_id', '=', 'materias.id')
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->leftJoin('anios', 'plandeestudios.anio_id', '=', 'anios.id')
            ->where('calificaciones.infoestudiantesifas_id', $infoId)
            ->where('carreras.instituciones_id', $user->instituciones_id)
            ->select([
                'calificaciones.*',
                'materias.Paralelo as MateriaParalelo',
                'plandeestudios.NombreMateria',
                'plandeestudios.SiglaMateria',
                'plandeestudios.LvlCurso',
                'anios.Anio',
                'carreras.CantidadEvaluaciones',
            ])
            ->orderBy('plandeestudios.RangoLvlCurso')
            ->orderBy('plandeestudios.Rango')
            ->orderBy('plandeestudios.NombreMateria');

        $perPage = (int) $request->query('per_page', 200);
        if ($perPage < 1) {
            $perPage = 200;
        }
        if ($perPage > 500) {
            $perPage = 500;
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

    public function bulkUpdate(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->instituciones_id) {
            return response()->json(['message' => 'Usuario sin institución'], 422);
        }

        $validated = $request->validate([
            'infoestudiantesifas_id' => ['required', 'integer'],
            'avg_eval_count' => ['nullable', 'integer', 'min:1', 'max:4'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.id' => ['required', 'integer'],
            'items.*.Teorico1' => ['nullable', 'integer', 'min:0', 'max:100'],
            'items.*.Teorico2' => ['nullable', 'integer', 'min:0', 'max:100'],
            'items.*.Teorico3' => ['nullable', 'integer', 'min:0', 'max:100'],
            'items.*.Teorico4' => ['nullable', 'integer', 'min:0', 'max:100'],
            'items.*.Practico1' => ['nullable', 'integer', 'min:0', 'max:100'],
            'items.*.Practico2' => ['nullable', 'integer', 'min:0', 'max:100'],
            'items.*.Practico3' => ['nullable', 'integer', 'min:0', 'max:100'],
            'items.*.Practico4' => ['nullable', 'integer', 'min:0', 'max:100'],
            'items.*.PruebaRecuperacion' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $infoId = (int) $validated['infoestudiantesifas_id'];
        $avgEvalCount = (int) ($validated['avg_eval_count'] ?? 4);
        if ($avgEvalCount < 1 || $avgEvalCount > 4) {
            $avgEvalCount = 4;
        }

        $info = infoestudiantesifas::query()
            ->where('id', $infoId)
            ->where('instituciones_id', $user->instituciones_id)
            ->first();

        if (!$info) {
            return response()->json(['message' => 'Inscripción no pertenece a la institución'], 403);
        }

        $items = $validated['items'];
        $ids = collect($items)->pluck('id')->map(fn ($x) => (int) $x)->values();

        // Verifica pertenencia institución + que sean de la misma inscripción
        $allowed = calificaciones::query()
            ->join('materias', 'calificaciones.materias_id', '=', 'materias.id')
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->where('calificaciones.infoestudiantesifas_id', $infoId)
            ->where('carreras.instituciones_id', $user->instituciones_id)
            ->whereIn('calificaciones.id', $ids)
            ->select(['calificaciones.*'])
            ->get()
            ->keyBy('id');

        if ($allowed->count() !== $ids->count()) {
            return response()->json(['message' => 'Uno o más registros no son válidos para esta institución/inscripción'], 403);
        }

        $avg = function (array $values) {
            $nums = array_values(array_filter($values, fn ($v) => is_int($v) || is_float($v)));
            if (count($nums) === 0) return null;
            $sum = array_reduce($nums, fn ($acc, $n) => $acc + $n, 0);
            return (int) round($sum / count($nums));
        };

        DB::beginTransaction();
        try {
            $updated = 0;

            foreach ($items as $payload) {
                $rowId = (int) $payload['id'];
                /** @var calificaciones $row */
                $row = $allowed->get($rowId);
                if (!$row) {
                    continue;
                }

                // Asigna campos editables
                foreach (['Teorico1','Teorico2','Teorico3','Teorico4','Practico1','Practico2','Practico3','Practico4','PruebaRecuperacion'] as $k) {
                    if (array_key_exists($k, $payload)) {
                        $row->$k = $payload[$k];
                    }
                }

                // Recalcula promedios (misma lógica base del front)
                $evals = range(1, $avgEvalCount);
                $zeroEval = false;
                foreach ($evals as $n) {
                    $t = $row->{'Teorico'.$n};
                    $p = $row->{'Practico'.$n};
                    if ($t !== null && $p !== null && (int)$t === 0 && (int)$p === 0) {
                        $zeroEval = true;
                        break;
                    }
                }

                if ($zeroEval) {
                    $row->PromTeorico = 0;
                    $row->PromPractico = 0;
                    $row->Promedio = 0;
                } else {
                    $teos = [];
                    $pracs = [];
                    foreach ($evals as $n) {
                        $teos[] = $row->{'Teorico'.$n};
                        $pracs[] = $row->{'Practico'.$n};
                    }

                    $row->PromTeorico = $avg($teos);
                    $row->PromPractico = $avg($pracs);

                    if ($row->PromTeorico === null && $row->PromPractico === null) {
                        $row->Promedio = null;
                    } else {
                        $sum = ((int) ($row->PromTeorico ?? 0)) + ((int) ($row->PromPractico ?? 0));
                        if ($sum < 0) $sum = 0;
                        if ($sum > 100) $sum = 100;
                        $row->Promedio = (int) round($sum);
                    }
                }

                $row->save();
                $updated++;
            }

            DB::commit();
            return response()->json(['data' => ['updated' => $updated]]);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function byMateria(Request $request, $materiaId)
    {
        $user = $request->user();
        if (!$user || !$user->instituciones_id) {
            abort(404);
        }

        $materiaId = (int) $materiaId;
        if ($materiaId <= 0) {
            abort(404);
        }

        $materia = materias::query()
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->leftJoin('anios', 'plandeestudios.anio_id', '=', 'anios.id')
            ->where('materias.id', $materiaId)
            ->where('carreras.instituciones_id', $user->instituciones_id)
            ->select([
                'materias.id',
                'materias.Paralelo as MateriaParalelo',
                'plandeestudios.NombreMateria',
                'plandeestudios.SiglaMateria',
                'plandeestudios.LvlCurso',
                'anios.Anio',
                'carreras.CantidadEvaluaciones',
            ])
            ->first();

        if (!$materia) {
            abort(404);
        }

        $docentes = DB::table('planteldocentesmaterias')
            ->join('planteldocentes', 'planteldocentesmaterias.planteldocentes_id', '=', 'planteldocentes.id')
            ->where('planteldocentesmaterias.materias_id', $materiaId)
            ->where('planteldocentes.instituciones_id', $user->instituciones_id)
            ->select([
                'planteldocentes.id',
                'planteldocentes.Nombres',
                'planteldocentes.Apellidos',
            ])
            ->orderByRaw("(planteldocentes.Apellidos IS NULL) DESC")
            ->orderBy('planteldocentes.Apellidos')
            ->orderBy('planteldocentes.Nombres')
            ->get();

        $query = calificaciones::query()
            ->join('infoestudiantesifas', 'calificaciones.infoestudiantesifas_id', '=', 'infoestudiantesifas.id')
            ->join('estudiantesifas', 'infoestudiantesifas.estudiantesifas_id', '=', 'estudiantesifas.id')
            ->join('materias', 'calificaciones.materias_id', '=', 'materias.id')
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->leftJoin('anios', 'plandeestudios.anio_id', '=', 'anios.id')
            ->where('calificaciones.materias_id', $materiaId)
            ->where('carreras.instituciones_id', $user->instituciones_id)
            ->where('infoestudiantesifas.instituciones_id', $user->instituciones_id)
            ->select([
                'calificaciones.*',
                'estudiantesifas.Ap_Paterno',
                'estudiantesifas.Ap_Materno',
                'estudiantesifas.Nombre as Nombres',
                'estudiantesifas.CI',
                'materias.Paralelo as MateriaParalelo',
                'plandeestudios.NombreMateria',
                'plandeestudios.SiglaMateria',
                'plandeestudios.LvlCurso',
                'anios.Anio',
                'carreras.CantidadEvaluaciones',
            ])
            // Orden requerido: Ap_Paterno, Ap_Materno, Nombres; NULLs primero en Ap_Paterno
            ->orderByRaw('(estudiantesifas.Ap_Paterno IS NULL) DESC')
            ->orderBy('estudiantesifas.Ap_Paterno')
            ->orderByRaw('(estudiantesifas.Ap_Materno IS NULL) DESC')
            ->orderBy('estudiantesifas.Ap_Materno')
            ->orderBy('estudiantesifas.Nombre')
            ->orderBy('calificaciones.id');

        $perPage = (int) $request->query('per_page', 200);
        if ($perPage < 1) {
            $perPage = 200;
        }
        if ($perPage > 500) {
            $perPage = 500;
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'materia' => $materia,
            'docentes' => $docentes,
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

    public function bulkUpdateMateria(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->instituciones_id) {
            return response()->json(['message' => 'Usuario sin institución'], 422);
        }

        $validated = $request->validate([
            'materias_id' => ['required', 'integer'],
            'avg_eval_count' => ['nullable', 'integer', 'min:1', 'max:4'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.id' => ['required', 'integer'],
            'items.*.Teorico1' => ['nullable', 'integer', 'min:0', 'max:100'],
            'items.*.Teorico2' => ['nullable', 'integer', 'min:0', 'max:100'],
            'items.*.Teorico3' => ['nullable', 'integer', 'min:0', 'max:100'],
            'items.*.Teorico4' => ['nullable', 'integer', 'min:0', 'max:100'],
            'items.*.Practico1' => ['nullable', 'integer', 'min:0', 'max:100'],
            'items.*.Practico2' => ['nullable', 'integer', 'min:0', 'max:100'],
            'items.*.Practico3' => ['nullable', 'integer', 'min:0', 'max:100'],
            'items.*.Practico4' => ['nullable', 'integer', 'min:0', 'max:100'],
            'items.*.PruebaRecuperacion' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $materiaId = (int) $validated['materias_id'];
        $avgEvalCount = (int) ($validated['avg_eval_count'] ?? 4);
        if ($avgEvalCount < 1 || $avgEvalCount > 4) {
            $avgEvalCount = 4;
        }

        // Validar materia pertenece a institución
        $materiaOk = materias::query()
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->where('materias.id', $materiaId)
            ->where('carreras.instituciones_id', $user->instituciones_id)
            ->exists();
        if (!$materiaOk) {
            return response()->json(['message' => 'Materia no pertenece a la institución'], 403);
        }

        $items = $validated['items'];
        $ids = collect($items)->pluck('id')->map(fn ($x) => (int) $x)->values();

        $allowed = calificaciones::query()
            ->join('materias', 'calificaciones.materias_id', '=', 'materias.id')
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->where('calificaciones.materias_id', $materiaId)
            ->where('carreras.instituciones_id', $user->instituciones_id)
            ->whereIn('calificaciones.id', $ids)
            ->select(['calificaciones.*'])
            ->get()
            ->keyBy('id');

        if ($allowed->count() !== $ids->count()) {
            return response()->json(['message' => 'Uno o más registros no son válidos para esta institución/materia'], 403);
        }

        $avg = function (array $values) {
            $nums = array_values(array_filter($values, fn ($v) => is_int($v) || is_float($v)));
            if (count($nums) === 0) return null;
            $sum = array_reduce($nums, fn ($acc, $n) => $acc + $n, 0);
            return (int) round($sum / count($nums));
        };

        DB::beginTransaction();
        try {
            $updated = 0;
            foreach ($items as $payload) {
                $rowId = (int) $payload['id'];
                /** @var calificaciones $row */
                $row = $allowed->get($rowId);
                if (!$row) continue;

                foreach (['Teorico1','Teorico2','Teorico3','Teorico4','Practico1','Practico2','Practico3','Practico4','PruebaRecuperacion'] as $k) {
                    if (array_key_exists($k, $payload)) {
                        $row->$k = $payload[$k];
                    }
                }

                $evals = range(1, $avgEvalCount);
                $zeroEval = false;
                foreach ($evals as $n) {
                    $t = $row->{'Teorico'.$n};
                    $p = $row->{'Practico'.$n};
                    if ($t !== null && $p !== null && (int)$t === 0 && (int)$p === 0) {
                        $zeroEval = true;
                        break;
                    }
                }

                if ($zeroEval) {
                    $row->PromTeorico = 0;
                    $row->PromPractico = 0;
                    $row->Promedio = 0;
                } else {
                    $teos = [];
                    $pracs = [];
                    foreach ($evals as $n) {
                        $teos[] = $row->{'Teorico'.$n};
                        $pracs[] = $row->{'Practico'.$n};
                    }
                    $row->PromTeorico = $avg($teos);
                    $row->PromPractico = $avg($pracs);

                    if ($row->PromTeorico === null && $row->PromPractico === null) {
                        $row->Promedio = null;
                    } else {
                        $sum = ((int) ($row->PromTeorico ?? 0)) + ((int) ($row->PromPractico ?? 0));
                        if ($sum < 0) $sum = 0;
                        if ($sum > 100) $sum = 100;
                        $row->Promedio = (int) round($sum);
                    }
                }

                $row->save();
                $updated++;
            }

            DB::commit();
            return response()->json(['data' => ['updated' => $updated]]);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
    
    
    public function store(Request $request)
    {
        $calificaciones = $request->all();
        calificaciones::insert($calificaciones);
        return response()->json(['data' => $calificaciones]);
    }
    
    public function show($id)
    {
        $calificaciones = calificaciones::where('id','=',$id)->firstOrFail();
        return response()->json(['data' => $calificaciones]);
    }
    
    
    public function update(Request $request)
    {
        $calificaciones = $request->all();
        calificaciones::where('id','=',$request->id)->update($calificaciones);
        return response()->json(['data' => $calificaciones]);
    }

    public function assign(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->instituciones_id) {
            return response()->json(['message' => 'Usuario sin institución'], 422);
        }

        $validated = $request->validate([
            'infoestudiantesifas_id' => ['required', 'integer'],
            'materias_id' => ['required', 'integer'],
            'EstadoRegistroMateria' => ['nullable', 'string', 'max:50'],
            'forzar' => ['sometimes', 'boolean'],
        ]);

        $forzar = (bool) ($validated['forzar'] ?? false);

        $info = infoestudiantesifas::query()
            ->where('id', $validated['infoestudiantesifas_id'])
            ->where('instituciones_id', $user->instituciones_id)
            ->first();

        if (!$info) {
            return response()->json(['message' => 'Inscripción no pertenece a la institución'], 403);
        }

        $materiaRow = materias::query()
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->where('materias.id', $validated['materias_id'])
            ->where('carreras.instituciones_id', $user->instituciones_id)
            ->select([
                'materias.id',
                'materias.Paralelo',
                'plandeestudios.LvlCurso',
            ])
            ->first();

        if (!$materiaRow) {
            return response()->json(['message' => 'Materia no pertenece a la institución'], 403);
        }

        if (!$forzar) {
            if (!empty($info->Curso_Solicitado) && !empty($materiaRow->LvlCurso) && $info->Curso_Solicitado !== $materiaRow->LvlCurso) {
                return response()->json(['message' => 'La materia no corresponde al curso solicitado'], 422);
            }

            if (!empty($info->Paralelo_Solicitado) && !empty($materiaRow->Paralelo) && $info->Paralelo_Solicitado !== $materiaRow->Paralelo) {
                return response()->json(['message' => 'La materia no corresponde al paralelo solicitado'], 422);
            }
        }

        DB::beginTransaction();
        try {
            $assignment = calificaciones::query()->firstOrCreate(
                [
                    'infoestudiantesifas_id' => $validated['infoestudiantesifas_id'],
                    'materias_id' => $validated['materias_id'],
                ],
                [
                    'EstadoRegistroMateria' => $validated['EstadoRegistroMateria'] ?? null,
                ]
            );

            $count = calificaciones::query()
                ->where('infoestudiantesifas_id', $validated['infoestudiantesifas_id'])
                ->count();

            $info->CantidadMateriasAsignadas = $count;
            $info->save();

            DB::commit();
            return response()->json(['data' => $assignment]);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function unassign(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->instituciones_id) {
            return response()->json(['message' => 'Usuario sin institución'], 422);
        }

        $validated = $request->validate([
            'infoestudiantesifas_id' => ['required', 'integer'],
            'materias_id' => ['required', 'integer'],
        ]);

        $info = infoestudiantesifas::query()
            ->where('id', $validated['infoestudiantesifas_id'])
            ->where('instituciones_id', $user->instituciones_id)
            ->first();

        if (!$info) {
            return response()->json(['message' => 'Inscripción no pertenece a la institución'], 403);
        }

        DB::beginTransaction();
        try {
            $deleted = calificaciones::query()
                ->where('infoestudiantesifas_id', $validated['infoestudiantesifas_id'])
                ->where('materias_id', $validated['materias_id'])
                ->delete();

            $count = calificaciones::query()
                ->where('infoestudiantesifas_id', $validated['infoestudiantesifas_id'])
                ->count();

            $info->CantidadMateriasAsignadas = $count;
            $info->save();

            DB::commit();

            return response()->json(['data' => ['deleted' => $deleted]]);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function assignBulkCurso(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->instituciones_id) {
            return response()->json(['message' => 'Usuario sin institución'], 422);
        }

        $validated = $request->validate([
            'infoestudiantesifas_id' => ['required', 'integer'],
            'curso' => ['nullable', 'string', 'max:60'],
            'forzar' => ['sometimes', 'boolean'],
            'all_paralelos' => ['sometimes', 'boolean'],
            'EstadoRegistroMateria' => ['nullable', 'string', 'max:50'],
        ]);

        $info = infoestudiantesifas::query()
            ->where('id', $validated['infoestudiantesifas_id'])
            ->where('instituciones_id', $user->instituciones_id)
            ->first();

        if (!$info) {
            return response()->json(['message' => 'Inscripción no pertenece a la institución'], 403);
        }

        $curso = trim((string) ($validated['curso'] ?? $info->Curso_Solicitado ?? ''));
        if ($curso === '') {
            return response()->json(['message' => 'Curso no definido para asignación masiva'], 422);
        }

        $allParalelos = (bool) ($validated['all_paralelos'] ?? false);

        $materiasQuery = materias::query()
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->where('carreras.instituciones_id', $user->instituciones_id)
            ->where('plandeestudios.LvlCurso', $curso)
            ->select(['materias.id', 'materias.Paralelo']);

        if (!$allParalelos && !empty($info->Paralelo_Solicitado)) {
            $materiasQuery->where('materias.Paralelo', $info->Paralelo_Solicitado);
        }

        $materiasIds = $materiasQuery->pluck('materias.id')->values();

        if ($materiasIds->count() === 0) {
            return response()->json(['data' => ['created' => 0, 'total_materias' => 0]]);
        }

        $existing = calificaciones::query()
            ->where('infoestudiantesifas_id', $validated['infoestudiantesifas_id'])
            ->whereIn('materias_id', $materiasIds)
            ->pluck('materias_id')
            ->map(fn($v) => (int) $v)
            ->all();
        $existingSet = array_flip($existing);

        $created = 0;

        DB::beginTransaction();
        try {
            foreach ($materiasIds as $mid) {
                $mid = (int) $mid;
                if (isset($existingSet[$mid])) {
                    continue;
                }

                calificaciones::create([
                    'infoestudiantesifas_id' => (int) $validated['infoestudiantesifas_id'],
                    'materias_id' => $mid,
                    'EstadoRegistroMateria' => $validated['EstadoRegistroMateria'] ?? null,
                ]);
                $created++;
            }

            $count = calificaciones::query()
                ->where('infoestudiantesifas_id', $validated['infoestudiantesifas_id'])
                ->count();

            $info->CantidadMateriasAsignadas = $count;
            $info->save();

            DB::commit();
            return response()->json(['data' => ['created' => $created, 'total_materias' => $materiasIds->count(), 'total_asignadas' => $count]]);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function assignBulkCategoria(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->instituciones_id) {
            return response()->json(['message' => 'Usuario sin institución'], 422);
        }

        $validated = $request->validate([
            'infoestudiantesifas_id' => ['required', 'integer'],
            'curso' => ['required', 'string', 'max:60'],
            'paralelo' => ['nullable', 'string', 'max:20'],
            'forzar' => ['sometimes', 'boolean'],
            'EstadoRegistroMateria' => ['nullable', 'string', 'max:50'],
        ]);

        $forzar = (bool) ($validated['forzar'] ?? false);

        $info = infoestudiantesifas::query()
            ->where('id', $validated['infoestudiantesifas_id'])
            ->where('instituciones_id', $user->instituciones_id)
            ->first();

        if (!$info) {
            return response()->json(['message' => 'Inscripción no pertenece a la institución'], 403);
        }

        $curso = trim((string) ($validated['curso'] ?? ''));
        if ($curso === '') {
            return response()->json(['message' => 'Curso no definido para asignación masiva'], 422);
        }

        $paralelo = trim((string) ($validated['paralelo'] ?? ''));

        $materiasQuery = materias::query()
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->where('carreras.instituciones_id', $user->instituciones_id)
            ->where('plandeestudios.LvlCurso', $curso)
            ->select(['materias.id', 'materias.Paralelo']);

        if ($paralelo !== '') {
            $materiasQuery->where('materias.Paralelo', $paralelo);
        } elseif (!$forzar && !empty($info->Paralelo_Solicitado)) {
            $materiasQuery->where('materias.Paralelo', $info->Paralelo_Solicitado);
        }

        $materiasIds = $materiasQuery->pluck('materias.id')->values();

        if ($materiasIds->count() === 0) {
            return response()->json(['data' => ['created' => 0, 'total_materias' => 0]]);
        }

        $existing = calificaciones::query()
            ->where('infoestudiantesifas_id', $validated['infoestudiantesifas_id'])
            ->whereIn('materias_id', $materiasIds)
            ->pluck('materias_id')
            ->map(fn($v) => (int) $v)
            ->all();
        $existingSet = array_flip($existing);

        $rowsToInsert = [];
        foreach ($materiasIds as $mid) {
            $mid = (int) $mid;
            if (isset($existingSet[$mid])) {
                continue;
            }
            $rowsToInsert[] = [
                'infoestudiantesifas_id' => (int) $validated['infoestudiantesifas_id'],
                'materias_id' => $mid,
                'EstadoRegistroMateria' => $validated['EstadoRegistroMateria'] ?? null,
            ];
        }

        DB::beginTransaction();
        try {
            $created = 0;
            if (count($rowsToInsert) > 0) {
                // Insert masivo; evita N requests desde el frontend.
                DB::table('calificaciones')->insert($rowsToInsert);
                $created = count($rowsToInsert);
            }

            $count = calificaciones::query()
                ->where('infoestudiantesifas_id', $validated['infoestudiantesifas_id'])
                ->count();

            $info->CantidadMateriasAsignadas = $count;
            $info->save();

            DB::commit();
            return response()->json([
                'data' => [
                    'created' => $created,
                    'total_materias' => $materiasIds->count(),
                    'total_asignadas' => $count,
                ],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function unassignBulkCategoria(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->instituciones_id) {
            return response()->json(['message' => 'Usuario sin institución'], 422);
        }

        $validated = $request->validate([
            'infoestudiantesifas_id' => ['required', 'integer'],
            'curso' => ['required', 'string', 'max:60'],
            'paralelo' => ['nullable', 'string', 'max:20'],
            'forzar' => ['sometimes', 'boolean'],
        ]);

        $forzar = (bool) ($validated['forzar'] ?? false);

        $info = infoestudiantesifas::query()
            ->where('id', $validated['infoestudiantesifas_id'])
            ->where('instituciones_id', $user->instituciones_id)
            ->first();

        if (!$info) {
            return response()->json(['message' => 'Inscripción no pertenece a la institución'], 403);
        }

        $curso = trim((string) ($validated['curso'] ?? ''));
        if ($curso === '') {
            return response()->json(['message' => 'Curso no definido para desasignación masiva'], 422);
        }

        $paralelo = trim((string) ($validated['paralelo'] ?? ''));

        $materiasQuery = materias::query()
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->where('carreras.instituciones_id', $user->instituciones_id)
            ->where('plandeestudios.LvlCurso', $curso)
            ->select(['materias.id', 'materias.Paralelo']);

        if ($paralelo !== '') {
            $materiasQuery->where('materias.Paralelo', $paralelo);
        } elseif (!$forzar && !empty($info->Paralelo_Solicitado)) {
            $materiasQuery->where('materias.Paralelo', $info->Paralelo_Solicitado);
        }

        $materiasIds = $materiasQuery->pluck('materias.id')->values();

        if ($materiasIds->count() === 0) {
            return response()->json(['data' => ['deleted' => 0, 'total_materias' => 0]]);
        }

        DB::beginTransaction();
        try {
            $deleted = calificaciones::query()
                ->where('infoestudiantesifas_id', $validated['infoestudiantesifas_id'])
                ->whereIn('materias_id', $materiasIds)
                ->delete();

            $count = calificaciones::query()
                ->where('infoestudiantesifas_id', $validated['infoestudiantesifas_id'])
                ->count();

            $info->CantidadMateriasAsignadas = $count;
            $info->save();

            DB::commit();
            return response()->json([
                'data' => [
                    'deleted' => $deleted,
                    'total_materias' => $materiasIds->count(),
                    'total_asignadas' => $count,
                ],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function assignBulkAnioResolucion(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->instituciones_id) {
            return response()->json(['message' => 'Usuario sin institución'], 422);
        }

        $validated = $request->validate([
            'infoestudiantesifas_id' => ['required', 'integer'],
            'anio_id' => ['required', 'integer'],
            'resolucion' => ['required', 'string', 'max:50'],
            'EstadoRegistroMateria' => ['nullable', 'string', 'max:50'],
        ]);

        $info = infoestudiantesifas::query()
            ->where('id', $validated['infoestudiantesifas_id'])
            ->where('instituciones_id', $user->instituciones_id)
            ->first();

        if (!$info) {
            return response()->json(['message' => 'Inscripción no pertenece a la institución'], 403);
        }

        $anioId = (int) $validated['anio_id'];
        $resolucion = trim((string) ($validated['resolucion'] ?? ''));
        if ($anioId <= 0 || $resolucion === '') {
            return response()->json(['message' => 'Año o Resolución no definidos para asignación masiva'], 422);
        }

        $materiasIds = materias::query()
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->where('carreras.instituciones_id', $user->instituciones_id)
            ->where('plandeestudios.anio_id', $anioId)
            ->where('carreras.Resolucion', $resolucion)
            ->pluck('materias.id')
            ->values();

        if ($materiasIds->count() === 0) {
            return response()->json(['data' => ['created' => 0, 'total_materias' => 0]]);
        }

        $existing = calificaciones::query()
            ->where('infoestudiantesifas_id', $validated['infoestudiantesifas_id'])
            ->whereIn('materias_id', $materiasIds)
            ->pluck('materias_id')
            ->map(fn($v) => (int) $v)
            ->all();
        $existingSet = array_flip($existing);

        $rowsToInsert = [];
        foreach ($materiasIds as $mid) {
            $mid = (int) $mid;
            if (isset($existingSet[$mid])) {
                continue;
            }
            $rowsToInsert[] = [
                'infoestudiantesifas_id' => (int) $validated['infoestudiantesifas_id'],
                'materias_id' => $mid,
                'EstadoRegistroMateria' => $validated['EstadoRegistroMateria'] ?? null,
            ];
        }

        DB::beginTransaction();
        try {
            $created = 0;
            if (count($rowsToInsert) > 0) {
                DB::table('calificaciones')->insert($rowsToInsert);
                $created = count($rowsToInsert);
            }

            $count = calificaciones::query()
                ->where('infoestudiantesifas_id', $validated['infoestudiantesifas_id'])
                ->count();

            $info->CantidadMateriasAsignadas = $count;
            $info->save();

            DB::commit();
            return response()->json([
                'data' => [
                    'created' => $created,
                    'total_materias' => $materiasIds->count(),
                    'total_asignadas' => $count,
                ],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function unassignBulkAnioResolucion(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->instituciones_id) {
            return response()->json(['message' => 'Usuario sin institución'], 422);
        }

        $validated = $request->validate([
            'infoestudiantesifas_id' => ['required', 'integer'],
            'anio_id' => ['required', 'integer'],
            'resolucion' => ['required', 'string', 'max:50'],
        ]);

        $info = infoestudiantesifas::query()
            ->where('id', $validated['infoestudiantesifas_id'])
            ->where('instituciones_id', $user->instituciones_id)
            ->first();

        if (!$info) {
            return response()->json(['message' => 'Inscripción no pertenece a la institución'], 403);
        }

        $anioId = (int) $validated['anio_id'];
        $resolucion = trim((string) ($validated['resolucion'] ?? ''));
        if ($anioId <= 0 || $resolucion === '') {
            return response()->json(['message' => 'Año o Resolución no definidos para desasignación masiva'], 422);
        }

        $materiasIds = materias::query()
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->where('carreras.instituciones_id', $user->instituciones_id)
            ->where('plandeestudios.anio_id', $anioId)
            ->where('carreras.Resolucion', $resolucion)
            ->pluck('materias.id')
            ->values();

        if ($materiasIds->count() === 0) {
            return response()->json(['data' => ['deleted' => 0, 'total_materias' => 0]]);
        }

        DB::beginTransaction();
        try {
            $deleted = calificaciones::query()
                ->where('infoestudiantesifas_id', $validated['infoestudiantesifas_id'])
                ->whereIn('materias_id', $materiasIds)
                ->delete();

            $count = calificaciones::query()
                ->where('infoestudiantesifas_id', $validated['infoestudiantesifas_id'])
                ->count();

            $info->CantidadMateriasAsignadas = $count;
            $info->save();

            DB::commit();
            return response()->json([
                'data' => [
                    'deleted' => $deleted,
                    'total_materias' => $materiasIds->count(),
                    'total_asignadas' => $count,
                ],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function unassignAll(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->instituciones_id) {
            return response()->json(['message' => 'Usuario sin institución'], 422);
        }

        $validated = $request->validate([
            'infoestudiantesifas_id' => ['required', 'integer'],
        ]);

        $info = infoestudiantesifas::query()
            ->where('id', $validated['infoestudiantesifas_id'])
            ->where('instituciones_id', $user->instituciones_id)
            ->first();

        if (!$info) {
            return response()->json(['message' => 'Inscripción no pertenece a la institución'], 403);
        }

        DB::beginTransaction();
        try {
            $deleted = calificaciones::query()
                ->where('infoestudiantesifas_id', $validated['infoestudiantesifas_id'])
                ->delete();

            $info->CantidadMateriasAsignadas = 0;
            $info->save();

            DB::commit();
            return response()->json(['data' => ['deleted' => $deleted]]);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
    
    public function destroy($id)
    {
        calificaciones::destroy($id);
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
    //#endregion Fin Controller de Crud PHP de calificaciones
}
