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
        $plandeestudios = DB::table('plandeestudios as pe')
            ->leftJoin('anios as a', 'a.id', '=', 'pe.anio_id')
            ->join('carreras as c', 'c.id', '=', 'pe.carreras_id')
            ->join('instituciones as i', 'i.id', '=', 'c.instituciones_id')
            ->select(
            'pe.*',
            'a.Anio as Anio',
            'c.NombreCarrera',
            'c.Resolucion',
            'i.Nombre as NombreInstitucion'
            )
            ->where('i.id', '=', $user->instituciones_id)
            ->orderBy('pe.carreras_id')->orderBy('pe.RangoLvlCurso')->orderBy('pe.Rango')
            ->get();

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
        $plandeestudios = plandeestudios::where('id','=',$id)->firstOrFail();
        return response()->json(['data' => $plandeestudios]);
    }
    
    
    public function update(Request $request)
    {
        $plandeestudios = $request->all();
        plandeestudios::where('id','=',$request->id)->update($plandeestudios);
        return response()->json(['data' => $plandeestudios]);
    }
    
    public function destroy($id)
    {
        plandeestudios::destroy($id);
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
    //#endregion Fin Controller de Crud PHP de plandeestudios
}
