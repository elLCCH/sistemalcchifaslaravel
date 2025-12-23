<?php

namespace App\Http\Controllers;

use App\Models\infoestudiantesifas;
use Illuminate\Http\Request;

use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;
class InfoestudiantesifasController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }
    //controllerPHPlcch infoestudiantesifas, $
    //#region Inicio Controller de Crud PHP de infoestudiantesifas
    public function index()
    {
        // $infoestudiantesifas = infoestudiantesifas::all();
        $user = request()->user();
        $infoestudiantesifas = \App\Models\infoestudiantesifas::where('instituciones_id', $user->instituciones_id)->get();
        foreach ($infoestudiantesifas as $infoestudiantesifas) {
            $institucion = \App\Models\instituciones::find($infoestudiantesifas->instituciones_id);
            $infoestudiantesifas->NombreInstitucion = $institucion ? $institucion->Nombre : null;
        }
        return response()->json(['data' => $infoestudiantesifas]);
    }
    
    
    public function store(Request $request)
    {
        $infoestudiantesifas = $request->all();
        $user = request()->user();
        if ($user->instituciones_id) {
            $infoestudiantesifas['instituciones_id'] = $user->instituciones_id;
        }
        infoestudiantesifas::insert($infoestudiantesifas);
        return response()->json(['data' => $infoestudiantesifas]);
    }
    
    public function show($id)
    {
        $infoestudiantesifas = infoestudiantesifas::where('id','=',$id)->firstOrFail();
        return response()->json(['data' => $infoestudiantesifas]);
    }
    
    
    public function update(Request $request)
    {
        $infoestudiantesifas = $request->all();
        infoestudiantesifas::where('id','=',$request->id)->update($infoestudiantesifas);
        return response()->json(['data' => $infoestudiantesifas]);
    }
    
    public function destroy($id)
    {
        infoestudiantesifas::destroy($id);
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
    //#endregion Fin Controller de Crud PHP de infoestudiantesifas
}
