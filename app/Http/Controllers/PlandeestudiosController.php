<?php

namespace App\Http\Controllers;

use App\Models\plandeestudios;
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
        $institucionIdParam = request()->query('instituciones_id');

        $anioId = $anioId !== null ? (int) $anioId : null;
        $resolucion = $resolucion !== null ? trim((string) $resolucion) : null;
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
            ->orderBy('pe.carreras_id')->orderBy('pe.RangoLvlCurso')->orderBy('pe.Rango');

        $plandeestudios = $plandeestudiosQuery->get();

        return response()->json([
            'data' => $plandeestudios
        ]);
    }
    
    public function store(Request $request)
    {
        $plandeestudios = $request->all();
        plandeestudios::insert($plandeestudios);
        return response()->json(['data' => $plandeestudios]);
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

        $plandeestudios = plandeestudios::where('id', '=', $id)->firstOrFail();
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
        plandeestudios::where('id', '=', $request->id)->update($payload);
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

        plandeestudios::destroy($id);
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
    //#endregion Fin Controller de Crud PHP de plandeestudios
}
