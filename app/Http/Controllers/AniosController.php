<?php

namespace App\Http\Controllers;

use App\Models\Anios;
use Illuminate\Http\Request;
use App\Http\Middleware\UpdateTokenExpiration;
use Illuminate\Routing\Controller;

class AniosController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }
    //#region Inicio Controller de Crud PHP de anios
    public function index()
    {
        $anios = Anios::all();
        return response()->json(['data' => $anios]);
    }
    
    
    public function store(Request $request)
    {
        $anios = $request->all();
        Anios::insert($anios);
        return response()->json(['data' => $anios]);
    }
    
    public function show($id)
    {
        $anios = Anios::where('id','=',$id)->firstOrFail();
        return response()->json(['data' => $anios]);
    }
    
    
    public function update(Request $request)
    {
        $anios = $request->all();
        Anios::where('id','=',$request->id)->update($anios);
        return response()->json(['data' => $anios]);
    }
    
    public function destroy($id)
    {
        Anios::destroy($id);
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
    //#endregion Fin Controller de Crud PHP de anios
}
