<?php

namespace App\Http\Controllers;

use App\Models\planteldocentesmaterias;
use Illuminate\Http\Request;

use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;
class PlanteldocentesmateriasController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }
    //controllerPHPlcch planteldocentesmaterias, $
    //#region Inicio Controller de Crud PHP de planteldocentesmaterias
    public function index()
    {
        $planteldocentesmaterias = planteldocentesmaterias::all();
        return response()->json(['data' => $planteldocentesmaterias]);
    }
    
    
    public function store(Request $request)
    {
        $planteldocentesmaterias = $request->all();
        planteldocentesmaterias::insert($planteldocentesmaterias);
        return response()->json(['data' => $planteldocentesmaterias]);
    }
    
    public function show($id)
    {
        $planteldocentesmaterias = planteldocentesmaterias::where('id','=',$id)->firstOrFail();
        return response()->json(['data' => $planteldocentesmaterias]);
    }
    
    
    public function update(Request $request)
    {
        $planteldocentesmaterias = $request->all();
        planteldocentesmaterias::where('id','=',$request->id)->update($planteldocentesmaterias);
        return response()->json(['data' => $planteldocentesmaterias]);
    }
    
    public function destroy($id)
    {
        planteldocentesmaterias::destroy($id);
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
    //#endregion Fin Controller de Crud PHP de planteldocentesmaterias
}
