<?php

namespace App\Http\Controllers;

use App\Models\estudiantesifas;
use Illuminate\Http\Request;

use Illuminate\Support\Facades\Hash;
use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;
class EstudiantesifasController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }
    //controllerPHPlcch estudiantesifas, $
    //#region Inicio Controller de Crud PHP de estudiantesifas
    public function index()
    {
        $estudiantesifas = estudiantesifas::all();
        return response()->json(['data' => $estudiantesifas]);
    }
    
    
    public function store(Request $request)
    {
        $estudiantesifas = $request->all();
        $usuarioslcchs['Contrasenia'] = Hash::make($request->input('Contrasenia'));
        estudiantesifas::insert($estudiantesifas);
        return response()->json(['data' => $estudiantesifas]);
    }
    
    public function show($id)
    {
        $estudiantesifas = estudiantesifas::where('id','=',$id)->firstOrFail();
        return response()->json(['data' => $estudiantesifas]);
    }
    
    
    public function update(Request $request)
    {
        // $estudiantesifas = $request->all();
        // estudiantesifas::where('id','=',$request->id)->update($estudiantesifas);
        // return response()->json(['data' => $estudiantesifas]);

        $estudiantesifas = estudiantesifas::findOrFail($request->id);
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
            $requestData['Contrasenia'] = $estudiantesifas->Contrasenia;
        }

        $estudiantesifas->update($requestData);
        return response()->json(['data' => $estudiantesifas]);
    }
    
    public function destroy($id)
    {
        estudiantesifas::destroy($id);
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
    //#endregion Fin Controller de Crud PHP de estudiantesifas
}
