<?php

namespace App\Http\Controllers;

use App\Models\CalificacionesEvento;
use App\Models\RubricasEvento;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;
use Illuminate\Support\Facades\DB;

class CalificacionesEventoController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }

    public function index(Request $request)
    {
        $query = CalificacionesEvento::with(['jurado', 'estudianteEvento', 'rubrica']);

        if ($request->has('jurado_id')) {
            $query->where('jurado_id', $request->input('jurado_id'));
        }

        if ($request->has('estudiantesevento_id')) {
            $query->where('estudiantesevento_id', $request->input('estudiantesevento_id'));
        }

        if ($request->has('evento_id')) {
            $query->whereHas('rubrica', function ($q) {
                $q->where('evento_id', request()->input('evento_id'));
            });
        }

        $calificaciones = $query->get();
        return response()->json(['data' => $calificaciones]);
    }

    public function create()
    {
        //
    }

    private function participantesQuery(Request $request, int $eventoId)
    {
        $user = $request->user();
        $q = DB::table('estudianteseventos')->where('eventos_id', $eventoId);
        if (!empty($user?->instituciones_id)) {
            $q->where('instituciones_id', $user->instituciones_id);
        }
        return $q;
    }

    public function tablero(Request $request)
    {
        $request->validate(['evento_id' => 'required|integer|exists:eventos,id']);
        $eventoId = (int) $request->input('evento_id');
        $esp = $request->input('especialidad');
        $cat = $request->input('categoria');

        $participantes = $this->participantesQuery($request, $eventoId)
            ->when($esp, fn ($q) => $q->where('Especialidad', $esp))
            ->when($cat, fn ($q) => $q->where('Categoria', $cat))
            ->orderBy('Ap_Paterno')->orderBy('Ap_Materno')->orderBy('Nombres')
            ->get(['id', 'Nombres', 'Ap_Paterno', 'Ap_Materno', 'Carnet', 'Foto', 'Especialidad', 'Categoria', 'EstadoInscripcion', 'NombreInstitucion']);

        $rubricas = RubricasEvento::where('evento_id', $eventoId)
            ->when($esp, fn ($q) => $q->where('especialidad', $esp))
            ->when($cat, fn ($q) => $q->where('categoria', $cat))
            ->orderBy('id')->get();

        $asignaciones = \App\Models\JuradoAsignacion::with('jurado:id,nombres,apellidos,carnet,foto,activo')
            ->where('evento_id', $eventoId)
            ->when($esp, fn ($q) => $q->where('especialidad', $esp))
            ->when($cat, fn ($q) => $q->where('categoria', $cat))
            ->get();

        $calificaciones = CalificacionesEvento::whereIn('estudiantesevento_id', $participantes->pluck('id'))
            ->get(['id', 'jurado_id', 'estudiantesevento_id', 'rubrica_id', 'puntaje_asignado', 'observaciones', 'updated_at']);

        return response()->json(['data' => [
            'participantes' => $participantes,
            'rubricas' => $rubricas,
            'asignaciones' => $asignaciones,
            'calificaciones' => $calificaciones,
        ]]);
    }

    public function guardarLote(Request $request)
    {
        $data = $request->validate([
            'jurado_id' => 'required|integer|exists:jurados,id',
            'estudiantesevento_id' => 'required|integer|exists:estudianteseventos,id',
            'items' => 'required|array|min:1',
            'items.*.rubrica_id' => 'required|integer|exists:rubricaseventos,id',
            'items.*.puntaje_asignado' => ['nullable', 'regex:/^(-|\d{1,3})$/'],
            'items.*.observaciones' => 'nullable|string',
        ]);

        $user = $request->user();
        $est = DB::table('estudianteseventos')->where('id', $data['estudiantesevento_id'])
            ->when(!empty($user?->instituciones_id), fn ($q) => $q->where('instituciones_id', $user->instituciones_id))
            ->first();
        if (!$est) {
            return response()->json(['data' => 'Participante no encontrado'], 404);
        }

        $asignado = \App\Models\JuradoAsignacion::where('jurado_id', $data['jurado_id'])
            ->where('evento_id', $est->eventos_id)
            ->where('especialidad', $est->Especialidad)
            ->where('categoria', $est->Categoria)
            ->exists();
        if (!$asignado) {
            return response()->json(['data' => 'El jurado no está asignado a la especialidad/categoría de este participante'], 422);
        }

        $rubricas = RubricasEvento::whereIn('id', collect($data['items'])->pluck('rubrica_id'))->get()->keyBy('id');
        foreach ($data['items'] as $item) {
            $r = $rubricas[$item['rubrica_id']];
            if ((int) $r->evento_id !== (int) $est->eventos_id
                || mb_strtoupper($r->especialidad) !== mb_strtoupper((string) $est->Especialidad)
                || mb_strtoupper($r->categoria) !== mb_strtoupper((string) $est->Categoria)) {
                return response()->json(['data' => "La rúbrica '{$r->criterio}' no corresponde a este participante"], 422);
            }
            if (isset($item['puntaje_asignado']) && is_numeric($item['puntaje_asignado']) && (int) $item['puntaje_asignado'] > (int) $r->puntaje_maximo) {
                return response()->json(['data' => "El puntaje de '{$r->criterio}' no puede exceder {$r->puntaje_maximo}"], 422);
            }
        }

        DB::transaction(function () use ($data) {
            foreach ($data['items'] as $item) {
                $where = [
                    'jurado_id' => $data['jurado_id'],
                    'estudiantesevento_id' => $data['estudiantesevento_id'],
                    'rubrica_id' => $item['rubrica_id'],
                ];
                if (!isset($item['puntaje_asignado'])) {
                    CalificacionesEvento::where($where)->delete();
                    continue;
                }
                CalificacionesEvento::updateOrCreate($where, [
                    'puntaje_asignado' => $item['puntaje_asignado'],
                    'observaciones' => $item['observaciones'] ?? null,
                ]);
            }
        });

        return response()->json(['data' => 'GUARDADO']);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'jurado_id' => 'required|exists:jurados,id',
            'estudiantesevento_id' => 'required|exists:estudianteseventos,id',
            'rubrica_id' => 'required|exists:rubricaseventos,id',
            'puntaje_asignado' => ['required', 'regex:/^(-|\d{1,3})$/'],
            'observaciones' => 'nullable|string',
        ]);

        $rubrica = RubricasEvento::findOrFail($validated['rubrica_id']);
        
        if (is_numeric($validated['puntaje_asignado']) && (int) $validated['puntaje_asignado'] > (int) $rubrica->puntaje_maximo) {
            return response()->json([
                'data' => 'El puntaje asignado no puede exceder el puntaje máximo de ' . $rubrica->puntaje_maximo,
            ], 422);
        }

        $calificacion = CalificacionesEvento::create($validated);
        $calificacion->load(['jurado', 'estudianteEvento', 'rubrica']);
        return response()->json(['data' => $calificacion], 201);
    }

    public function show(CalificacionesEvento $calificacionesEvento)
    {
        $calificacionesEvento->load(['jurado', 'estudianteEvento', 'rubrica']);
        return response()->json(['data' => $calificacionesEvento]);
    }

    public function edit(CalificacionesEvento $calificacionesEvento)
    {
        //
    }

    public function update(Request $request, CalificacionesEvento $calificacionesEvento)
    {
        $validated = $request->validate([
            'puntaje_asignado' => ['sometimes', 'regex:/^(-|\d{1,3})$/'],
            'observaciones' => 'sometimes|nullable|string',
        ]);

        if (isset($validated['puntaje_asignado']) && is_numeric($validated['puntaje_asignado'])) {
            $rubrica = $calificacionesEvento->rubrica;
            if ((int) $validated['puntaje_asignado'] > (int) $rubrica->puntaje_maximo) {
                return response()->json([
                    'data' => 'El puntaje asignado no puede exceder el puntaje máximo de ' . $rubrica->puntaje_maximo,
                ], 422);
            }
        }

        $calificacionesEvento->update($validated);
        $calificacionesEvento->load(['jurado', 'estudianteEvento', 'rubrica']);
        return response()->json(['data' => $calificacionesEvento]);
    }

    public function destroy(CalificacionesEvento $calificacionesEvento)
    {
        $calificacionesEvento->delete();
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }

    public function obtenerCalificacionesPorEstudiante($estudianteEventoId)
    {
        $calificaciones = CalificacionesEvento::where('estudiantesevento_id', $estudianteEventoId)
            ->with(['jurado', 'rubrica'])
            ->get();

        return response()->json(['data' => $calificaciones]);
    }

    public function obtenerCalificacionesPorJurado($juradoId, $eventoId)
    {
        $calificaciones = CalificacionesEvento::whereHas('rubrica', function ($q) use ($eventoId) {
            $q->where('evento_id', $eventoId);
        })
            ->where('jurado_id', $juradoId)
            ->with(['jurado', 'estudianteEvento', 'rubrica'])
            ->get();

        return response()->json(['data' => $calificaciones]);
    }
}