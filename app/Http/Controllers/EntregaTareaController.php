<?php

namespace App\Http\Controllers;

use App\Http\Middleware\UpdateTokenExpiration;
use App\Models\AulaParticipante;
use App\Models\calificaciones;
use App\Models\EntregaTarea;
use App\Models\infoestudiantesifas;
use App\Models\planteladministrativos;
use App\Models\planteldocentes;
use App\Models\Tarea;
use App\Models\usuarioslcchs;
use App\Models\estudiantesifas;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class EntregaTareaController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }

    public function index(Request $request, $tareaId)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'No autenticado'], 401);
        }

        $tarea = Tarea::query()->with('publicacion.aula')->where('id', (int) $tareaId)->first();
        if (!$tarea) {
            return response()->json(['success' => false, 'message' => 'Tarea no encontrada'], 404);
        }

        $aula = $tarea->publicacion?->aula;
        if (!$aula) {
            return response()->json(['success' => false, 'message' => 'Aula no encontrada'], 404);
        }

        // docentes/admins pueden ver todas las entregas dentro de su institución
        if ($user instanceof planteldocentes || $user instanceof planteladministrativos) {
            if ((int) $user->instituciones_id !== (int) $aula->instituciones_id) {
                return response()->json(['success' => false, 'message' => 'No permitido'], 403);
            }
            $data = EntregaTarea::query()->where('tareas_id', (int) $tarea->id)->orderByDesc('id')->get();
            return response()->json(['success' => true, 'data' => $data]);
        }

        // superadmin: todo
        if ($user instanceof usuarioslcchs) {
            $data = EntregaTarea::query()->where('tareas_id', (int) $tarea->id)->orderByDesc('id')->get();
            return response()->json(['success' => true, 'data' => $data]);
        }

        // estudiante: solo su propia entrega
        if ($user instanceof estudiantesifas) {
            $infoId = infoestudiantesifas::query()
                ->where('estudiantesifas_id', (int) $user->id)
                ->where('instituciones_id', (int) $aula->instituciones_id)
                ->orderByDesc('id')
                ->value('id');

            if (!$infoId) {
                return response()->json(['success' => true, 'data' => []]);
            }

            $row = EntregaTarea::query()
                ->where('tareas_id', (int) $tarea->id)
                ->where('infoestudiantesifas_id', (int) $infoId)
                ->first();

            return response()->json(['success' => true, 'data' => $row ? [$row] : []]);
        }

        return response()->json(['success' => false, 'message' => 'Tipo de usuario no soportado'], 403);
    }

    public function submit(Request $request, $tareaId)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'No autenticado'], 401);
        }

        if (!($user instanceof estudiantesifas)) {
            return response()->json(['success' => false, 'message' => 'Solo estudiantes pueden entregar'], 403);
        }

        $tarea = Tarea::query()->with('publicacion.aula')->where('id', (int) $tareaId)->first();
        if (!$tarea) {
            return response()->json(['success' => false, 'message' => 'Tarea no encontrada'], 404);
        }

        $aula = $tarea->publicacion?->aula;
        if (!$aula) {
            return response()->json(['success' => false, 'message' => 'Aula no encontrada'], 404);
        }

        if (strtoupper((string) $aula->estado) === 'INACTIVO') {
            return response()->json(['success' => false, 'message' => 'Aula inactiva'], 422);
        }

        if (strtoupper((string) $tarea->estado) === 'INACTIVO') {
            return response()->json(['success' => false, 'message' => 'Tarea inactiva'], 422);
        }

        if (strtoupper((string) $tarea->bloquear_recepcion) === 'SI') {
            return response()->json(['success' => false, 'message' => 'Recepción bloqueada'], 422);
        }

        $validated = $request->validate([
            'comentario_estudiante' => ['nullable', 'string'],
            'infoestudiantesifas_id' => ['nullable', 'integer'],
        ]);

        $infoId = (int) ($validated['infoestudiantesifas_id'] ?? 0);
        if ($infoId <= 0) {
            $infoId = (int) infoestudiantesifas::query()
                ->where('estudiantesifas_id', (int) $user->id)
                ->where('instituciones_id', (int) $aula->instituciones_id)
                ->orderByDesc('id')
                ->value('id');
        }

        if ($infoId <= 0) {
            return response()->json(['success' => false, 'message' => 'El estudiante no tiene inscripción válida en esta institución'], 422);
        }

        // validar que el estudiante esté vinculado a la materia (vía calificaciones)
        $estaEnMateria = calificaciones::query()
            ->where('infoestudiantesifas_id', (int) $infoId)
            ->where('materias_id', (int) $aula->materias_id)
            ->exists();

        if (!$estaEnMateria) {
            return response()->json(['success' => false, 'message' => 'El estudiante no está inscrito en esta materia'], 403);
        }

        $now = Carbon::now();

        $inicio = $tarea->fecha_inicio ? Carbon::parse($tarea->fecha_inicio) : null;
        if ($inicio && $now->lt($inicio)) {
            return response()->json(['success' => false, 'message' => 'Aún no inicia el periodo de entrega'], 422);
        }

        $deadline = $tarea->fecha_entrega ? Carbon::parse($tarea->fecha_entrega) : null;
        $cierre = $tarea->fecha_cierre ? Carbon::parse($tarea->fecha_cierre) : ($deadline ? $deadline->copy() : null);

        if ($cierre && $now->gt($cierre)) {
            return response()->json(['success' => false, 'message' => 'La tarea ya está cerrada'], 422);
        }

        $estado = 'ENTREGADO';
        if ($deadline && $now->gt($deadline)) {
            if (!$tarea->permitir_entrega_tardia) {
                return response()->json(['success' => false, 'message' => 'No se permiten entregas tardías'], 422);
            }

            if (!is_null($tarea->limite_tardia_horas)) {
                $max = $deadline->copy()->addHours((int) $tarea->limite_tardia_horas);
                if ($now->gt($max)) {
                    return response()->json(['success' => false, 'message' => 'Se excedió el límite de entrega tardía'], 422);
                }
            }

            $estado = 'ATRASADO';
        }

        $entrega = EntregaTarea::query()
            ->where('tareas_id', (int) $tarea->id)
            ->where('infoestudiantesifas_id', (int) $infoId)
            ->first();

        if ($entrega) {
            $entrega->numero_reentrega = (int) $entrega->numero_reentrega + 1;
            $entrega->estado = $estado;
            $entrega->fecha_entrega = $now;
            $entrega->comentario_estudiante = $validated['comentario_estudiante'] ?? $entrega->comentario_estudiante;
            $entrega->save();
        } else {
            $entrega = EntregaTarea::query()->create([
                'tareas_id' => (int) $tarea->id,
                'infoestudiantesifas_id' => (int) $infoId,
                'estado' => $estado,
                'fecha_entrega' => $now,
                'comentario_estudiante' => $validated['comentario_estudiante'] ?? null,
                'numero_reentrega' => 0,
            ]);
        }

        return response()->json(['success' => true, 'data' => $entrega, 'message' => 'Entrega registrada']);
    }
}
