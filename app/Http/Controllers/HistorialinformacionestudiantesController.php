<?php

namespace App\Http\Controllers;

use App\Models\Historialinformacionestudiantes;
use Illuminate\Http\Request;

use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;
class HistorialinformacionestudiantesController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }
    //controllerPHPlcch historialinformacionestudiantes, $
    //#region Inicio Controller de Crud PHP de historialinformacionestudiantes
    public function index()
    {
        $historialinformacionestudiantes = Historialinformacionestudiantes::all();
        return response()->json(['data' => $historialinformacionestudiantes]);
    }
    
    
    public function store(Request $request)
    {
        $historialinformacionestudiantes = $request->all();
        Historialinformacionestudiantes::insert($historialinformacionestudiantes);
        return response()->json(['data' => $historialinformacionestudiantes]);
    }
    
    public function show($id)
    {
        $historialinformacionestudiantes = Historialinformacionestudiantes::where('id','=',$id)->firstOrFail();
        return response()->json(['data' => $historialinformacionestudiantes]);
    }
    
    
    public function update(Request $request)
    {
        $historialinformacionestudiantes = $request->all();
        Historialinformacionestudiantes::where('id','=',$request->id)->update($historialinformacionestudiantes);
        return response()->json(['data' => $historialinformacionestudiantes]);
    }
    
    public function destroy($id)
    {
        Historialinformacionestudiantes::destroy($id);
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
    //#endregion Fin Controller de Crud PHP de historialinformacionestudiantes
}
