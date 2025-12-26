<?php

namespace App\Http\Controllers;

use App\Models\inicios;
use Illuminate\Http\Request;

use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;
class IniciosController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }
    //controllerPHPlcch inicios, $
    //#region Inicio Controller de Crud PHP de inicios
    public function index()
    {
        $user = request()->user();
        $institucionId = $user ? ($user->instituciones_id ?? null) : null;

        $query = inicios::query();
        if ($institucionId) {
            $query->where(function ($q) use ($institucionId) {
                $q->whereNull('id_institucion')
                  ->orWhere('id_institucion', $institucionId);
            });
        }

        $Inicio = $query->orderBy('categoria', 'asc')
            ->orderBy('titulo', 'asc')
            ->orderBy('id', 'desc')
            ->orderBy('fecha', 'desc')
            ->get();
        return response()->json(['data' => $Inicio]);
    }
    
    
    public function store(Request $request)
    {
        $user = request()->user();
        $institucionId = $user ? ($user->instituciones_id ?? null) : null;

        $data = $request->all();
        $data['id_institucion'] = $institucionId;

        $created = inicios::create($data);
        return response()->json(['data' => $created]);
    }
    
    public function show($id)
    {
        $user = request()->user();
        $institucionId = $user ? ($user->instituciones_id ?? null) : null;

        $query = inicios::where('id', '=', $id);
        if ($institucionId) {
            $query->where(function ($q) use ($institucionId) {
                $q->whereNull('id_institucion')
                  ->orWhere('id_institucion', $institucionId);
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

        $query = inicios::where('id', '=', $id);
        if ($institucionId) {
            $query->where(function ($q) use ($institucionId) {
                $q->whereNull('id_institucion')
                  ->orWhere('id_institucion', $institucionId);
            });
        }

        $query->update($data);
        $updated = inicios::where('id', '=', $id)->first();
        return response()->json(['data' => $updated]);
    }
    
    public function destroy($id)
    {
        $user = request()->user();
        $institucionId = $user ? ($user->instituciones_id ?? null) : null;

        $query = inicios::where('id', '=', $id);
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
