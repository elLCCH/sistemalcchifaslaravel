<?php

namespace App\Http\Controllers;

use App\Models\materias;
use Illuminate\Http\Request;

use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;
class MateriasController extends Controller
{
    public function __construct() {
        $this->middleware(UpdateTokenExpiration::class);
    }
    //controllerPHPlcch materias, $
    //#region Inicio Controller de Crud PHP de materias
    public function index()
    {
        $materias = materias::all();
        return response()->json(['data' => $materias]);
    }
    
    
    public function store(Request $request)
    {
        $materias = $request->all();
        materias::insert($materias);
        return response()->json(['data' => $materias]);
    }
    
    public function show($id)
    {
        $materias = materias::where('id','=',$id)->firstOrFail();
        return response()->json(['data' => $materias]);
    }
    
    
    public function update(Request $request)
    {
        $materias = $request->all();
        materias::where('id','=',$request->id)->update($materias);
        return response()->json(['data' => $materias]);
    }
    
    public function destroy($id)
    {
        materias::destroy($id);
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
    //#endregion Fin Controller de Crud PHP de materias
}
