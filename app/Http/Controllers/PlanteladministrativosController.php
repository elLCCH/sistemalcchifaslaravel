<?php

namespace App\Http\Controllers;

use App\Models\planteladministrativos;
use Illuminate\Http\Request;

use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class PlanteladministrativosController extends Controller
{
    // public function __construct() {
    //     $this->middleware(UpdateTokenExpiration::class);
    // }
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }

    //controllerPHPlcch planteladministrativos, $
    //#region Inicio Controller de Crud PHP de planteladministrativos
    public function index()
    {
        // $planteladministrativos = planteladministrativos::all();
        $user = request()->user();
        $planteladministrativos = \App\Models\planteladministrativos::where('instituciones_id', $user->instituciones_id)->get();

        foreach ($planteladministrativos as $planteladministrativo) {
            $institucion = \App\Models\instituciones::find($planteladministrativo->instituciones_id);
            $planteladministrativo->NombreInstitucion = $institucion ? $institucion->Nombre : null;
        }
        return response()->json(['data' => $planteladministrativos]);
    }
    
    
    public function store(Request $request)
    {
        
        $planteladministrativos = $request->all();
        $user = request()->user();
        if ($user->instituciones_id) {
            $planteladministrativos['instituciones_id'] = $user->instituciones_id;
        }
        
        $planteladministrativos['Contrasenia'] = Hash::make($request->input('Contrasenia'));
        planteladministrativos::insert($planteladministrativos);
        return response()->json(['data' => $planteladministrativos]);
    }
    
    public function show($id)
    {
        $planteladministrativos = planteladministrativos::where('id','=',$id)->firstOrFail();
        return response()->json(['data' => $planteladministrativos]);
    }
    
    
    public function update(Request $request)
    {
        // $planteladministrativos = $request->all();
        // planteladministrativos::where('id','=',$request->id)->update($planteladministrativos);
        // return response()->json(['data' => $planteladministrativos]);
        $administrativo = planteladministrativos::findOrFail($request->id);
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
            $requestData['Contrasenia'] = $administrativo->Contrasenia;
        }

        $administrativo->update($requestData);
        return response()->json(['data' => $administrativo]);
    }
    
    public function destroy($id)
    {
        planteladministrativos::destroy($id);
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
    //#endregion Fin Controller de Crud PHP de planteladministrativos
}
