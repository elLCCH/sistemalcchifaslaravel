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
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class])->except(['index', 'show', 'horariosPublicos']);
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
        if (!is_null($institucionId)) {
            $data['id_institucion'] = $institucionId;
        }

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
        if (!is_null($institucionId)) {
            $data['id_institucion'] = $institucionId;
        }

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

    public function horariosPublicos()
    {
        $anio = request()->query('anio');
        $institucionIdFilter = request()->query('institucion_id');

        $rows = Inicios::query()
            ->leftJoin('instituciones', 'inicios.id_institucion', '=', 'instituciones.id')
            ->where('inicios.categoria', '=', 'HORARIO')
            ->when($anio, function ($q) use ($anio) {
                $q->where('inicios.subtitulo', '=', $anio);
            })
            ->when($institucionIdFilter, function ($q) use ($institucionIdFilter) {
                $q->where('inicios.id_institucion', '=', $institucionIdFilter);
            })
            ->where(function ($q) {
                $q->whereNull('inicios.estado')
                  ->orWhere('inicios.estado', '=', 'ACTIVO');
            })
            ->where(function ($q) {
                $q->whereNull('inicios.visibilidad')
                  ->orWhere('inicios.visibilidad', '=', 'VISIBLE');
            })
            ->select(
                'inicios.*',
                'instituciones.id as institucion_id',
                'instituciones.Nombre as institucion_nombre',
                'instituciones.Logo as institucion_logo',
                'instituciones.ColorInstitucional as institucion_color_institucional',
                'instituciones.ColorFuerte as institucion_color_fuerte',
                'instituciones.ColorMedio as institucion_color_medio',
                'instituciones.ColorBajo as institucion_color_bajo'
            )
            ->orderBy('instituciones.Nombre', 'asc')
            ->orderBy('inicios.subtitulo', 'desc')
            ->orderBy('inicios.titulo', 'asc')
            ->orderBy('inicios.id', 'desc')
            ->get();

        $groups = [];
        foreach ($rows as $r) {
            $key = (string) ($r->id_institucion ?? 0);
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'institucion_id' => $r->id_institucion,
                    'institucion_nombre' => $r->institucion_nombre ?? 'SIN INSTITUCIÓN',
                    'institucion_logo' => $r->institucion_logo,
                    'institucion_color_institucional' => $r->institucion_color_institucional,
                    'institucion_color_fuerte' => $r->institucion_color_fuerte,
                    'institucion_color_medio' => $r->institucion_color_medio,
                    'institucion_color_bajo' => $r->institucion_color_bajo,
                    'horarios' => [],
                ];
            }
            $groups[$key]['horarios'][] = $r;
        }

        return response()->json(['data' => array_values($groups)]);
    }
    //#endregion Fin Controller de Crud PHP de inicios
}
