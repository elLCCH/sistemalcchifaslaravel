<?php

namespace App\Http\Controllers;

use App\Models\Carreras;
use Illuminate\Http\Request;

use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;
class CarrerasController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }
    //controllerPHPlcch carreras, $
    //#region Inicio Controller de Crud PHP de carreras
    public function index()
    {
        $user = request()->user();

        // Si el usuario tiene instituciones_id => SOLO su institución.
        // Si NO tiene instituciones_id (superadmin usuarioslcchs) => ver todo.
        $query = \App\Models\Carreras::query();
        if (!empty($user?->instituciones_id)) {
            $query->where('instituciones_id', $user->instituciones_id);
        }

        $carreras = $query->get();

        // NombreInstitucion SOLO para superadmins (para gestión multi-institución)
        if (empty($user?->instituciones_id)) {
            foreach ($carreras as $carrera) {
                $institucion = \App\Models\Instituciones::find($carrera->instituciones_id);
                $carrera->NombreInstitucion = $institucion ? $institucion->Nombre : null;
            }
        } else {
            foreach ($carreras as $carrera) {
                $carrera->NombreInstitucion = null;
            }
        }

        return response()->json(['data' => $carreras]);
    }
    
    
    public function store(Request $request)
    {
        $carreras = $request->all();
        $user = request()->user();
        if ($user->instituciones_id) {
            $carreras['instituciones_id'] = $user->instituciones_id;
        }
        Carreras::insert($carreras);
        return response()->json(['data' => $carreras]);
    }
    
    public function show($id)
    {
        $user = request()->user();
        $carreras = Carreras::query()
            ->where('id', '=', $id)
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('instituciones_id', $user->instituciones_id);
            })
            ->firstOrFail();

        if (!empty($user?->instituciones_id)) {
            $carreras->NombreInstitucion = null;
        }
        return response()->json(['data' => $carreras]);
    }
    
    
    public function update(Request $request)
    {
        $user = $request->user();
        $carreras = $request->all();

        $q = Carreras::query()->where('id', '=', $request->id);
        if (!empty($user?->instituciones_id)) {
            $q->where('instituciones_id', $user->instituciones_id);
        }

        $row = $q->firstOrFail();
        $row->update($carreras);

        if (!empty($user?->instituciones_id)) {
            $row->NombreInstitucion = null;
        }

        return response()->json(['data' => $row]);
    }
    
    public function destroy($id)
    {
        $user = request()->user();
        $q = Carreras::query()->where('id', '=', $id);
        if (!empty($user?->instituciones_id)) {
            $q->where('instituciones_id', $user->instituciones_id);
        }

        $row = $q->firstOrFail();
        $row->delete();
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
    //#endregion Fin Controller de Crud PHP de carreras
}
