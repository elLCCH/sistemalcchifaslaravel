<?php

namespace App\Http\Controllers;

use App\Models\Usuarioslcchs;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;
use Illuminate\Support\Facades\Hash;

class UsuarioslcchsController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }
    //#region Inicio Controller de Crud PHP de usuarioslcchs
    public function index()
    {
        $usuarioslcchs = Usuarioslcchs::all();
        return response()->json(['data' => $usuarioslcchs]);
    }
    
    
    public function store(Request $request)
    {
        $usuarioslcchs = $request->all();
        $usuarioslcchs['Contrasenia'] = Hash::make($request->input('Contrasenia'));
        Usuarioslcchs::insert($usuarioslcchs);
        return response()->json(['data' => $usuarioslcchs]);
    }
    
    public function show($id)
    {
        $usuarioslcchs = Usuarioslcchs::where('id','=',$id)->firstOrFail();
        return response()->json(['data' => $usuarioslcchs]);
    }
    
    
    public function update(Request $request)
    {
        // $usuarioslcchs = $request->all();
        // usuarioslcchs::where('id','=',$request->id)->update($usuarioslcchs);
        // return response()->json(['data' => $usuarioslcchs]);

        $usuarioslcchs = Usuarioslcchs::findOrFail($request->id);
        $requestData = $request->all();

        if ($request->has('Contrasenia')) {
            // Si se envió la contraseña
            if (Hash::needsRehash($request->Contrasenia)) {
            $requestData['Contrasenia'] = Hash::make($request->Contrasenia);
            } else {
            $requestData['Contrasenia'] = $request->Contrasenia;
            }
        } else {
            // No se envió la contraseña, mantener la actual
            $requestData['Contrasenia'] = $usuarioslcchs->Contrasenia;
        }

        $usuarioslcchs->update($requestData);
        return response()->json(['data' => $usuarioslcchs]);
    }
    
    public function destroy($id)
    {
        Usuarioslcchs::destroy($id);
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
    //#endregion Fin Controller de Crud PHP de usuarioslcchs
}
