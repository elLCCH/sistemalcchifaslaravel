<?php

namespace App\Http\Controllers;

use App\Models\Plandeestudios;
use Illuminate\Http\Request;

use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;
use Illuminate\Support\Facades\DB;

class PlandeestudiosController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }
    //controllerPHPlcch plandeestudios, $
    //#region Inicio Controller de Crud PHP de plandeestudios
    public function index()
    {
        // $plandeestudios = plandeestudios::all();
        $user = request()->user();
        $isSuperAdmin = empty($user?->instituciones_id);

        $anioId = request()->query('anio_id');
        $resolucion = request()->query('resolucion');
        $nivel = request()->query('nivel');
        $institucionIdParam = request()->query('instituciones_id');

        $anioId = $anioId !== null ? (int) $anioId : null;
        $resolucion = $resolucion !== null ? trim((string) $resolucion) : null;
        $nivel = $nivel !== null ? trim((string) $nivel) : null;
        $institucionIdParam = $institucionIdParam !== null ? (int) $institucionIdParam : null;

        $plandeestudiosQuery = DB::table('plandeestudios as pe')
            ->leftJoin('anios as a', 'a.id', '=', 'pe.anio_id')
            ->join('carreras as c', 'c.id', '=', 'pe.carreras_id')
            ->join('instituciones as i', 'i.id', '=', 'c.instituciones_id')
            ->select(
            'pe.*',
            'a.Anio as Anio',
            'c.NombreCarrera',
            'c.Resolucion',
            'c.Nivel',
            $isSuperAdmin ? 'i.Nombre as NombreInstitucion' : DB::raw('NULL as NombreInstitucion')
            )
            ->when(!$isSuperAdmin, function ($q) use ($user) {
                $q->where('i.id', '=', $user->instituciones_id);
            })
            ->when($isSuperAdmin && !empty($institucionIdParam), function ($q) use ($institucionIdParam) {
                $q->where('i.id', '=', $institucionIdParam);
            })
            ->when(!empty($anioId), function ($q) use ($anioId) {
                $q->where('pe.anio_id', '=', $anioId);
            })
            ->when(!empty($resolucion), function ($q) use ($resolucion) {
                $q->where('c.Resolucion', '=', $resolucion);
            })
            ->when(!empty($nivel), function ($q) use ($nivel) {
                $q->where('c.Nivel', '=', $nivel);
            })
            ->orderBy('pe.carreras_id')->orderBy('pe.RangoLvlCurso')->orderBy('pe.Rango');

        $plandeestudios = $plandeestudiosQuery->get();

        return response()->json([
            'data' => $plandeestudios
        ]);
    }
    
    public function store(Request $request)
    {
        $plandeestudios = $request->all();
        Plandeestudios::insert($plandeestudios);
        return response()->json(['data' => $plandeestudios]);
    }

    public function cloneGestion(Request $request)
    {
        $user = $request->user();
        $isSuperAdmin = empty($user?->instituciones_id);

        $rules = [
            'anio_origen_id' => ['required', 'integer', 'exists:anios,id', 'different:anio_destino_id'],
            'anio_destino_id' => ['required', 'integer', 'exists:anios,id'],
        ];

        if ($isSuperAdmin) {
            $rules['instituciones_id'] = ['required', 'integer', 'exists:instituciones,id'];
        } else {
            $rules['instituciones_id'] = ['nullable', 'integer'];
        }

        $validated = $request->validate($rules);

        $institucionId = $isSuperAdmin
            ? (int) $validated['instituciones_id']
            : (int) $user->instituciones_id;
        $anioOrigenId = (int) $validated['anio_origen_id'];
        $anioDestinoId = (int) $validated['anio_destino_id'];

        $sourcePlans = $this->baseInstitucionAnioQuery($institucionId, $anioOrigenId)
            ->select(
                'pe.carreras_id',
                'pe.Rango',
                'pe.RangoLvlCurso',
                'pe.LvlCurso',
                'pe.Horas',
                'pe.ModoMateria',
                'pe.NombreMateria',
                'pe.SiglaMateria',
                'pe.Prerrequisitos',
                'pe.SiglasPrerrequisitos',
                'pe.TipoMateria',
                'pe.Periodo',
                'pe.RelacionDocenteCursoAEstudiante'
            )
            ->orderBy('pe.carreras_id')
            ->orderBy('pe.RangoLvlCurso')
            ->orderBy('pe.Rango')
            ->get();

        if ($sourcePlans->isEmpty()) {
            return response()->json([
                'message' => 'La gestión origen no tiene plan de estudios registrado para la institución seleccionada.'
            ], 422);
        }

        $destinationHasPlans = $this->baseInstitucionAnioQuery($institucionId, $anioDestinoId)->exists();

        if ($destinationHasPlans) {
            return response()->json([
                'message' => 'La gestión destino ya tiene plan de estudios registrado para esa institución.'
            ], 422);
        }

        $timestamp = now();
        $insertData = $sourcePlans->map(function ($plan) use ($anioDestinoId, $timestamp) {
            return [
                'carreras_id' => $plan->carreras_id,
                'Rango' => $plan->Rango,
                'RangoLvlCurso' => $plan->RangoLvlCurso,
                'LvlCurso' => $plan->LvlCurso,
                'Horas' => $plan->Horas,
                'anio_id' => $anioDestinoId,
                'ModoMateria' => $plan->ModoMateria,
                'NombreMateria' => $plan->NombreMateria,
                'SiglaMateria' => $plan->SiglaMateria,
                'Prerrequisitos' => $plan->Prerrequisitos,
                'SiglasPrerrequisitos' => $plan->SiglasPrerrequisitos,
                'TipoMateria' => $plan->TipoMateria,
                'Periodo' => $plan->Periodo,
                'RelacionDocenteCursoAEstudiante' => $plan->RelacionDocenteCursoAEstudiante,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        })->all();

        DB::transaction(function () use ($insertData) {
            Plandeestudios::insert($insertData);
        });

        return response()->json([
            'data' => [
                'instituciones_id' => $institucionId,
                'anio_origen_id' => $anioOrigenId,
                'anio_destino_id' => $anioDestinoId,
                'cantidad_clonada' => count($insertData),
            ],
            'message' => 'Plan de estudios clonado correctamente.'
        ]);
    }
    
    public function show($id)
    {
        $user = request()->user();
        $isSuperAdmin = empty($user?->instituciones_id);

        if (!$isSuperAdmin) {
            $allowed = DB::table('plandeestudios as pe')
                ->join('carreras as c', 'c.id', '=', 'pe.carreras_id')
                ->where('pe.id', '=', $id)
                ->where('c.instituciones_id', '=', $user->instituciones_id)
                ->exists();

            if (!$allowed) {
                abort(404);
            }
        }

        $plandeestudios = Plandeestudios::where('id', '=', $id)->firstOrFail();
        return response()->json(['data' => $plandeestudios]);
    }
    
    
    public function update(Request $request)
    {
        $user = $request->user();
        $isSuperAdmin = empty($user?->instituciones_id);

        if (!$isSuperAdmin) {
            $allowed = DB::table('plandeestudios as pe')
                ->join('carreras as c', 'c.id', '=', 'pe.carreras_id')
                ->where('pe.id', '=', $request->id)
                ->where('c.instituciones_id', '=', $user->instituciones_id)
                ->exists();

            if (!$allowed) {
                abort(404);
            }
        }

        $payload = $request->all();
        Plandeestudios::where('id', '=', $request->id)->update($payload);
        return response()->json(['data' => $payload]);
    }
    
    public function destroy($id)
    {
        $user = request()->user();
        $isSuperAdmin = empty($user?->instituciones_id);

        if (!$isSuperAdmin) {
            $allowed = DB::table('plandeestudios as pe')
                ->join('carreras as c', 'c.id', '=', 'pe.carreras_id')
                ->where('pe.id', '=', $id)
                ->where('c.instituciones_id', '=', $user->instituciones_id)
                ->exists();

            if (!$allowed) {
                abort(404);
            }
        }

        Plandeestudios::destroy($id);
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }

    private function baseInstitucionAnioQuery(int $institucionId, int $anioId)
    {
        return DB::table('plandeestudios as pe')
            ->join('carreras as c', 'c.id', '=', 'pe.carreras_id')
            ->where('c.instituciones_id', '=', $institucionId)
            ->where('pe.anio_id', '=', $anioId);
    }
    //#endregion Fin Controller de Crud PHP de plandeestudios
}
