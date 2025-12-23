<?php

// namespace App\Http\Controllers;

// use App\Http\Middleware\UpdateTokenExpiration;
// use App\Models\materias;
// use App\Models\planteldocentes;
// use App\Models\planteldocentesmaterias;
// use Illuminate\Http\Request;
// use Illuminate\Routing\Controller;

// class PlanteldocentesmateriasController extends Controller
// {
//     public function __construct()
//     {
//         $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
//     }

//     public function index(Request $request)
//     {
//         $user = $request->user();
//         if (!$user || !$user->instituciones_id) {
//             return response()->json(['data' => []]);
//         }

//         $query = planteldocentesmaterias::query()
//             ->select('planteldocentesmaterias.*')
//             ->join('planteldocentes', 'planteldocentesmaterias.planteldocentes_id', '=', 'planteldocentes.id')
//             ->where('planteldocentes.instituciones_id', $user->instituciones_id);

//         if ($request->filled('planteldocentes_id')) {
//             $query->where('planteldocentesmaterias.planteldocentes_id', $request->get('planteldocentes_id'));
//         }

//         return response()->json(['data' => $query->get()]);
//     }

//     public function store(Request $request)
//     {
//         return $this->assign($request);
//     }

//     public function show(Request $request, $id)
//     {
//         $user = $request->user();
//         if (!$user || !$user->instituciones_id) {
//             abort(404);
//         }

//         $row = planteldocentesmaterias::query()
//             ->select('planteldocentesmaterias.*')
//             ->join('planteldocentes', 'planteldocentesmaterias.planteldocentes_id', '=', 'planteldocentes.id')
//             ->where('planteldocentes.instituciones_id', $user->instituciones_id)
//             ->where('planteldocentesmaterias.id', $id)
//             ->firstOrFail();

//         return response()->json(['data' => $row]);
//     }

//     public function update(Request $request, $id)
//     {
//         $user = $request->user();
//         if (!$user || !$user->instituciones_id) {
//             abort(404);
//         }

//         $assignment = planteldocentesmaterias::query()
//             ->join('planteldocentes', 'planteldocentesmaterias.planteldocentes_id', '=', 'planteldocentes.id')
//             ->where('planteldocentes.instituciones_id', $user->instituciones_id)
//             ->where('planteldocentesmaterias.id', $id)
//             ->select('planteldocentesmaterias.*')
//             ->firstOrFail();

//         $data = $request->only(['Paralelo', 'EstadoHabilitacion', 'EstadoEnvio']);
//         $assignment->update($data);

//         return response()->json(['data' => $assignment]);
//     }

//     public function destroy(Request $request, $id)
//     {
//         $user = $request->user();
//         if (!$user || !$user->instituciones_id) {
//             abort(404);
//         }

//         $deleted = planteldocentesmaterias::query()
//             ->join('planteldocentes', 'planteldocentesmaterias.planteldocentes_id', '=', 'planteldocentes.id')
//             ->where('planteldocentes.instituciones_id', $user->instituciones_id)
//             ->where('planteldocentesmaterias.id', $id)
//             ->delete();

//         return response()->json(['data' => $deleted ? 'ELIMINADO EXITOSAMENTE' : 'NO ENCONTRADO']);
//     }

//     public function assign(Request $request)
//     {
//         $user = $request->user();
//         if (!$user || !$user->instituciones_id) {
//             return response()->json(['message' => 'Usuario sin institución'], 422);
//         }

//         $validated = $request->validate([
//             'planteldocentes_id' => ['required', 'integer'],
//             'materias_id' => ['required', 'integer'],
//             'Paralelo' => ['nullable', 'string', 'max:50'],
//         ]);

//         $docenteOk = planteldocentes::query()
//             ->where('id', $validated['planteldocentes_id'])
//             ->where('instituciones_id', $user->instituciones_id)
//             ->exists();

//         if (!$docenteOk) {
//             return response()->json(['message' => 'Docente no pertenece a la institución'], 403);
//         }

//         $materiaOk = materias::query()
//             ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
//             ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
//             ->where('materias.id', $validated['materias_id'])
//             ->where('carreras.instituciones_id', $user->instituciones_id)
//             ->exists();

//         if (!$materiaOk) {
//             return response()->json(['message' => 'Materia no pertenece a la institución'], 403);
//         }

//         $assignment = planteldocentesmaterias::query()->firstOrCreate(
//             [
//                 'planteldocentes_id' => $validated['planteldocentes_id'],
//                 'materias_id' => $validated['materias_id'],
//             ],
//             [
//                 'Paralelo' => $validated['Paralelo'] ?? null,
//                 'EstadoHabilitacion' => $request->input('EstadoHabilitacion'),
//                 'EstadoEnvio' => $request->input('EstadoEnvio'),
//             ]
//         );

//         if ($request->filled('Paralelo') && $assignment->Paralelo !== $request->input('Paralelo')) {
//             $assignment->Paralelo = $request->input('Paralelo');
//             $assignment->save();
//         }

//         return response()->json(['data' => $assignment]);
//     }

//     public function unassign(Request $request)
//     {
//         $user = $request->user();
//         if (!$user || !$user->instituciones_id) {
//             return response()->json(['message' => 'Usuario sin institución'], 422);
//         }

//         $validated = $request->validate([
//             'planteldocentes_id' => ['required', 'integer'],
//             'materias_id' => ['required', 'integer'],
//         ]);

//         $docenteOk = planteldocentes::query()
//             ->where('id', $validated['planteldocentes_id'])
//             ->where('instituciones_id', $user->instituciones_id)
//             ->exists();

//         if (!$docenteOk) {
//             return response()->json(['message' => 'Docente no pertenece a la institución'], 403);
//         }

//         $deleted = planteldocentesmaterias::query()
//             ->where('planteldocentes_id', $validated['planteldocentes_id'])
//             ->where('materias_id', $validated['materias_id'])
//             ->delete();

//         return response()->json(['data' => ['deleted' => $deleted]]);
//     }
// }
// <?php

namespace App\Http\Controllers;

use App\Models\planteldocentesmaterias;
use Illuminate\Http\Request;

use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;
use App\Models\materias;
use App\Models\planteldocentes;

class PlanteldocentesmateriasController extends Controller
{
   public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->instituciones_id) {
            return response()->json(['data' => []]);
        }

        $query = planteldocentesmaterias::query()
            ->select('planteldocentesmaterias.*')
            ->join('planteldocentes', 'planteldocentesmaterias.planteldocentes_id', '=', 'planteldocentes.id')
            ->where('planteldocentes.instituciones_id', $user->instituciones_id);

        if ($request->filled('planteldocentes_id')) {
            $query->where('planteldocentesmaterias.planteldocentes_id', $request->get('planteldocentes_id'));
        }

        return response()->json(['data' => $query->get()]);
    }

    public function store(Request $request)
    {
        return $this->assign($request);
    }

    public function show(Request $request, $id)
    {
        $user = $request->user();
        if (!$user || !$user->instituciones_id) {
            abort(404);
        }

        $row = planteldocentesmaterias::query()
            ->select('planteldocentesmaterias.*')
            ->join('planteldocentes', 'planteldocentesmaterias.planteldocentes_id', '=', 'planteldocentes.id')
            ->where('planteldocentes.instituciones_id', $user->instituciones_id)
            ->where('planteldocentesmaterias.id', $id)
            ->firstOrFail();

        return response()->json(['data' => $row]);
    }

    public function update(Request $request, $id)
    {
        $user = $request->user();
        if (!$user || !$user->instituciones_id) {
            abort(404);
        }

        $assignment = planteldocentesmaterias::query()
            ->join('planteldocentes', 'planteldocentesmaterias.planteldocentes_id', '=', 'planteldocentes.id')
            ->where('planteldocentes.instituciones_id', $user->instituciones_id)
            ->where('planteldocentesmaterias.id', $id)
            ->select('planteldocentesmaterias.*')
            ->firstOrFail();

        $data = $request->only(['Paralelo', 'EstadoHabilitacion', 'EstadoEnvio']);
        $assignment->update($data);

        return response()->json(['data' => $assignment]);
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        if (!$user || !$user->instituciones_id) {
            abort(404);
        }

        $deleted = planteldocentesmaterias::query()
            ->join('planteldocentes', 'planteldocentesmaterias.planteldocentes_id', '=', 'planteldocentes.id')
            ->where('planteldocentes.instituciones_id', $user->instituciones_id)
            ->where('planteldocentesmaterias.id', $id)
            ->delete();

        return response()->json(['data' => $deleted ? 'ELIMINADO EXITOSAMENTE' : 'NO ENCONTRADO']);
    }

    public function assign(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->instituciones_id) {
            return response()->json(['message' => 'Usuario sin institución'], 422);
        }

        $validated = $request->validate([
            'planteldocentes_id' => ['required', 'integer'],
            'materias_id' => ['required', 'integer'],
            'Paralelo' => ['nullable', 'string', 'max:50'],
        ]);

        $docenteOk = planteldocentes::query()
            ->where('id', $validated['planteldocentes_id'])
            ->where('instituciones_id', $user->instituciones_id)
            ->exists();

        if (!$docenteOk) {
            return response()->json(['message' => 'Docente no pertenece a la institución'], 403);
        }

        $materiaOk = materias::query()
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->where('materias.id', $validated['materias_id'])
            ->where('carreras.instituciones_id', $user->instituciones_id)
            ->exists();

        if (!$materiaOk) {
            return response()->json(['message' => 'Materia no pertenece a la institución'], 403);
        }

        $assignment = planteldocentesmaterias::query()->firstOrCreate(
            [
                'planteldocentes_id' => $validated['planteldocentes_id'],
                'materias_id' => $validated['materias_id'],
            ],
            [
                'Paralelo' => $validated['Paralelo'] ?? null,
                'EstadoHabilitacion' => $request->input('EstadoHabilitacion'),
                'EstadoEnvio' => $request->input('EstadoEnvio'),
            ]
        );

        if ($request->filled('Paralelo') && $assignment->Paralelo !== $request->input('Paralelo')) {
            $assignment->Paralelo = $request->input('Paralelo');
            $assignment->save();
        }

        return response()->json(['data' => $assignment]);
    }

    public function unassign(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->instituciones_id) {
            return response()->json(['message' => 'Usuario sin institución'], 422);
        }

        $validated = $request->validate([
            'planteldocentes_id' => ['required', 'integer'],
            'materias_id' => ['required', 'integer'],
        ]);

        $docenteOk = planteldocentes::query()
            ->where('id', $validated['planteldocentes_id'])
            ->where('instituciones_id', $user->instituciones_id)
            ->exists();

        if (!$docenteOk) {
            return response()->json(['message' => 'Docente no pertenece a la institución'], 403);
        }

        $deleted = planteldocentesmaterias::query()
            ->where('planteldocentes_id', $validated['planteldocentes_id'])
            ->where('materias_id', $validated['materias_id'])
            ->delete();

        return response()->json(['data' => ['deleted' => $deleted]]);
    }
    //#endregion Fin Controller de Crud PHP de planteldocentesmaterias
}
