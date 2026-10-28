<?php

namespace App\Http\Controllers;

use App\Models\RubricasEvento;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;

class RubricasEventoController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }

    public function index(Request $request)
    {
        $query = RubricasEvento::with('evento');

        if ($request->has('evento_id')) {
            $query->where('evento_id', $request->input('evento_id'));
        }

        if ($request->has('especialidad')) {
            $query->where('especialidad', $request->input('especialidad'));
        }

        if ($request->has('categoria')) {
            $query->where('categoria', $request->input('categoria'));
        }

        $rubricas = $query->get();
        return response()->json(['data' => $rubricas]);
    }

    public function create()
    {
        //
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'evento_id' => 'required|exists:eventos,id',
            'especialidad' => 'required|string|max:100',
            'categoria' => 'required|string|max:100',
            'criterio' => 'required|string|max:255',
            'puntaje_maximo' => 'required|integer|min:1|max:999',
        ]);

        $existe = RubricasEvento::where('evento_id', $validated['evento_id'])
            ->where('especialidad', $validated['especialidad'])
            ->where('categoria', $validated['categoria'])
            ->where('criterio', $validated['criterio'])
            ->exists();
        if ($existe) {
            return response()->json(['data' => 'Ya existe una rúbrica con ese criterio'], 422);
        }

        $rubrica = RubricasEvento::create($validated);
        $rubrica->load('evento');
        return response()->json(['data' => $rubrica], 201);
    }

    public function show(RubricasEvento $rubricasEvento)
    {
        $rubricasEvento->load('evento');
        return response()->json(['data' => $rubricasEvento]);
    }

    public function edit(RubricasEvento $rubricasEvento)
    {
        //
    }

    public function update(Request $request, RubricasEvento $rubricasEvento)
    {
        $validated = $request->validate([
            'criterio' => 'sometimes|string|max:255',
            'puntaje_maximo' => 'sometimes|integer|min:1|max:999',
            'especialidad' => 'sometimes|string|max:100',
            'categoria' => 'sometimes|string|max:100',
        ]);

        $rubricasEvento->update($validated);
        $rubricasEvento->load('evento');
        return response()->json(['data' => $rubricasEvento]);
    }

    public function destroy(RubricasEvento $rubricasEvento)
    {
        $rubricasEvento->delete();
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
}
