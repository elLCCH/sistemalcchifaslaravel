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

        $rows = calificaciones::query()
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
            ])
            ->orderBy('plandeestudios.RangoLvlCurso')
            ->orderBy('plandeestudios.Rango')
            ->orderBy('plandeestudios.NombreMateria')
            ->get();

        return response()->json(['data' => $rows]);
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
