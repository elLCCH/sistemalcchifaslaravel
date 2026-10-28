<?php

namespace App\Http\Controllers;

use App\Models\JuradoAsignacion;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use App\Http\Middleware\UpdateTokenExpiration;

class JuradoAsignacionController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }

    public function index(Request $request)
    {
        $query = JuradoAsignacion::with(['jurado', 'evento']);

        if ($request->has('evento_id')) {
            $query->where('evento_id', $request->input('evento_id'));
        }

        if ($request->has('jurado_id')) {
            $query->where('jurado_id', $request->input('jurado_id'));
        }

        $asignaciones = $query->get();
        return response()->json(['data' => $asignaciones]);
    }

    public function create()
    {
        //
    }

    public function opcionesEvento($eventoId)
    {
        $base = DB::table('estudianteseventos')->where('eventos_id', $eventoId);

        $especialidades = (clone $base)
            ->whereNotNull('Especialidad')->where('Especialidad', '<>', '')
            ->distinct()->orderBy('Especialidad')->pluck('Especialidad');

        $categorias = (clone $base)
            ->whereNotNull('Categoria')->where('Categoria', '<>', '')
            ->distinct()->orderBy('Categoria')->pluck('Categoria');

        return response()->json(['data' => [
            'especialidades' => $especialidades,
            'categorias' => $categorias,
        ]]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'jurado_id' => 'required|exists:jurados,id',
            'evento_id' => 'required|exists:eventos,id',
            'especialidad' => 'required|string|max:100',
            'categoria' => 'required|string|max:100',
        ]);

        if (JuradoAsignacion::where($validated)->exists()) {
            return response()->json(['data' => 'El jurado ya tiene esta asignación'], 422);
        }

        $asignacion = JuradoAsignacion::create($validated);
        $asignacion->load(['jurado', 'evento']);
        return response()->json(['data' => $asignacion], 201);
    }

    public function show(JuradoAsignacion $juradoAsignacion)
    {
        $juradoAsignacion->load(['jurado', 'evento']);
        return response()->json(['data' => $juradoAsignacion]);
    }

    public function edit(JuradoAsignacion $juradoAsignacion)
    {
        //
    }

    public function update(Request $request, JuradoAsignacion $juradoAsignacion)
    {
        $validated = $request->validate([
            'especialidad' => 'sometimes|string|max:100',
            'categoria' => 'sometimes|string|max:100',
        ]);

        $juradoAsignacion->update($validated);
        $juradoAsignacion->load(['jurado', 'evento']);
        return response()->json(['data' => $juradoAsignacion]);
    }

    public function destroy(JuradoAsignacion $juradoAsignacion)
    {
        $juradoAsignacion->delete();
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
}
