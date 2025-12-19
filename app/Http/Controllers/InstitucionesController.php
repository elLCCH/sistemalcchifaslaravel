<?php

namespace App\Http\Controllers;

use App\Models\Instituciones;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;

class InstitucionesController extends Controller
{
    public function __construct() {
        $this->middleware(UpdateTokenExpiration::class);
    }
    //#region Inicio Controller de Crud PHP de Instituciones
    public function index()
    {
        $Instituciones = Instituciones::all();
        return response()->json(['data' => $Instituciones]);
    }
    
    
    public function store(Request $request)
    {
        $Instituciones = $request->all();
        Instituciones::insert($Instituciones);
        return response()->json(['data' => $Instituciones]);
    }
    
    public function show($id)
    {
        $Instituciones = Instituciones::where('id','=',$id)->firstOrFail();
        return response()->json(['data' => $Instituciones]);
    }
    
    
    public function update(Request $request)
    {
        $Instituciones = $request->all();
        Instituciones::where('id','=',$request->id)->update($Instituciones);
        return response()->json(['data' => $Instituciones]);
    }
    
    public function destroy($id)
    {
        Instituciones::destroy($id);
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
    //#endregion Fin Controller de Crud PHP de Instituciones
}
