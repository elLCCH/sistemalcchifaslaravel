<?php

namespace App\Http\Controllers;

use App\Models\historialinformacionestudiantes;
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
        $historialinformacionestudiantes = historialinformacionestudiantes::all();
        return response()->json(['data' => $historialinformacionestudiantes]);
    }
    
    
    public function store(Request $request)
    {
        $historialinformacionestudiantes = $request->all();
        historialinformacionestudiantes::insert($historialinformacionestudiantes);
        return response()->json(['data' => $historialinformacionestudiantes]);
    }
    
    public function show($id)
    {
        $historialinformacionestudiantes = historialinformacionestudiantes::where('id','=',$id)->firstOrFail();
        return response()->json(['data' => $historialinformacionestudiantes]);
    }
    
    
    public function update(Request $request)
    {
        $historialinformacionestudiantes = $request->all();
        historialinformacionestudiantes::where('id','=',$request->id)->update($historialinformacionestudiantes);
        return response()->json(['data' => $historialinformacionestudiantes]);
    }
    
    public function destroy($id)
    {
        historialinformacionestudiantes::destroy($id);
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
    //#endregion Fin Controller de Crud PHP de historialinformacionestudiantes
}
