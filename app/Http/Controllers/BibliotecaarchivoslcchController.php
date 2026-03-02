<?php

namespace App\Http\Controllers;

use App\Http\Middleware\UpdateTokenExpiration;
use App\Models\Bibliotecaarchivoslcch;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class BibliotecaarchivoslcchController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class])->except(['indexPublic']);
    }

    /**
     * Endpoint público (sin autenticación) para DOCUMENTOS_PUBLICOS y NAVEGADOR.
     */
    public function indexPublic(Request $request)
    {
        $categoriaParam = strtoupper(trim((string) $request->query('categoria')));

        if (!in_array($categoriaParam, ['DOCUMENTOS_PUBLICOS', 'NAVEGADOR'])) {
            return response()->json(['error' => 'Categoría no permitida en acceso público'], 403);
        }

        $query = Bibliotecaarchivoslcch::query()
            ->leftJoin('instituciones', 'bibliotecaarchivoslcch.institucion_id', '=', 'instituciones.id')
            ->select('bibliotecaarchivoslcch.*', 'instituciones.Logo as institucion_logo', 'instituciones.Nombre as institucion_nombre')
            ->where('bibliotecaarchivoslcch.categoria', $categoriaParam)
            ->where('bibliotecaarchivoslcch.visibilidad', 'VISIBLE')
            ->where('bibliotecaarchivoslcch.estado', 'ACTIVO')
            ->orderByDesc('bibliotecaarchivoslcch.fecha')
            ->orderByDesc('bibliotecaarchivoslcch.id');

        return response()->json(['data' => $query->get()]);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $categoriaParam = strtoupper(trim((string) $request->query('categoria')));
        $perPage = max(1, min((int) ($request->query('per_page') ?? 10), 20));
        $search = trim((string) $request->query('search'));
        $anio = trim((string) $request->query('anio'));

        // Para DOCUMENTOS_PUBLICOS y NAVEGADOR: mezclar TODAS las instituciones y traer logo.
        if (in_array($categoriaParam, ['DOCUMENTOS_PUBLICOS', 'NAVEGADOR'])) {
            $query = Bibliotecaarchivoslcch::query()
                ->leftJoin('instituciones', 'bibliotecaarchivoslcch.institucion_id', '=', 'instituciones.id')
                ->select('bibliotecaarchivoslcch.*', 'instituciones.Logo as institucion_logo', 'instituciones.Nombre as institucion_nombre')
                ->where('bibliotecaarchivoslcch.categoria', $categoriaParam)
                ->where('bibliotecaarchivoslcch.visibilidad', 'VISIBLE')
                ->where('bibliotecaarchivoslcch.estado', 'ACTIVO');

            if (!empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->where('bibliotecaarchivoslcch.nombre_documento', 'LIKE', "%{$search}%")
                      ->orWhere('bibliotecaarchivoslcch.publicado_por', 'LIKE', "%{$search}%")
                      ->orWhere('bibliotecaarchivoslcch.descripcion', 'LIKE', "%{$search}%")
                      ->orWhere('instituciones.Nombre', 'LIKE', "%{$search}%");
                });
            }
            if (!empty($anio)) {
                $query->where('bibliotecaarchivoslcch.fecha', 'LIKE', $anio . '%');
            }

            $paginated = $query->orderByDesc('bibliotecaarchivoslcch.fecha')
                ->orderByDesc('bibliotecaarchivoslcch.id')
                ->paginate($perPage);

            return response()->json([
                'data'         => $paginated->items(),
                'total'        => $paginated->total(),
                'per_page'     => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
            ]);
        }

        // ==== Consulta normal por institución ====
        $isDocente = $this->esDocente($user);
        $nombreDocente = '';
        if ($isDocente) {
            $nombreDocente = trim(($user->Apellidos ?? '') . ' ' . ($user->Nombres ?? ''));
        }

        $validCats = ['PLAN', 'DOCUMENTOS_ADMINISTRATIVOS'];

        // Closure para aplicar las condiciones base (institución, visible, activo)
        $applyBase = function ($q) use ($user, $request) {
            if (!empty($user?->instituciones_id)) {
                $q->where('institucion_id', (int) $user->instituciones_id);
            } elseif ($request->query('institucion_id')) {
                $q->where('institucion_id', (int) $request->query('institucion_id'));
            }
            $q->where('visibilidad', 'VISIBLE')->where('estado', 'ACTIVO');
        };

        // Closure para la condición "TODOS" de docentes: ve sólo sus PLAN + todo DOCUMENTOS_ADMINISTRATIVOS
        $applyDocenteTodos = function ($q) use ($isDocente, $nombreDocente) {
            if ($isDocente && !empty($nombreDocente)) {
                $q->where(function ($sub) use ($nombreDocente) {
                    $sub->where('categoria', '!=', 'PLAN')
                        ->orWhere('publicado_por', $nombreDocente);
                });
            }
        };

        // --- Conteo por categoría ---
        $categoryCounts = [];
        foreach ($validCats as $cat) {
            $cq = Bibliotecaarchivoslcch::query();
            $applyBase($cq);
            $cq->where('categoria', $cat);
            if ($isDocente && $cat === 'PLAN' && !empty($nombreDocente)) {
                $cq->where('publicado_por', $nombreDocente);
            }
            $categoryCounts[$cat] = $cq->count();
        }
        $categoryCounts['TODOS'] = array_sum($categoryCounts);

        // --- Años disponibles ---
        $yq = Bibliotecaarchivoslcch::query();
        $applyBase($yq);
        $yq->whereIn('categoria', $validCats);
        $applyDocenteTodos($yq);
        $years = $yq->selectRaw("DISTINCT LEFT(COALESCE(fecha, created_at), 4) as anio")
            ->whereNotNull('fecha')
            ->pluck('anio')
            ->filter()
            ->sort()
            ->reverse()
            ->values()
            ->toArray();

        // --- Consulta principal paginada ---
        $query = Bibliotecaarchivoslcch::query();
        $applyBase($query);

        if (!empty($categoriaParam) && $categoriaParam !== 'TODOS' && in_array($categoriaParam, $validCats)) {
            $query->where('categoria', $categoriaParam);
            if ($isDocente && $categoriaParam === 'PLAN' && !empty($nombreDocente)) {
                $query->where('publicado_por', $nombreDocente);
            }
        } else {
            $query->whereIn('categoria', $validCats);
            $applyDocenteTodos($query);
        }

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('nombre_documento', 'LIKE', "%{$search}%")
                  ->orWhere('publicado_por', 'LIKE', "%{$search}%")
                  ->orWhere('descripcion', 'LIKE', "%{$search}%")
                  ->orWhere('dirigido', 'LIKE', "%{$search}%");
            });
        }

        if (!empty($anio)) {
            $query->where('fecha', 'LIKE', $anio . '%');
        }

        $paginated = $query->orderByDesc('fecha')->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'data'            => $paginated->items(),
            'total'           => $paginated->total(),
            'per_page'        => $paginated->perPage(),
            'current_page'    => $paginated->currentPage(),
            'last_page'       => $paginated->lastPage(),
            'category_counts' => $categoryCounts,
            'available_years' => $years,
        ]);
    }

    public function show(int $id, Request $request)
    {
        $user = $request->user();
        $item = Bibliotecaarchivoslcch::find($id);

        if (!$item) {
            return response()->json(['error' => 'Documento no encontrado'], 404);
        }

        if (!empty($user?->instituciones_id) && (int) $item->institucion_id !== (int) $user->instituciones_id) {
            return response()->json(['error' => 'Documento fuera de su institución'], 403);
        }

        return response()->json(['data' => $item]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        // Si es docente, solo puede crear documentos con categoria=PLAN.
        if ($this->esDocente($user)) {
            $request->merge(['categoria' => 'PLAN']);
        }

        $validated = $request->validate([
            'institucion_id' => ['nullable', 'integer'],
            'categoria' => ['required', 'string', 'max:80', 'in:PLAN,DOCUMENTOS_ADMINISTRATIVOS,DOCUMENTOS_PUBLICOS,NAVEGADOR'],
            'nombre_documento' => ['required', 'string', 'max:150'],
            'fecha' => ['nullable', 'date'],
            'archivo' => ['required', 'string', 'max:300'],
            'estado' => ['nullable', 'string', 'max:15'],
            'visibilidad' => ['nullable', 'string', 'max:15'],
            'publicado_por' => ['nullable', 'string', 'max:120'],
            'dirigido' => ['nullable', 'string', 'max:120'],
            'descripcion' => ['nullable', 'string'],
        ]);

        // Si el usuario tiene institución, forzarla.
        if (!empty($user?->instituciones_id)) {
            $validated['institucion_id'] = (int) $user->instituciones_id;
        }

        if (empty($validated['institucion_id'])) {
            return response()->json(['error' => 'institucion_id es requerido'], 422);
        }

        // Auto-llenar publicado_por y fecha para TODOS los roles.
        $validated['fecha'] = now()->toDateString();
        $validated['publicado_por'] = trim(($user->Apellidos ?? '') . ' ' . ($user->Nombres ?? ''));

        // Para docentes, forzar dirigido.
        if ($this->esDocente($user)) {
            $validated['dirigido'] = 'PLANTEL INSTITUCIONAL';
        }

        $item = Bibliotecaarchivoslcch::create($validated);

        return response()->json(['data' => $item], 201);
    }

    public function update(Request $request, int $id)
    {
        $user = $request->user();
        $item = Bibliotecaarchivoslcch::find($id);

        if (!$item) {
            return response()->json(['error' => 'Documento no encontrado'], 404);
        }

        if (!empty($user?->instituciones_id) && (int) $item->institucion_id !== (int) $user->instituciones_id) {
            return response()->json(['error' => 'Documento fuera de su institución'], 403);
        }

        // Docente solo puede editar sus propios PLAN.
        if ($this->esDocente($user)) {
            if (strtoupper(trim($item->categoria ?? '')) !== 'PLAN') {
                return response()->json(['error' => 'No tiene permiso para editar este documento'], 403);
            }
            $nombreDocente = trim(($user->Apellidos ?? '') . ' ' . ($user->Nombres ?? ''));
            if (trim($item->publicado_por ?? '') !== $nombreDocente) {
                return response()->json(['error' => 'Solo puede editar sus propios planes'], 403);
            }
        }

        $validated = $request->validate([
            'categoria' => ['nullable', 'string', 'max:80'],
            'nombre_documento' => ['nullable', 'string', 'max:150'],
            'fecha' => ['nullable', 'date'],
            'archivo' => ['nullable', 'string', 'max:300'],
            'estado' => ['nullable', 'string', 'max:15'],
            'visibilidad' => ['nullable', 'string', 'max:15'],
            'publicado_por' => ['nullable', 'string', 'max:120'],
            'dirigido' => ['nullable', 'string', 'max:120'],
            'descripcion' => ['nullable', 'string'],
        ]);

        // Docente no puede cambiar la categoría.
        if ($this->esDocente($user)) {
            unset($validated['categoria']);
        }

        // Siempre sobrescribir publicado_por y fecha al editar.
        $validated['fecha'] = now()->toDateString();
        $validated['publicado_por'] = trim(($user->Apellidos ?? '') . ' ' . ($user->Nombres ?? ''));

        // No permitir cambiar institución desde update.
        unset($validated['institucion_id']);

        $item->fill($validated);
        $item->save();

        return response()->json(['data' => $item]);
    }

    public function destroy(int $id, Request $request)
    {
        $user = $request->user();
        $item = Bibliotecaarchivoslcch::find($id);

        if (!$item) {
            return response()->json(['error' => 'Documento no encontrado'], 404);
        }

        if (!empty($user?->instituciones_id) && (int) $item->institucion_id !== (int) $user->instituciones_id) {
            return response()->json(['error' => 'Documento fuera de su institución'], 403);
        }

        // Docente solo puede eliminar sus propios PLAN.
        if ($this->esDocente($user)) {
            if (strtoupper(trim($item->categoria ?? '')) !== 'PLAN') {
                return response()->json(['error' => 'No tiene permiso para eliminar este documento'], 403);
            }
            $nombreDocente = trim(($user->Apellidos ?? '') . ' ' . ($user->Nombres ?? ''));
            if (trim($item->publicado_por ?? '') !== $nombreDocente) {
                return response()->json(['error' => 'Solo puede eliminar sus propios planes'], 403);
            }
        }

        $item->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Determina si el usuario autenticado es docente.
     */
    private function esDocente($user): bool
    {
        if (!$user) return false;
        return $user instanceof \App\Models\Planteldocentes;
    }
}
