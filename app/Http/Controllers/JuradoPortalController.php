<?php

namespace App\Http\Controllers;

use App\Models\CalificacionesEvento;
use App\Models\Jurado;
use App\Models\JuradoAsignacion;
use App\Models\RubricasEvento;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

// Endpoints exclusivos para el jurado autenticado (habilidad JURADO(A)).
class JuradoPortalController extends Controller
{
    private function jurado(Request $request): Jurado
    {
        $user = $request->user();
        abort_unless($user instanceof Jurado && $user->activo, 403, 'Sesión de jurado inválida.');
        return $user;
    }

    public function misEventos(Request $request)
    {
        $jurado = $this->jurado($request);

        $asignaciones = JuradoAsignacion::where('jurado_id', $jurado->id)->get();
        $eventos = DB::table('eventos')
            ->whereIn('id', $asignaciones->pluck('evento_id')->unique())
            ->orderByDesc('id')
            ->get()
            ->map(function ($ev) use ($asignaciones) {
                $ev->asignaciones = $asignaciones->where('evento_id', $ev->id)->values();
                return $ev;
            });

        return response()->json(['data' => $eventos]);
    }

    public function tablero(Request $request)
    {
        $jurado = $this->jurado($request);
        $request->validate(['evento_id' => 'required|integer|exists:eventos,id']);
        $eventoId = (int) $request->input('evento_id');

        $asignaciones = JuradoAsignacion::where('jurado_id', $jurado->id)
            ->where('evento_id', $eventoId)->get();

        if ($asignaciones->isEmpty()) {
            return response()->json(['data' => 'No tiene asignaciones en este evento'], 403);
        }

        $participantes = DB::table('estudianteseventos')
            ->where('eventos_id', $eventoId)
            ->where(function ($q) use ($asignaciones) {
                foreach ($asignaciones as $a) {
                    $q->orWhere(fn ($w) => $w->where('Especialidad', $a->especialidad)->where('Categoria', $a->categoria));
                }
            })
            ->orderBy('Especialidad')->orderBy('Categoria')
            ->orderBy('Ap_Paterno')->orderBy('Ap_Materno')->orderBy('Nombres')
            ->get(['id', 'Nombres', 'Ap_Paterno', 'Ap_Materno', 'Carnet', 'Foto', 'Especialidad', 'Categoria', 'EstadoInscripcion', 'NombreInstitucion']);

        $rubricas = RubricasEvento::where('evento_id', $eventoId)
            ->where(function ($q) use ($asignaciones) {
                foreach ($asignaciones as $a) {
                    $q->orWhere(fn ($w) => $w->where('especialidad', $a->especialidad)->where('categoria', $a->categoria));
                }
            })
            ->orderBy('id')->get();

        // Solo las calificaciones propias: el jurado no ve las de otros jurados.
        $calificaciones = CalificacionesEvento::where('jurado_id', $jurado->id)
            ->whereIn('estudiantesevento_id', $participantes->pluck('id'))
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
        $jurado = $this->jurado($request);
        $data = $request->validate([
            'estudiantesevento_id' => 'required|integer|exists:estudianteseventos,id',
            'items' => 'required|array|min:1',
            'items.*.rubrica_id' => 'required|integer',
            'items.*.puntaje_asignado' => ['required', 'regex:/^(-|\d{1,3})$/'],
        ], [
            'items.*.puntaje_asignado.required' => 'Debe calificar todas las rúbricas antes de enviar (use "-" si no corresponde calificar).',
            'items.*.puntaje_asignado.regex' => 'El puntaje debe ser un número entero o "-".',
        ]);

        $est = DB::table('estudianteseventos')->where('id', $data['estudiantesevento_id'])->first();
        $rubricasGrupo = RubricasEvento::where('evento_id', $est->eventos_id)
            ->whereRaw('UPPER(especialidad) = ?', [mb_strtoupper((string) $est->Especialidad)])
            ->whereRaw('UPPER(categoria) = ?', [mb_strtoupper((string) $est->Categoria)])
            ->pluck('id');

        if ($rubricasGrupo->isEmpty()) {
            return response()->json(['data' => 'Este participante no tiene rúbricas definidas'], 422);
        }

        // Una calificación enviada es definitiva: el jurado ya no puede modificarla.
        $yaCalificado = CalificacionesEvento::where('jurado_id', $jurado->id)
            ->where('estudiantesevento_id', $est->id)
            ->whereIn('rubrica_id', $rubricasGrupo)
            ->exists();
        if ($yaCalificado) {
            return response()->json(['data' => 'Este participante ya fue calificado y no puede modificarse'], 422);
        }

        $enviadas = collect($data['items'])->pluck('rubrica_id')->map(fn ($v) => (int) $v)->unique();
        if ($enviadas->count() !== $rubricasGrupo->count() || $rubricasGrupo->diff($enviadas)->isNotEmpty()) {
            return response()->json(['data' => 'Debe calificar todas las rúbricas del participante'], 422);
        }

        // Se fuerza el jurado autenticado; nunca se acepta uno enviado por el cliente.
        $request->merge(['jurado_id' => $jurado->id]);

        return app(CalificacionesEventoController::class)->guardarLote($request);
    }
}
