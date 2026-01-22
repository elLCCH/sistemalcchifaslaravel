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

        $query = Bibliotecaarchivoslcch::query()
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('institucion_id', (int) $user->instituciones_id);
            })
            ->when(empty($user?->instituciones_id) && $request->query('institucion_id'), function ($q) use ($request) {
                $q->where('institucion_id', (int) $request->query('institucion_id'));
            })
            ->when($request->query('categoria'), function ($q) use ($request) {
                $q->where('categoria', 'LIKE', '%' . trim((string) $request->query('categoria')) . '%');
            })
            ->when($request->query('visibilidad'), function ($q) use ($request) {
                $q->where('visibilidad', (string) $request->query('visibilidad'));
            })
            ->orderByDesc('fecha')
            ->orderByDesc('id');

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

        $validated = $request->validate([
            'institucion_id' => ['nullable', 'integer'],
            'categoria' => ['nullable', 'string', 'max:80'],
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

        $item->delete();

        return response()->json(['success' => true]);
    }
}
