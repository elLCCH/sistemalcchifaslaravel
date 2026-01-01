<?php

namespace App\Http\Controllers;

use App\Http\Middleware\UpdateTokenExpiration;
use App\Models\AulaParticipante;
use App\Models\Tarea;
use App\Models\planteladministrativos;
use App\Models\planteldocentes;
use App\Models\usuarioslcchs;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class TareaController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }

    private function canEditarTarea($user, $aulaId): bool
    {
        if ($user instanceof usuarioslcchs) {
            return true;
        }

        if ($user instanceof planteladministrativos) {
            return true;
        }

        if ($user instanceof planteldocentes) {
            return AulaParticipante::query()
                ->where('aulas_virtuales_id', (int) $aulaId)
                ->where('tipo', 'DOCENTE')
                ->where('planteldocentes_id', (int) $user->id)
                ->where('puede_publicar', 1)
                ->exists();
        }

        return false;
    }

    public function show(Request $request, $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'No autenticado'], 401);
        }

        $tarea = Tarea::query()->with('publicacion.aula')->where('id', (int) $id)->first();
        if (!$tarea) {
            return response()->json(['success' => false, 'message' => 'Tarea no encontrada'], 404);
        }

        return response()->json(['success' => true, 'data' => $tarea]);
    }

    public function update(Request $request, $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'No autenticado'], 401);
        }

        $tarea = Tarea::query()->with('publicacion.aula')->where('id', (int) $id)->first();
        if (!$tarea) {
            return response()->json(['success' => false, 'message' => 'Tarea no encontrada'], 404);
        }

        $aulaId = (int) ($tarea->publicacion?->aula?->id ?? 0);
        if ($aulaId <= 0) {
            return response()->json(['success' => false, 'message' => 'No se pudo resolver el aula de la tarea'], 422);
        }

        if (!$this->canEditarTarea($user, $aulaId)) {
            return response()->json(['success' => false, 'message' => 'No permitido'], 403);
        }

        $validated = $request->validate([
            'fecha_inicio' => ['nullable', 'date'],
            'fecha_entrega' => ['nullable', 'date'],
            'fecha_cierre' => ['nullable', 'date'],
            'permitir_entrega_tardia' => ['nullable', 'boolean'],
            'limite_tardia_horas' => ['nullable', 'integer'],
            'bloquear_recepcion' => ['nullable', 'string', 'max:15'],
            'puntaje_maximo' => ['nullable', 'integer'],
            'tipo_calificacion' => ['nullable', 'string', 'max:20'],
            'estado' => ['nullable', 'string', 'max:15'],
            'visibilidad' => ['nullable', 'string', 'max:15'],
        ]);

        $tarea->update($validated);

        return response()->json(['success' => true, 'data' => $tarea->fresh(), 'message' => 'Tarea actualizada']);
    }
}
