<?php

namespace App\Http\Controllers;

use App\Models\Planteladministrativos;
use Illuminate\Http\Request;

use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;
use Illuminate\Support\Facades\Hash;

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
        $query = \App\Models\Planteladministrativos::query();
        if (!empty($user?->instituciones_id)) {
            $query->where('instituciones_id', $user->instituciones_id);
        }

        $planteladministrativos = $query->get();

        // NombreInstitucion solo para superadmin (usuarioslcchs)
        if (empty($user?->instituciones_id)) {
            foreach ($planteladministrativos as $planteladministrativo) {
                $institucion = \App\Models\Instituciones::find($planteladministrativo->instituciones_id);
                $planteladministrativo->NombreInstitucion = $institucion ? $institucion->Nombre : null;
            }
        } else {
            foreach ($planteladministrativos as $planteladministrativo) {
                $planteladministrativo->NombreInstitucion = null;
            }
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
        
        $planteladministrativos['Contrasenia'] = !empty($request->input('Contrasenia'))
            ? Hash::make($request->input('Contrasenia'))
            : null;
        Planteladministrativos::insert($planteladministrativos);
        return response()->json(['data' => $planteladministrativos]);
    }
    
    public function show($id)
    {
        $user = request()->user();
        $planteladministrativos = Planteladministrativos::query()
            ->where('id', '=', $id)
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('instituciones_id', $user->instituciones_id);
            })
            ->firstOrFail();

        if (!empty($user?->instituciones_id)) {
            $planteladministrativos->NombreInstitucion = null;
        }
        return response()->json(['data' => $planteladministrativos]);
    }
    
    
    public function update(Request $request)
    {
        // $planteladministrativos = $request->all();
        // planteladministrativos::where('id','=',$request->id)->update($planteladministrativos);
        // return response()->json(['data' => $planteladministrativos]);
        $user = $request->user();
        $administrativo = Planteladministrativos::query()
            ->where('id', '=', $request->id)
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('instituciones_id', $user->instituciones_id);
            })
            ->firstOrFail();
        $requestData = $request->all();

        if ($request->has('Contrasenia')) {
            $raw = (string) $request->input('Contrasenia');
            if (trim($raw) !== '' && !\Illuminate\Support\Str::startsWith($raw, ['$2y$', '$2a$', '$argon2', '$bcrypt$'])) {
                $requestData['Contrasenia'] = Hash::make($raw);
            } else {
                // Vacío o ya es un hash → mantener la contraseña actual
                $requestData['Contrasenia'] = $administrativo->Contrasenia;
            }
        } else {
            // No se envió la contraseña, mantener la actual
            $requestData['Contrasenia'] = $administrativo->Contrasenia;
        }

        $administrativo->update($requestData);

        if (!empty($user?->instituciones_id)) {
            $administrativo->NombreInstitucion = null;
        }
        return response()->json(['data' => $administrativo]);
    }
    
    public function destroy($id)
    {
        $user = request()->user();
        $row = Planteladministrativos::query()
            ->where('id', '=', $id)
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('instituciones_id', $user->instituciones_id);
            })
            ->firstOrFail();
        $row->delete();
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
    //#endregion Fin Controller de Crud PHP de planteladministrativos
}
