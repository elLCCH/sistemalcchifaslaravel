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
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $categoriaParam = trim((string) $request->query('categoria'));

        // Para DOCUMENTOS_PUBLICOS: mezclar TODAS las instituciones y traer logo.
        if (strtoupper($categoriaParam) === 'DOCUMENTOS_PUBLICOS') {
            $query = Bibliotecaarchivoslcch::query()
                ->leftJoin('instituciones', 'bibliotecaarchivoslcch.institucion_id', '=', 'instituciones.id')
                ->select('bibliotecaarchivoslcch.*', 'instituciones.Logo as institucion_logo', 'instituciones.Nombre as institucion_nombre')
                ->where('bibliotecaarchivoslcch.categoria', 'DOCUMENTOS_PUBLICOS')
                ->when($request->query('visibilidad'), function ($q) use ($request) {
                    $q->where('bibliotecaarchivoslcch.visibilidad', (string) $request->query('visibilidad'));
                })
                ->orderByDesc('bibliotecaarchivoslcch.fecha')
                ->orderByDesc('bibliotecaarchivoslcch.id');

            return response()->json(['data' => $query->get()]);
        }

        $query = Bibliotecaarchivoslcch::query()
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('institucion_id', (int) $user->instituciones_id);
            })
            ->when(empty($user?->instituciones_id) && $request->query('institucion_id'), function ($q) use ($request) {
                $q->where('institucion_id', (int) $request->query('institucion_id'));
            })
            ->when(!empty($categoriaParam), function ($q) use ($categoriaParam) {
                $q->where('categoria', 'LIKE', '%' . $categoriaParam . '%');
            })
            ->when($request->query('visibilidad'), function ($q) use ($request) {
                $q->where('visibilidad', (string) $request->query('visibilidad'));
            })
            ->orderByDesc('fecha')
            ->orderByDesc('id');

        // Si es docente, filtrar solo sus propios planes.
        if ($this->esDocente($user) && strtoupper($categoriaParam) === 'PLAN') {
            $nombreDocente = trim(($user->Apellidos ?? '') . ' ' . ($user->Nombres ?? ''));
            if (!empty($nombreDocente)) {
                $query->where('publicado_por', $nombreDocente);
            }
        }

        return response()->json(['data' => $query->get()]);
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
            'categoria' => ['required', 'string', 'max:80', 'in:PLAN,DOCUMENTOS_ADMINISTRATIVOS,DOCUMENTOS_PUBLICOS'],
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

        // Auto-llenar campos para docentes.
        if ($this->esDocente($user)) {
            $validated['publicado_por'] = trim(($user->Apellidos ?? '') . ' ' . ($user->Nombres ?? ''));
            $validated['dirigido'] = 'PLANTEL INSTITUCIONAL';
            $validated['fecha'] = now()->toDateString();
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
