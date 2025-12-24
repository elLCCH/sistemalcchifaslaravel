<?php

namespace App\Http\Controllers;

use App\Models\infoestudiantesifas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;
class InfoestudiantesifasController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }
    //controllerPHPlcch infoestudiantesifas, $
    //#region Inicio Controller de Crud PHP de infoestudiantesifas
    public function index(Request $request)
    {
        $perPage = (int) $request->query('per_page', 10);
        if ($perPage < 1) {
            $perPage = 10;
        }
        if ($perPage > 200) {
            $perPage = 200;
        }

        $search = trim((string) $request->query('search', ''));
        $sortBy = (string) $request->query('sort_by', 'FechInsc');
        $sortDir = strtolower((string) $request->query('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        // Campos permitidos para ordenar (alias "Anio" se maneja con orderByRaw)
        $allowedSort = [
            'id',
            'FechInsc',
            'NombreInstitucion',
            'Ap_Paterno',
            'Ap_Materno',
            'Nombre',
            'Anio',
        ];
        if (!in_array($sortBy, $allowedSort, true)) {
            $sortBy = 'FechInsc';
        }

        $user = request()->user();

        // Subquery (mismo que en el select) para ordenar por Anio si se requiere
        $anioSubquery = "COALESCE((
            SELECT MAX(a.Anio)
            FROM calificaciones c
            INNER JOIN materias m ON m.id = c.materias_id
            INNER JOIN plandeestudios p ON p.id = m.plandeestudios_id
            INNER JOIN anios a ON a.id = p.anio_id
            WHERE c.infoestudiantesifas_id = infoestudiantesifas.id
        ), 'SIN ASIGNAR')";

        $query = infoestudiantesifas::query()
            ->leftJoin('instituciones', 'infoestudiantesifas.instituciones_id', '=', 'instituciones.id')
            ->leftJoin('estudiantesifas', 'infoestudiantesifas.estudiantesifas_id', '=', 'estudiantesifas.id')
            ->select([
                'infoestudiantesifas.*',
                'instituciones.Nombre as NombreInstitucion',
                'estudiantesifas.Ap_Paterno',
                'estudiantesifas.Ap_Materno',
                'estudiantesifas.Nombre',
                DB::raw($anioSubquery . " as Anio"),
            ])
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('infoestudiantesifas.instituciones_id', $user->instituciones_id);
            })
            ->when($search !== '', function ($q) use ($search) {
                $like = '%' . $search . '%';
                $q->where(function ($qq) use ($like) {
                    $qq->where('estudiantesifas.Ap_Paterno', 'like', $like)
                        ->orWhere('estudiantesifas.Ap_Materno', 'like', $like)
                        ->orWhere('estudiantesifas.Nombre', 'like', $like)
                        ->orWhere('instituciones.Nombre', 'like', $like);
                });
            });

        // Ordenamiento (mapeando alias a columnas reales)
        if ($sortBy === 'NombreInstitucion') {
            $query->orderBy('instituciones.Nombre', $sortDir);
        } elseif ($sortBy === 'Ap_Paterno') {
            $query->orderBy('estudiantesifas.Ap_Paterno', $sortDir);
        } elseif ($sortBy === 'Ap_Materno') {
            $query->orderBy('estudiantesifas.Ap_Materno', $sortDir);
        } elseif ($sortBy === 'Nombre') {
            $query->orderBy('estudiantesifas.Nombre', $sortDir);
        } elseif ($sortBy === 'Anio') {
            $query->orderByRaw($anioSubquery . ' ' . $sortDir);
        } else {
            $query->orderBy('infoestudiantesifas.' . $sortBy, $sortDir);
        }

        // Orden secundario estable
        if ($sortBy !== 'id') {
            $query->orderByDesc('infoestudiantesifas.id');
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }


    public function byEstudiante($estudianteId)
    {
        $user = request()->user();

        $query = infoestudiantesifas::query()
            ->leftJoin('instituciones', 'infoestudiantesifas.instituciones_id', '=', 'instituciones.id')
            ->leftJoin('estudiantesifas', 'infoestudiantesifas.estudiantesifas_id', '=', 'estudiantesifas.id')
            ->select([
                'infoestudiantesifas.*',
                'instituciones.Nombre as NombreInstitucion',
                'estudiantesifas.Ap_Paterno',
                'estudiantesifas.Ap_Materno',
                'estudiantesifas.Nombre',
                DB::raw("COALESCE((
                    SELECT MAX(a.Anio)
                    FROM calificaciones c
                    INNER JOIN materias m ON m.id = c.materias_id
                    INNER JOIN plandeestudios p ON p.id = m.plandeestudios_id
                    INNER JOIN anios a ON a.id = p.anio_id
                    WHERE c.infoestudiantesifas_id = infoestudiantesifas.id
                ), 'SIN ASIGNAR') as Anio"),
            ])
            ->where('infoestudiantesifas.estudiantesifas_id', $estudianteId)
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('infoestudiantesifas.instituciones_id', $user->instituciones_id);
            })
            ->orderByDesc('infoestudiantesifas.FechInsc')
            ->orderByDesc('infoestudiantesifas.id');

        $infoestudiantesifas = $query->get();
        return response()->json(['data' => $infoestudiantesifas]);
    }
    
    
    public function store(Request $request)
    {
        $infoestudiantesifas = $request->all();
        $user = request()->user();
        if ($user->instituciones_id) {
            $infoestudiantesifas['instituciones_id'] = $user->instituciones_id;
        }
        infoestudiantesifas::insert($infoestudiantesifas);
        return response()->json(['data' => $infoestudiantesifas]);
    }
    
    public function show($id)
    {
        $infoestudiantesifas = infoestudiantesifas::where('id','=',$id)->firstOrFail();
        return response()->json(['data' => $infoestudiantesifas]);
    }
    
    
    public function update(Request $request)
    {
        $infoestudiantesifas = $request->all();
        infoestudiantesifas::where('id','=',$request->id)->update($infoestudiantesifas);
        return response()->json(['data' => $infoestudiantesifas]);
    }
    
    public function destroy($id)
    {
        infoestudiantesifas::destroy($id);
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
    //#endregion Fin Controller de Crud PHP de infoestudiantesifas
}
