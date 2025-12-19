<?php

namespace App\Http\Controllers;

use App\Models\calificaciones;
use Illuminate\Http\Request;

use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;

class CalificacionesController extends Controller
{
    public function __construct() {
        $this->middleware(UpdateTokenExpiration::class);
    }
    //#region Inicio Controller de Crud PHP de calificaciones
    public function index()
    {
        $calificaciones = calificaciones::all();
        return response()->json(['data' => $calificaciones]);
    }
    
    
    public function store(Request $request)
    {
        $calificaciones = $request->all();
        calificaciones::insert($calificaciones);
        return response()->json(['data' => $calificaciones]);
    }
    
    public function show($id)
    {
        $calificaciones = calificaciones::where('id','=',$id)->firstOrFail();
        return response()->json(['data' => $calificaciones]);
    }
    
    
    public function update(Request $request)
    {
        $calificaciones = $request->all();
        calificaciones::where('id','=',$request->id)->update($calificaciones);
        return response()->json(['data' => $calificaciones]);
    }
    
    public function destroy($id)
    {
        calificaciones::destroy($id);
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
    //#endregion Fin Controller de Crud PHP de calificaciones
}
