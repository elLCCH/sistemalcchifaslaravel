<?php

namespace App\Http\Controllers;

use App\Models\gestionesaltorango\usuarioslcchs;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;
use Illuminate\Support\Facades\Hash;

class UsuarioslcchsController extends Controller
{
    public function __construct() {
        $this->middleware(UpdateTokenExpiration::class);
    }
    //#region Inicio Controller de Crud PHP de usuarioslcchs
    public function index()
    {
        $usuarioslcchs = usuarioslcchs::all();
        return response()->json(['data' => $usuarioslcchs]);
    }
    
    
    public function store(Request $request)
    {
        $usuarioslcchs = $request->all();
        usuarioslcchs::insert($usuarioslcchs);
        return response()->json(['data' => $usuarioslcchs]);
    }
    
    public function show($id)
    {
        $usuarioslcchs = usuarioslcchs::where('id','=',$id)->firstOrFail();
        return response()->json(['data' => $usuarioslcchs]);
    }
    
    
    public function update(Request $request)
    {
        $usuarioslcchs = $request->all();
        usuarioslcchs::where('id','=',$request->id)->update($usuarioslcchs);
        return response()->json(['data' => $usuarioslcchs]);
    }
    
    public function destroy($id)
    {
        usuarioslcchs::destroy($id);
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
    //#endregion Fin Controller de Crud PHP de usuarioslcchs
}
