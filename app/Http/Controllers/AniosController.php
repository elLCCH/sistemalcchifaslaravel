<?php

namespace App\Http\Controllers;

use App\Models\gestionesaltorango\anios;
use Illuminate\Http\Request;
use App\Http\Middleware\UpdateTokenExpiration;
use Illuminate\Routing\Controller;

class AniosController extends Controller
{
    public function __construct() {
        $this->middleware(UpdateTokenExpiration::class);
    }
    //#region Inicio Controller de Crud PHP de anios
    public function index()
    {
        $anios = anios::all();
        return response()->json(['data' => $anios]);
    }
    
    
    public function store(Request $request)
    {
        $anios = $request->all();
        anios::insert($anios);
        return response()->json(['data' => $anios]);
    }
    
    public function show($id)
    {
        $anios = anios::where('id','=',$id)->firstOrFail();
        return response()->json(['data' => $anios]);
    }
    
    
    public function update(Request $request)
    {
        $anios = $request->all();
        anios::where('id','=',$request->id)->update($anios);
        return response()->json(['data' => $anios]);
    }
    
    public function destroy($id)
    {
        anios::destroy($id);
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
    //#endregion Fin Controller de Crud PHP de anios
}
