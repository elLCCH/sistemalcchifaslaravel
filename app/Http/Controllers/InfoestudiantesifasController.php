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
        $anioId = $request->query('anio_id');

        // Campos permitidos para ordenar (alias "Anio" se maneja con orderByRaw)
        $allowedSort = [
            'id',
            'FechInsc',
            'NombreInstitucion',
            'Ap_Paterno',
            'Ap_Materno',
            'Nombre',
            'Anio',
            'Categoria',
            'Curso_Solicitado',
            'Paralelo_Solicitado',
            'CantidadMateriasAsignadas',
            'Observacion',
            'Verificacion',
        ];
        if (!in_array($sortBy, $allowedSort, true)) {
            $sortBy = 'FechInsc';
        }

        $user = request()->user();
        $isSuperAdmin = empty($user?->instituciones_id);

        // Subquery (mismo que en el select) para ordenar por Anio si se requiere
        $anioSubquery = "COALESCE((
            SELECT MAX(a.Anio)
            FROM calificaciones c
            INNER JOIN materias m ON m.id = c.materias_id
            INNER JOIN plandeestudios p ON p.id = m.plandeestudios_id
            INNER JOIN anios a ON a.id = p.anio_id
            WHERE c.infoestudiantesifas_id = infoestudiantesifas.id
        ), 'SIN ASIGNAR')";

        $carreraSubquery = "(
            SELECT ca.NombreCarrera
            FROM calificaciones c
            INNER JOIN materias m ON m.id = c.materias_id
            INNER JOIN plandeestudios p ON p.id = m.plandeestudios_id
            INNER JOIN carreras ca ON ca.id = p.carreras_id
            INNER JOIN anios a ON a.id = p.anio_id
            WHERE c.infoestudiantesifas_id = infoestudiantesifas.id
            ORDER BY a.Anio DESC, p.id DESC
            LIMIT 1
        )";

        $resolucionSubquery = "(
            SELECT ca.Resolucion
            FROM calificaciones c
            INNER JOIN materias m ON m.id = c.materias_id
            INNER JOIN plandeestudios p ON p.id = m.plandeestudios_id
            INNER JOIN carreras ca ON ca.id = p.carreras_id
            INNER JOIN anios a ON a.id = p.anio_id
            WHERE c.infoestudiantesifas_id = infoestudiantesifas.id
            ORDER BY a.Anio DESC, p.id DESC
            LIMIT 1
        )";

        $query = infoestudiantesifas::query()
            ->leftJoin('instituciones', 'infoestudiantesifas.instituciones_id', '=', 'instituciones.id')
            ->leftJoin('estudiantesifas', 'infoestudiantesifas.estudiantesifas_id', '=', 'estudiantesifas.id')
            ->select([
                'infoestudiantesifas.*',
                $isSuperAdmin ? 'instituciones.Nombre as NombreInstitucion' : DB::raw('NULL as NombreInstitucion'),
                'estudiantesifas.Ap_Paterno',
                'estudiantesifas.Ap_Materno',
                'estudiantesifas.Nombre',
                DB::raw($anioSubquery . " as Anio"),
                DB::raw($carreraSubquery . " as NombreCarrera"),
                DB::raw($resolucionSubquery . " as Resolucion"),
            ])
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('infoestudiantesifas.instituciones_id', $user->instituciones_id);
            })
            ->when($anioId !== null && $anioId !== '' && (int) $anioId > 0, function ($q) use ($anioId) {
                $anioIdInt = (int) $anioId;
                $q->whereExists(function ($qq) use ($anioIdInt) {
                    $qq->select(DB::raw(1))
                        ->from('calificaciones as c')
                        ->join('materias as m', 'm.id', '=', 'c.materias_id')
                        ->join('plandeestudios as p', 'p.id', '=', 'm.plandeestudios_id')
                        ->whereColumn('c.infoestudiantesifas_id', 'infoestudiantesifas.id')
                        ->where('p.anio_id', $anioIdInt);
                });
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
        $isSuperAdmin = empty($user?->instituciones_id);

        $query = infoestudiantesifas::query()
            ->leftJoin('instituciones', 'infoestudiantesifas.instituciones_id', '=', 'instituciones.id')
            ->leftJoin('estudiantesifas', 'infoestudiantesifas.estudiantesifas_id', '=', 'estudiantesifas.id')
            ->select([
                'infoestudiantesifas.*',
                $isSuperAdmin ? 'instituciones.Nombre as NombreInstitucion' : DB::raw('NULL as NombreInstitucion'),
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

    public function pendientesAsignacion(Request $request)
    {
        $perPage = (int) $request->query('per_page', 10);
        if ($perPage < 1) {
            $perPage = 10;
        }
        if ($perPage > 50) {
            $perPage = 50;
        }

        $search = trim((string) $request->query('search', ''));

        $user = request()->user();
        $isSuperAdmin = empty($user?->instituciones_id);

        // Subquery para mostrar gestión por asignaciones (si no hay asignaciones => SIN ASIGNAR)
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
                $isSuperAdmin ? 'instituciones.Nombre as NombreInstitucion' : DB::raw('NULL as NombreInstitucion'),
                'estudiantesifas.Ap_Paterno',
                'estudiantesifas.Ap_Materno',
                'estudiantesifas.Nombre',
                'estudiantesifas.CI',
                'estudiantesifas.Matricula',
                DB::raw($anioSubquery . " as Anio"),
            ])
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('infoestudiantesifas.instituciones_id', $user->instituciones_id);
            })
            // Pendientes = sin ninguna asignación en calificaciones
            ->whereNotExists(function ($qq) {
                $qq->select(DB::raw(1))
                    ->from('calificaciones as c')
                    ->whereColumn('c.infoestudiantesifas_id', 'infoestudiantesifas.id');
            })
            ->when($search !== '', function ($q) use ($search) {
                $like = '%' . $search . '%';
                $q->where(function ($qq) use ($like) {
                    $qq->where('estudiantesifas.Ap_Paterno', 'like', $like)
                        ->orWhere('estudiantesifas.Ap_Materno', 'like', $like)
                        ->orWhere('estudiantesifas.Nombre', 'like', $like)
                        ->orWhere('estudiantesifas.CI', 'like', $like)
                        ->orWhere('estudiantesifas.Matricula', 'like', $like)
                        ->orWhere('instituciones.Nombre', 'like', $like)
                        ->orWhere('infoestudiantesifas.Curso_Solicitado', 'like', $like)
                        ->orWhere('infoestudiantesifas.Paralelo_Solicitado', 'like', $like)
                        ->orWhere('infoestudiantesifas.Turno', 'like', $like);
                });
            })
            ->orderByDesc('infoestudiantesifas.FechInsc')
            ->orderByDesc('infoestudiantesifas.id');

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
        $user = request()->user();
        $infoestudiantesifas = infoestudiantesifas::query()
            ->where('id', '=', $id)
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('instituciones_id', $user->instituciones_id);
            })
            ->firstOrFail();
        return response()->json(['data' => $infoestudiantesifas]);
    }
    
    
    public function update(Request $request)
    {
        $user = $request->user();

        $row = infoestudiantesifas::query()
            ->where('id', '=', $request->id)
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('instituciones_id', $user->instituciones_id);
            })
            ->firstOrFail();

        $payload = $request->all();
        if (!empty($user?->instituciones_id)) {
            $payload['instituciones_id'] = $user->instituciones_id;
        }

        $row->update($payload);
        return response()->json(['data' => $row]);
    }
    
    public function destroy($id)
    {
        $user = request()->user();
        $row = infoestudiantesifas::query()
            ->where('id', '=', $id)
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('instituciones_id', $user->instituciones_id);
            })
            ->firstOrFail();

        $row->delete();
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
    //#endregion Fin Controller de Crud PHP de infoestudiantesifas
}
