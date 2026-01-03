<?php

namespace App\Http\Controllers;

use App\Models\Inicios;
use Illuminate\Http\Request;

use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;
class IniciosController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class])->except(['index', 'show']);
    }
    //controllerPHPlcch inicios, $
    //#region Inicio Controller de Crud PHP de inicios
    public function index()
    {
        $user = request()->user();
        $institucionId = $user ? ($user->instituciones_id ?? null) : null;

        $query = Inicios::query()
            ->leftJoin('instituciones', 'inicios.id_institucion', '=', 'instituciones.id')
            ->select(
                'inicios.*',
                'instituciones.Nombre as institucion_nombre',
                'instituciones.Logo as institucion_logo'
            );
        if ($institucionId) {
            $query->where(function ($q) use ($institucionId) {
                $q->whereNull('inicios.id_institucion')
                  ->orWhere('inicios.id_institucion', $institucionId);
            });
        }

        $Inicio = $query->orderBy('inicios.categoria', 'asc')
            ->orderBy('inicios.titulo', 'asc')
            ->orderBy('inicios.id', 'desc')
            ->orderBy('inicios.fecha', 'desc')
            ->get();
        return response()->json(['data' => $Inicio]);
    }
    
    
    public function store(Request $request)
    {
        $user = request()->user();
        $institucionId = $user ? ($user->instituciones_id ?? null) : null;

        $data = $request->all();
        $data['id_institucion'] = $institucionId;

        $created = Inicios::create($data);

        $createdWithInstitucion = Inicios::query()
            ->leftJoin('instituciones', 'inicios.id_institucion', '=', 'instituciones.id')
            ->select(
                'inicios.*',
                'instituciones.Nombre as institucion_nombre',
                'instituciones.Logo as institucion_logo'
            )
            ->where('inicios.id', '=', $created->id)
            ->first();

        return response()->json(['data' => $createdWithInstitucion ?? $created]);
    }
    
    public function show($id)
    {
        $user = request()->user();
        $institucionId = $user ? ($user->instituciones_id ?? null) : null;

        $query = Inicios::query()
            ->leftJoin('instituciones', 'inicios.id_institucion', '=', 'instituciones.id')
            ->select(
                'inicios.*',
                'instituciones.Nombre as institucion_nombre',
                'instituciones.Logo as institucion_logo'
            )
            ->where('inicios.id', '=', $id);
        if ($institucionId) {
            $query->where(function ($q) use ($institucionId) {
                $q->whereNull('inicios.id_institucion')
                  ->orWhere('inicios.id_institucion', $institucionId);
            });
        }

        $inicios = $query->firstOrFail();
        return response()->json(['data' => $inicios]);
    }
    
    
    public function update(Request $request, $id)
    {
        $user = request()->user();
        $institucionId = $user ? ($user->instituciones_id ?? null) : null;

        $data = $request->all();
        $data['id_institucion'] = $institucionId;

        $query = Inicios::where('id', '=', $id);
        if ($institucionId) {
            $query->where(function ($q) use ($institucionId) {
                $q->whereNull('id_institucion')
                  ->orWhere('id_institucion', $institucionId);
            });
        }

        $query->update($data);
        $updatedWithInstitucion = Inicios::query()
            ->leftJoin('instituciones', 'inicios.id_institucion', '=', 'instituciones.id')
            ->select(
                'inicios.*',
                'instituciones.Nombre as institucion_nombre',
                'instituciones.Logo as institucion_logo'
            )
            ->where('inicios.id', '=', $id)
            ->first();

        $fallbackUpdated = Inicios::where('id', '=', $id)->first();
        return response()->json(['data' => $updatedWithInstitucion ?? $fallbackUpdated]);
    }
    
    public function destroy($id)
    {
        $user = request()->user();
        $institucionId = $user ? ($user->instituciones_id ?? null) : null;

        $query = Inicios::where('id', '=', $id);
        if ($institucionId) {
            $query->where(function ($q) use ($institucionId) {
                $q->whereNull('id_institucion')
                  ->orWhere('id_institucion', $institucionId);
            });
        }

        $query->delete();
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
    //#endregion Fin Controller de Crud PHP de inicios
}
