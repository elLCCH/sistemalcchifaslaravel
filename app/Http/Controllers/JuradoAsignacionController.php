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

    private function institucionId(Request $request): ?int
    {
        $id = $request->user()?->instituciones_id;
        return empty($id) ? null : (int) $id;
    }

    // La asignación solo es válida si el jurado y el evento pertenecen a la institución del usuario.
    private function validarAsignacion(Request $request, JuradoAsignacion $a): void
    {
        $instId = $this->institucionId($request);
        if (!$instId) return;
        $juradoOk = \App\Models\Jurado::where('id', $a->jurado_id)->where('instituciones_id', $instId)->exists();
        abort_unless($juradoOk, 404);
    }

    public function index(Request $request)
    {
        $query = JuradoAsignacion::with(['jurado', 'evento']);
        $instId = $this->institucionId($request);
        if ($instId) {
            $query->whereHas('jurado', fn ($q) => $q->where('instituciones_id', $instId));
        }

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

    public function opcionesEvento(Request $request, $eventoId)
    {
        $base = DB::table('estudianteseventos')->where('eventos_id', $eventoId)
            ->when($this->institucionId($request), fn ($q, $i) => $q->where('instituciones_id', $i));

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

        $instId = $this->institucionId($request);
        if ($instId) {
            $juradoOk = \App\Models\Jurado::where('id', $validated['jurado_id'])->where('instituciones_id', $instId)->exists();
            $eventoOk = DB::table('eventos')->where('id', $validated['evento_id'])->where('instituciones_id', $instId)->exists();
            if (!$juradoOk || !$eventoOk) {
                return response()->json(['data' => 'El jurado y el evento deben pertenecer a su institución'], 422);
            }
        }

        if (JuradoAsignacion::where($validated)->exists()) {
            return response()->json(['data' => 'El jurado ya tiene esta asignación'], 422);
        }

        $asignacion = JuradoAsignacion::create($validated);
        $asignacion->load(['jurado', 'evento']);
        return response()->json(['data' => $asignacion], 201);
    }

    public function show(Request $request, JuradoAsignacion $juradoAsignacion)
    {
        $this->validarAsignacion($request, $juradoAsignacion);
        $juradoAsignacion->load(['jurado', 'evento']);
        return response()->json(['data' => $juradoAsignacion]);
    }

    public function edit(JuradoAsignacion $juradoAsignacion)
    {
        //
    }

    public function update(Request $request, JuradoAsignacion $juradoAsignacion)
    {
        $this->validarAsignacion($request, $juradoAsignacion);
        $validated = $request->validate([
            'especialidad' => 'sometimes|string|max:100',
            'categoria' => 'sometimes|string|max:100',
        ]);

        $juradoAsignacion->update($validated);
        $juradoAsignacion->load(['jurado', 'evento']);
        return response()->json(['data' => $juradoAsignacion]);
    }

    public function destroy(Request $request, JuradoAsignacion $juradoAsignacion)
    {
        $this->validarAsignacion($request, $juradoAsignacion);
        $juradoAsignacion->delete();
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
}
