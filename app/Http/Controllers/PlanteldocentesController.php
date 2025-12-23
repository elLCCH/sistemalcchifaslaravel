<?php

namespace App\Http\Controllers;

use App\Models\planteldocentes;
use Illuminate\Http\Request;

use Illuminate\Support\Facades\Hash;
use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;
class PlanteldocentesController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }
    //controllerPHPlcch planteldocentes, $
    //#region Inicio Controller de Crud PHP de planteldocentes
    public function index()
    {
        // $planteldocentes = planteldocentes::all();
        $user = request()->user();
        $planteldocentes = \App\Models\planteldocentes::where('instituciones_id', $user->instituciones_id)->get();
        foreach ($planteldocentes as $planteldocente) {
            $institucion = \App\Models\instituciones::find($planteldocente->instituciones_id);
            $planteldocente->NombreInstitucion = $institucion ? $institucion->Nombre : null;
        }
        return response()->json(['data' => $planteldocentes]);
    }
    
    
    public function store(Request $request)
    {
        $planteldocentes = $request->all();
        $user = request()->user();
        if ($user->instituciones_id) {
            $planteldocentes['instituciones_id'] = $user->instituciones_id;
        }
        $planteldocentes['Contrasenia'] = Hash::make($request->input('Contrasenia'));
        planteldocentes::insert($planteldocentes);
        return response()->json(['data' => $planteldocentes]);
    }
    
    public function show($id)
    {
        $planteldocentes = planteldocentes::where('id','=',$id)->firstOrFail();
        return response()->json(['data' => $planteldocentes]);
    }
    
    
    public function update(Request $request)
    {
        // $planteldocentes = $request->all();
        // planteldocentes::where('id','=',$request->id)->update($planteldocentes);
        // return response()->json(['data' => $planteldocentes]);

        $planteldocentes = planteldocentes::findOrFail($request->id);
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
            $requestData['Contrasenia'] = $planteldocentes->Contrasenia;
        }

        $planteldocentes->update($requestData);
        return response()->json(['data' => $planteldocentes]);
    }
    
    public function destroy($id)
    {
        planteldocentes::destroy($id);
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
    //#endregion Fin Controller de Crud PHP de planteldocentes
}
