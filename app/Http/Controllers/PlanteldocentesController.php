<?php

namespace App\Http\Controllers;

use App\Models\Planteldocentes;
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

        $query = \App\Models\Planteldocentes::query();
        if (!empty($user?->instituciones_id)) {
            $query->where('instituciones_id', $user->instituciones_id);
        }

        $planteldocentes = $query->get();

        if (empty($user?->instituciones_id)) {
            foreach ($planteldocentes as $planteldocente) {
                $institucion = \App\Models\Instituciones::find($planteldocente->instituciones_id);
                $planteldocente->NombreInstitucion = $institucion ? $institucion->Nombre : null;
            }
        } else {
            foreach ($planteldocentes as $planteldocente) {
                $planteldocente->NombreInstitucion = null;
            }
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
        Planteldocentes::insert($planteldocentes);
        return response()->json(['data' => $planteldocentes]);
    }
    
    public function show($id)
    {
        $user = request()->user();
        $planteldocentes = Planteldocentes::query()
            ->where('id', '=', $id)
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('instituciones_id', $user->instituciones_id);
            })
            ->firstOrFail();

        if (!empty($user?->instituciones_id)) {
            $planteldocentes->NombreInstitucion = null;
        }
        return response()->json(['data' => $planteldocentes]);
    }
    
    
    public function update(Request $request)
    {
        // $planteldocentes = $request->all();
        // planteldocentes::where('id','=',$request->id)->update($planteldocentes);
        // return response()->json(['data' => $planteldocentes]);

        $user = $request->user();
        $planteldocentes = Planteldocentes::query()
            ->where('id', '=', $request->id)
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('instituciones_id', $user->instituciones_id);
            })
            ->firstOrFail();
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

        if (!empty($user?->instituciones_id)) {
            $planteldocentes->NombreInstitucion = null;
        }
        return response()->json(['data' => $planteldocentes]);
    }
    
    public function destroy($id)
    {
        $user = request()->user();
        $row = Planteldocentes::query()
            ->where('id', '=', $id)
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('instituciones_id', $user->instituciones_id);
            })
            ->firstOrFail();
        $row->delete();
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
    //#endregion Fin Controller de Crud PHP de planteldocentes
}
