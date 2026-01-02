<?php

namespace App\Http\Controllers;

use App\Http\Middleware\UpdateTokenExpiration;
use App\Models\AulaParticipante;
use App\Models\CalificacionTarea;
use App\Models\EntregaTarea;
use App\Models\Planteldocentes;
use App\Models\Usuarioslcchs;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class CalificacionTareaController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }

    private function canCalificar($user, $aulaId): bool
    {
        if ($user instanceof Usuarioslcchs) {
            return true;
        }

        if ($user instanceof Planteldocentes) {
            return AulaParticipante::query()
                ->where('aulas_virtuales_id', (int) $aulaId)
                ->where('tipo', 'DOCENTE')
                ->where('planteldocentes_id', (int) $user->id)
                ->where('puede_calificar', 1)
                ->exists();
        }

        return false;
    }

    public function store(Request $request, $entregaId)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'No autenticado'], 401);
        }

        if (!($user instanceof Planteldocentes || $user instanceof Usuarioslcchs)) {
            return response()->json(['success' => false, 'message' => 'Solo docentes pueden calificar'], 403);
        }

        $entrega = EntregaTarea::query()->with('tarea.publicacion.aula')->where('id', (int) $entregaId)->first();
        if (!$entrega) {
            return response()->json(['success' => false, 'message' => 'Entrega no encontrada'], 404);
        }

        $tarea = $entrega->tarea;
        $aula = $tarea?->publicacion?->aula;
        if (!$tarea || !$aula) {
            return response()->json(['success' => false, 'message' => 'No se pudo resolver aula/tarea'], 422);
        }

        if ($user instanceof Planteldocentes && (int) $user->instituciones_id !== (int) $aula->instituciones_id) {
            return response()->json(['success' => false, 'message' => 'No permitido'], 403);
        }

        if (!$this->canCalificar($user, (int) $aula->id)) {
            return response()->json(['success' => false, 'message' => 'No permitido para calificar'], 403);
        }

        $validated = $request->validate([
            'planteldocentes_id' => ['nullable', 'integer'],
            'puntaje_obtenido' => ['nullable', 'integer'],
            'comentario_docente' => ['nullable', 'string'],
            'estado' => ['nullable', 'string', 'max:15'],
            'visibilidad' => ['nullable', 'string', 'max:15'],
        ]);

        if ($user instanceof Usuarioslcchs) {
            $docenteId = (int) ($validated['planteldocentes_id'] ?? 0);
            if ($docenteId <= 0) {
                return response()->json(['success' => false, 'message' => 'planteldocentes_id es requerido para superadmin'], 422);
            }
        }

        if (!is_null($validated['puntaje_obtenido'] ?? null)) {
            $puntaje = (int) $validated['puntaje_obtenido'];
            if ($puntaje < 0 || $puntaje > (int) $tarea->puntaje_maximo) {
                return response()->json(['success' => false, 'message' => 'puntaje_obtenido fuera de rango'], 422);
            }
        }

        $calif = CalificacionTarea::query()
            ->where('entregas_tareas_id', (int) $entrega->id)
            ->first();

        $payload = [
            'planteldocentes_id' => ($user instanceof Planteldocentes) ? (int) $user->id : (int) ($validated['planteldocentes_id'] ?? 0),
            'puntaje_obtenido' => $validated['puntaje_obtenido'] ?? null,
            'comentario_docente' => $validated['comentario_docente'] ?? null,
            'fecha_calificacion' => Carbon::now(),
            'estado' => $validated['estado'] ?? 'ACTIVO',
            'visibilidad' => $validated['visibilidad'] ?? 'VISIBLE',
        ];

        if ($calif) {
            $calif->update($payload);
        } else {
            $payload['entregas_tareas_id'] = (int) $entrega->id;
            $calif = CalificacionTarea::query()->create($payload);
        }

        $entrega->estado = 'CALIFICADO';
        $entrega->save();

        return response()->json(['success' => true, 'data' => $calif, 'message' => 'Entrega calificada']);
    }
}
