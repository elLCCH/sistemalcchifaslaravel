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
            'modo_eval' => 3,
            'habilitada' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('evaluaciones_materia')->where('id', $id)->first();
    }

    private function ensureAsistenciaRubro(int $evaluacionId): void
    {
        $exists = DB::table('rubros_evaluacion')
            ->where('evaluacion_id', $evaluacionId)
            ->where('tipo', 'TEO')
            ->where('es_asistencia', 1)
            ->where('habilitado', 1)
            ->exists();

        if ($exists) return;

        DB::table('rubros_evaluacion')->insert([
            'evaluacion_id' => $evaluacionId,
            'tipo' => 'TEO',
            'nombre' => 'Asistencia',
            'max_puntos' => 10,
            'orden' => 9999,
            'es_asistencia' => 1,
            'habilitado' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
        $this->ensureAsistenciaRubro($eval->id);

        $rubros = DB::table('rubros_evaluacion')
            ->where('evaluacion_id', $eval->id)
            ->where('habilitado', 1)
            ->orderBy('tipo')
            ->orderByRaw("CASE WHEN tipo='TEO' THEN es_asistencia ELSE 0 END ASC")
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
        $this->ensureAsistenciaRubro($eval->id);

        $modo = (int) ($eval->modo_eval ?? 3);
        $tipo = $validated['tipo'];

        // En modos 1/2 se fuerza max_puntos
        $max = (int) $validated['max_puntos'];
        if ($modo === 1) {
            $max = 100;
        } elseif ($modo === 2) {
            $max = $tipo === 'TEO' ? 30 : 70;
        }

        $id = DB::table('rubros_evaluacion')->insertGetId([
            'evaluacion_id' => $eval->id,
            'tipo' => $tipo,
            'nombre' => $validated['nombre'],
            'max_puntos' => $max,
            'orden' => (int) ($validated['orden'] ?? 1),
            'es_asistencia' => 0,
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
        $rubro = $this->ensureRubroInInstitucion($request, $rubroId);

        $validated = $request->validate([
            'tipo' => ['required', 'in:TEO,PRA'],
            'nombre' => ['required', 'string', 'max:150'],
            'max_puntos' => ['required', 'integer', 'min:1', 'max:1000'],
            'orden' => ['required', 'integer', 'min:1', 'max:9999'],
        ]);

        // si es asistencia, mantener tipo=TEO y max=10 y orden al final
        $isAsistencia = (int) ($rubro->es_asistencia ?? 0) === 1;

        $eval = DB::table('evaluaciones_materia')->where('id', $rubro->evaluacion_id)->first();
        $modo = (int) ($eval->modo_eval ?? 3);

        $tipo = $validated['tipo'];
        $max = (int) $validated['max_puntos'];
        $orden = (int) $validated['orden'];

        if ($isAsistencia) {
            $tipo = 'TEO';
            $max = 10;
            $orden = 9999;
        } else {
            if ($modo === 1) {
                $max = 100;
            } elseif ($modo === 2) {
                $max = $tipo === 'TEO' ? 30 : 70;
            }
        }

        DB::table('rubros_evaluacion')
            ->where('id', $rubroId)
            ->update([
                'tipo' => $tipo,
                'nombre' => $validated['nombre'],
                'max_puntos' => $max,
                'orden' => $orden,
                'updated_at' => now(),
            ]);

        return response()->json([
            'rubro' => DB::table('rubros_evaluacion')->where('id', $rubroId)->first(),
        ]);
    }

    public function deleteRubro(Request $request, int $rubroId)
    {
        $rubro = $this->ensureRubroInInstitucion($request, $rubroId);

        if ((int) ($rubro->es_asistencia ?? 0) === 1) {
            return response()->json(['message' => 'No se puede eliminar el rubro de asistencia'], 422);
        }

        DB::table('rubros_evaluacion')
            ->where('id', $rubroId)
            ->update([
                'habilitado' => 0,
                'updated_at' => now(),
            ]);

        return response()->json(['ok' => true]);
    }

    public function updateEvaluacion(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->instituciones_id) {
            return response()->json(['message' => 'Usuario sin institución'], 422);
        }

        $validated = $request->validate([
            'materias_id' => ['required', 'integer'],
            'numero_eval' => ['required', 'integer', 'min:1', 'max:4'],
            'modo_eval' => ['required', 'integer', 'min:1', 'max:3'],
        ]);

        $materiaId = (int) $validated['materias_id'];
        $this->ensureMateriaInInstitucion($request, $materiaId);

        $eval = $this->ensureEvaluacion($materiaId, (int) $validated['numero_eval']);
        $this->ensureAsistenciaRubro($eval->id);

        $modo = (int) $validated['modo_eval'];

        DB::table('evaluaciones_materia')
            ->where('id', $eval->id)
            ->update([
                'modo_eval' => $modo,
                'updated_at' => now(),
            ]);

        // Ajustar max_puntos automáticamente en modos 1/2
        if ($modo === 1) {
            DB::table('rubros_evaluacion')
                ->where('evaluacion_id', $eval->id)
                ->where('habilitado', 1)
                ->where('es_asistencia', 0)
                ->update([
                    'max_puntos' => 100,
                    'updated_at' => now(),
                ]);
        } elseif ($modo === 2) {
            DB::table('rubros_evaluacion')
                ->where('evaluacion_id', $eval->id)
                ->where('habilitado', 1)
                ->where('es_asistencia', 0)
                ->where('tipo', 'TEO')
                ->update([
                    'max_puntos' => 30,
                    'updated_at' => now(),
                ]);

            DB::table('rubros_evaluacion')
                ->where('evaluacion_id', $eval->id)
                ->where('habilitado', 1)
                ->where('es_asistencia', 0)
                ->where('tipo', 'PRA')
                ->update([
                    'max_puntos' => 70,
                    'updated_at' => now(),
                ]);
        }

        return response()->json([
            'evaluacion' => DB::table('evaluaciones_materia')->where('id', $eval->id)->first(),
        ]);
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
        $this->ensureAsistenciaRubro($eval->id);

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

            $modo = (int) ($eval->modo_eval ?? 3);

            foreach ($infoIds as $infoId) {
                $rows = DB::table('notas_rubro as n')
                    ->join('rubros_evaluacion as r', 'r.id', '=', 'n.rubro_id')
                    ->where('r.evaluacion_id', $eval->id)
                    ->where('r.habilitado', 1)
                    ->where('n.infoestudiantesifas_id', $infoId)
                    ->whereNotNull('n.nota')
                    ->select(['r.tipo', 'r.max_puntos', 'r.es_asistencia', 'n.nota'])
                    ->get();

                $limTeo = (int) $eval->limite_teorico;
                $limPra = (int) $eval->limite_practico;

                $teoNotas = [];
                $praNotas = [];
                $asistencia = null;

                foreach ($rows as $r) {
                    $max = (int) $r->max_puntos;
                    $nota = (int) round($r->nota);
                    if ($nota < 0) $nota = 0;
                    if ($max > 0 && $nota > $max) $nota = $max;

                    if ($r->tipo === 'TEO') {
                        if ((int) ($r->es_asistencia ?? 0) === 1) {
                            $asistencia = $nota;
                        } else {
                            $teoNotas[] = $nota;
                        }
                    } else {
                        $praNotas[] = $nota;
                    }
                }

                $teo = null;
                $pra = null;

                if ($modo === 1) {
                    // Promedio casilleros TEO sobre 100 -> escala a 20 + asistencia(10)
                    $teoPart = null;
                    if (count($teoNotas) > 0) {
                        $avg = array_sum($teoNotas) / count($teoNotas);
                        $teoPart = (int) round(($avg / 100.0) * 20.0);
                    }
                    if ($teoPart !== null || $asistencia !== null) {
                        $teo = (int) round(($teoPart ?? 0) + ($asistencia ?? 0));
                        if ($teo < 0) $teo = 0;
                        if ($teo > $limTeo) $teo = $limTeo;
                    }

                    // Promedio casilleros PRA sobre 100 -> escala a 70
                    if (count($praNotas) > 0) {
                        $avg = array_sum($praNotas) / count($praNotas);
                        $pra = (int) round(($avg / 100.0) * (float) $limPra);
                        if ($pra < 0) $pra = 0;
                        if ($pra > $limPra) $pra = $limPra;
                    }
                } elseif ($modo === 2) {
                    // TEO: promedio sobre 30 -> escala a 20 + asistencia(10)
                    $teoPart = null;
                    if (count($teoNotas) > 0) {
                        $avg = array_sum($teoNotas) / count($teoNotas);
                        $teoPart = (int) round(($avg / 30.0) * 20.0);
                    }
                    if ($teoPart !== null || $asistencia !== null) {
                        $teo = (int) round(($teoPart ?? 0) + ($asistencia ?? 0));
                        if ($teo < 0) $teo = 0;
                        if ($teo > $limTeo) $teo = $limTeo;
                    }

                    // PRA: promedio sobre 70 -> escala a 70 (limPra)
                    if (count($praNotas) > 0) {
                        $avg = array_sum($praNotas) / count($praNotas);
                        $pra = (int) round(($avg / 70.0) * (float) $limPra);
                        if ($pra < 0) $pra = 0;
                        if ($pra > $limPra) $pra = $limPra;
                    }
                } else {
                    // Sumatoria
                    $sumTeo = array_sum($teoNotas) + ($asistencia ?? 0);
                    $sumPra = array_sum($praNotas);

                    if (count($teoNotas) > 0 || $asistencia !== null) {
                        $teo = (int) round($sumTeo);
                        if ($teo < 0) $teo = 0;
                        if ($teo > $limTeo) $teo = $limTeo;
                    }

                    if (count($praNotas) > 0) {
                        $pra = (int) round($sumPra);
                        if ($pra < 0) $pra = 0;
                        if ($pra > $limPra) $pra = $limPra;
                    }
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
