<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;

class RegistrocalificacionesController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }

    private function ensureMateriaInInstitucion(Request $request, int $materiaId): array
    {
        $user = $request->user();
        if (!$user || !$user->instituciones_id) {
            abort(404);
        }

        $materia = DB::table('materias')
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

        $avgEvalCount = (int) ($materia->CantidadEvaluaciones ?? 4);
        if ($avgEvalCount < 1 || $avgEvalCount > 4) $avgEvalCount = 4;

        return [$user, $materia, $avgEvalCount];
    }

    private function ensureEvaluacion(int $materiaId, int $numeroEval): object
    {
        $numeroEval = max(1, min(4, $numeroEval));

        $eval = DB::table('evaluaciones_materia')
            ->where('materias_id', $materiaId)
            ->where('numero_eval', $numeroEval)
            ->first();

        if ($eval) return $eval;

        $id = DB::table('evaluaciones_materia')->insertGetId([
            'materias_id' => $materiaId,
            'numero_eval' => $numeroEval,
            'nombre' => 'Evaluación ' . $numeroEval,
            'limite_teorico' => 30,
            'limite_practico' => 70,
            'habilitada' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('evaluaciones_materia')->where('id', $id)->first();
    }

    private function ensureRubroInInstitucion(Request $request, int $rubroId): object
    {
        $user = $request->user();
        if (!$user || !$user->instituciones_id) {
            abort(404);
        }

        $rubro = DB::table('rubros_evaluacion as r')
            ->join('evaluaciones_materia as e', 'e.id', '=', 'r.evaluacion_id')
            ->join('materias', 'materias.id', '=', 'e.materias_id')
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->where('r.id', $rubroId)
            ->where('carreras.instituciones_id', $user->instituciones_id)
            ->select([
                'r.*',
                'e.materias_id',
                'e.numero_eval',
                'e.limite_teorico',
                'e.limite_practico',
            ])
            ->first();

        if (!$rubro) abort(404);
        return $rubro;
    }

    public function index(Request $request, int $materiaId)
    {
        [$user, $materia, $avgEvalCountFromMateria] = $this->ensureMateriaInInstitucion($request, $materiaId);

        $numeroEval = (int) $request->query('eval', 1);
        if ($numeroEval < 1 || $numeroEval > 4) $numeroEval = 1;

        $perPage = (int) $request->query('per_page', 50);
        if ($perPage < 1) $perPage = 50;
        if ($perPage > 200) $perPage = 200;

        $eval = $this->ensureEvaluacion($materiaId, $numeroEval);

        $rubros = DB::table('rubros_evaluacion')
            ->where('evaluacion_id', $eval->id)
            ->where('habilitado', 1)
            ->orderBy('tipo')
            ->orderBy('orden')
            ->orderBy('id')
            ->get();

        $query = DB::table('calificaciones')
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
            ->orderByRaw('(estudiantesifas.Ap_Paterno IS NULL) DESC')
            ->orderBy('estudiantesifas.Ap_Paterno')
            ->orderByRaw('(estudiantesifas.Ap_Materno IS NULL) DESC')
            ->orderBy('estudiantesifas.Ap_Materno')
            ->orderBy('estudiantesifas.Nombre')
            ->orderBy('calificaciones.id');

        $paginator = $query->paginate($perPage);
        $items = $paginator->items();

        $infoIds = collect($items)->pluck('infoestudiantesifas_id')->values();
        $rubroIds = $rubros->pluck('id')->values();

        $notas = [];
        if ($infoIds->count() && $rubroIds->count()) {
            $notas = DB::table('notas_rubro')
                ->whereIn('infoestudiantesifas_id', $infoIds)
                ->whereIn('rubro_id', $rubroIds)
                ->select(['rubro_id', 'infoestudiantesifas_id', 'nota'])
                ->get();
        }

        return response()->json([
            'materia' => $materia,
            'evaluacion' => $eval,
            'rubros' => $rubros,
            'data' => $items,
            'notas' => $notas,
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

    public function storeRubro(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->instituciones_id) {
            return response()->json(['message' => 'Usuario sin institución'], 422);
        }

        $validated = $request->validate([
            'materias_id' => ['required', 'integer'],
            'numero_eval' => ['required', 'integer', 'min:1', 'max:4'],
            'tipo' => ['required', 'in:TEO,PRA'],
            'nombre' => ['required', 'string', 'max:150'],
            'max_puntos' => ['required', 'integer', 'min:1', 'max:1000'],
            'orden' => ['nullable', 'integer', 'min:1', 'max:9999'],
        ]);

        $materiaId = (int) $validated['materias_id'];
        // valida pertenencia
        $this->ensureMateriaInInstitucion($request, $materiaId);

        $eval = $this->ensureEvaluacion($materiaId, (int) $validated['numero_eval']);

        $id = DB::table('rubros_evaluacion')->insertGetId([
            'evaluacion_id' => $eval->id,
            'tipo' => $validated['tipo'],
            'nombre' => $validated['nombre'],
            'max_puntos' => (int) $validated['max_puntos'],
            'orden' => (int) ($validated['orden'] ?? 1),
            'habilitado' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'rubro' => DB::table('rubros_evaluacion')->where('id', $id)->first(),
            'evaluacion' => $eval,
        ], 201);
    }

    public function updateRubro(Request $request, int $rubroId)
    {
        $this->ensureRubroInInstitucion($request, $rubroId);

        $validated = $request->validate([
            'tipo' => ['required', 'in:TEO,PRA'],
            'nombre' => ['required', 'string', 'max:150'],
            'max_puntos' => ['required', 'integer', 'min:1', 'max:1000'],
            'orden' => ['required', 'integer', 'min:1', 'max:9999'],
        ]);

        DB::table('rubros_evaluacion')
            ->where('id', $rubroId)
            ->update([
                'tipo' => $validated['tipo'],
                'nombre' => $validated['nombre'],
                'max_puntos' => (int) $validated['max_puntos'],
                'orden' => (int) $validated['orden'],
                'updated_at' => now(),
            ]);

        return response()->json([
            'rubro' => DB::table('rubros_evaluacion')->where('id', $rubroId)->first(),
        ]);
    }

    public function deleteRubro(Request $request, int $rubroId)
    {
        $this->ensureRubroInInstitucion($request, $rubroId);

        DB::table('rubros_evaluacion')
            ->where('id', $rubroId)
            ->update([
                'habilitado' => 0,
                'updated_at' => now(),
            ]);

        return response()->json(['ok' => true]);
    }

    private function avg(array $values)
    {
        $nums = array_values(array_filter($values, fn ($v) => is_int($v) || is_float($v)));
        if (count($nums) === 0) return null;
        $sum = array_reduce($nums, fn ($acc, $n) => $acc + $n, 0);
        return (int) round($sum / count($nums));
    }

    private function recalcPromedios(array $row, int $avgEvalCount): array
    {
        $avgEvalCount = max(1, min(4, $avgEvalCount));
        $evals = range(1, $avgEvalCount);

        $zeroEval = false;
        foreach ($evals as $n) {
            $t = $row['Teorico' . $n] ?? null;
            $p = $row['Practico' . $n] ?? null;
            if ($t !== null && $p !== null && (int) $t === 0 && (int) $p === 0) {
                $zeroEval = true;
                break;
            }
        }

        if ($zeroEval) {
            $row['PromTeorico'] = 0;
            $row['PromPractico'] = 0;
            $row['Promedio'] = 0;
            return $row;
        }

        $teos = [];
        $pracs = [];
        foreach ($evals as $n) {
            $teos[] = $row['Teorico' . $n] ?? null;
            $pracs[] = $row['Practico' . $n] ?? null;
        }

        $row['PromTeorico'] = $this->avg($teos);
        $row['PromPractico'] = $this->avg($pracs);

        if ($row['PromTeorico'] === null && $row['PromPractico'] === null) {
            $row['Promedio'] = null;
        } else {
            $sum = ((int) ($row['PromTeorico'] ?? 0)) + ((int) ($row['PromPractico'] ?? 0));
            if ($sum < 0) $sum = 0;
            if ($sum > 100) $sum = 100;
            $row['Promedio'] = (int) round($sum);
        }

        return $row;
    }

    public function bulkSave(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->instituciones_id) {
            return response()->json(['message' => 'Usuario sin institución'], 422);
        }

        $validated = $request->validate([
            'materias_id' => ['required', 'integer'],
            'numero_eval' => ['required', 'integer', 'min:1', 'max:4'],
            'avg_eval_count' => ['nullable', 'integer', 'min:1', 'max:4'],
            'items' => ['required', 'array', 'min:1', 'max:5000'],
            'items.*.infoestudiantesifas_id' => ['required', 'integer'],
            'items.*.rubro_id' => ['required', 'integer'],
            'items.*.nota' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);

        $materiaId = (int) $validated['materias_id'];
        $numeroEval = (int) $validated['numero_eval'];

        [, $materia, $avgEvalCountFromMateria] = $this->ensureMateriaInInstitucion($request, $materiaId);
        $avgEvalCount = (int) ($validated['avg_eval_count'] ?? $avgEvalCountFromMateria);
        if ($avgEvalCount < 1 || $avgEvalCount > 4) $avgEvalCount = $avgEvalCountFromMateria;

        $eval = $this->ensureEvaluacion($materiaId, $numeroEval);

        $rubros = DB::table('rubros_evaluacion')
            ->where('evaluacion_id', $eval->id)
            ->where('habilitado', 1)
            ->get()
            ->keyBy('id');

        $items = $validated['items'];

        DB::beginTransaction();
        try {
            // Upsert notas (sin tocar created_at en updates)
            foreach ($items as $it) {
                $rubroId = (int) $it['rubro_id'];
                if (!$rubros->has($rubroId)) continue;

                $infoId = (int) $it['infoestudiantesifas_id'];
                $nota = array_key_exists('nota', $it) ? $it['nota'] : null;
                if ($nota !== null) {
                    $nota = (int) round($nota);
                }

                DB::statement(
                    'INSERT INTO notas_rubro (rubro_id, infoestudiantesifas_id, nota, created_at, updated_at)
                     VALUES (?, ?, ?, NOW(), NOW())
                     ON DUPLICATE KEY UPDATE nota = VALUES(nota), updated_at = VALUES(updated_at)',
                    [$rubroId, $infoId, $nota]
                );
            }

            $infoIds = collect($items)->pluck('infoestudiantesifas_id')->unique()->values();

            $colTeo = 'Teorico' . $numeroEval;
            $colPra = 'Practico' . $numeroEval;

            foreach ($infoIds as $infoId) {
                $rows = DB::table('notas_rubro as n')
                    ->join('rubros_evaluacion as r', 'r.id', '=', 'n.rubro_id')
                    ->where('r.evaluacion_id', $eval->id)
                    ->where('r.habilitado', 1)
                    ->where('n.infoestudiantesifas_id', $infoId)
                    ->whereNotNull('n.nota')
                    ->select(['r.tipo', 'r.max_puntos', 'n.nota'])
                    ->get();

                $sumNotasTeo = 0.0;
                $sumMaxTeo = 0;
                $sumNotasPra = 0.0;
                $sumMaxPra = 0;

                foreach ($rows as $r) {
                    if ($r->tipo === 'TEO') {
                        $sumNotasTeo += (float) $r->nota;
                        $sumMaxTeo += (int) $r->max_puntos;
                    } else {
                        $sumNotasPra += (float) $r->nota;
                        $sumMaxPra += (int) $r->max_puntos;
                    }
                }

                $teo = null;
                if ($sumMaxTeo > 0) {
                    $teo = (int) round(($sumNotasTeo / $sumMaxTeo) * (int) $eval->limite_teorico);
                    if ($teo < 0) $teo = 0;
                    if ($teo > (int) $eval->limite_teorico) $teo = (int) $eval->limite_teorico;
                }

                $pra = null;
                if ($sumMaxPra > 0) {
                    $pra = (int) round(($sumNotasPra / $sumMaxPra) * (int) $eval->limite_practico);
                    if ($pra < 0) $pra = 0;
                    if ($pra > (int) $eval->limite_practico) $pra = (int) $eval->limite_practico;
                }

                // Carga la fila actual de calificaciones y actualiza Teo/Prac de la evaluación
                $cal = DB::table('calificaciones')
                    ->where('infoestudiantesifas_id', $infoId)
                    ->where('materias_id', $materiaId)
                    ->first();

                if (!$cal) {
                    // si no existe, se crea
                    DB::table('calificaciones')->insert([
                        'infoestudiantesifas_id' => $infoId,
                        'materias_id' => $materiaId,
                        $colTeo => $teo,
                        $colPra => $pra,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } else {
                    DB::table('calificaciones')
                        ->where('id', $cal->id)
                        ->update([
                            $colTeo => $teo,
                            $colPra => $pra,
                            'updated_at' => now(),
                        ]);
                }

                // Recalcula promedios en backend para mantener consistencia
                $calRow = DB::table('calificaciones')
                    ->where('infoestudiantesifas_id', $infoId)
                    ->where('materias_id', $materiaId)
                    ->first();

                if ($calRow) {
                    $arr = (array) $calRow;
                    $arr = $this->recalcPromedios($arr, $avgEvalCount);

                    DB::table('calificaciones')
                        ->where('id', $calRow->id)
                        ->update([
                            'PromTeorico' => $arr['PromTeorico'],
                            'PromPractico' => $arr['PromPractico'],
                            'Promedio' => $arr['Promedio'],
                            'updated_at' => now(),
                        ]);
                }
            }

            DB::commit();
            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
