<?php

namespace App\Http\Controllers;

use App\Models\carreras;
use Illuminate\Http\Request;

use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;
class CarrerasController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }
    //controllerPHPlcch carreras, $
    //#region Inicio Controller de Crud PHP de carreras
    public function index()
    {
        // $carreras = carreras::all();
        
        $user = request()->user();
        $carreras = \App\Models\carreras::where('instituciones_id', $user->instituciones_id)->get();
        foreach ($carreras as $carrera) {
            $institucion = \App\Models\instituciones::find($carrera->instituciones_id);
            $carrera->NombreInstitucion = $institucion ? $institucion->Nombre : null;
        }
        return response()->json(['data' => $carreras]);
    }
    
    
    public function store(Request $request)
    {
        $carreras = $request->all();
        $user = request()->user();
        if ($user->instituciones_id) {
            $carreras['instituciones_id'] = $user->instituciones_id;
        }
        carreras::insert($carreras);
        return response()->json(['data' => $carreras]);
    }
    
    public function show($id)
    {
        $carreras = carreras::where('id','=',$id)->firstOrFail();
        return response()->json(['data' => $carreras]);
    }
    
    
    public function update(Request $request)
    {
        $carreras = $request->all();
        carreras::where('id','=',$request->id)->update($carreras);
        return response()->json(['data' => $carreras]);
    }
    
    public function destroy($id)
    {
        carreras::destroy($id);
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
    //#endregion Fin Controller de Crud PHP de carreras
}
