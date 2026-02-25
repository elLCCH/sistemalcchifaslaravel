<?php

namespace App\Http\Controllers;

use App\Http\Middleware\UpdateTokenExpiration;
use App\Models\Calificaciones;
use App\Models\EntregaTarea;
use App\Models\Infoestudiantesifas;
use App\Models\Materias;
use App\Models\Planteladministrativos;
use App\Models\Planteldocentes;
use App\Models\Planteldocentesmaterias;
use App\Models\Tarea;
use App\Models\Usuarioslcchs;
use App\Models\Estudiantesifas;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class EntregaTareaController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }

    private function modoMateria(int $materiaId): string
    {
        $modo = Materias::query()
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->where('materias.id', (int) $materiaId)
            ->value('plandeestudios.ModoMateria');

        return strtoupper(trim((string) ($modo ?? '')));
    }

    private function docenteAsignadoMateria(Planteldocentes $doc, int $materiaId): bool
    {
        $ok = Planteldocentesmaterias::query()
            ->where('planteldocentes_id', (int) $doc->id)
            ->where('materias_id', (int) $materiaId)
            ->exists();
        if ($ok) return true;

        $modo = $this->modoMateria($materiaId);
        if ($modo === 'MODO INSTRUMENTOS DE ESPECIALIDAD') {
            return Calificaciones::query()
                ->join('infoestudiantesifas as info', 'calificaciones.infoestudiantesifas_id', '=', 'info.id')
                ->where('calificaciones.materias_id', (int) $materiaId)
                ->where('info.planteldocadmins_id', (int) $doc->id)
                ->exists();
        }
        if ($modo === 'MODO PRÁCTICA DE CONJUNTOS') {
            return Calificaciones::query()
                ->join('infoestudiantesifas as info', 'calificaciones.infoestudiantesifas_id', '=', 'info.id')
                ->where('calificaciones.materias_id', (int) $materiaId)
                ->where('info.planteldocadmins_idPC', (int) $doc->id)
                ->exists();
        }
        if ($modo === 'MODO INSTRUMENTO COMPLEMENTARIO') {
            return Calificaciones::query()
                ->join('infoestudiantesifas as info', 'calificaciones.infoestudiantesifas_id', '=', 'info.id')
                ->where('calificaciones.materias_id', (int) $materiaId)
                ->where('info.planteldocadmins_idOtros', (int) $doc->id)
                ->exists();
        }

        return false;
    }

    public function index(Request $request, $tareaId)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'No autenticado'], 401);
        }

        try {
            $tarea = Tarea::query()->with('publicacion.aula')->where('id', (int) $tareaId)->first();
        } catch (\Throwable $e) {
            // \Log::error('EntregaTarea index: error cargando tarea', ['tareaId' => $tareaId, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Error al cargar tarea: ' . $e->getMessage()], 500);
        }

        if (!$tarea) {
            return response()->json(['success' => false, 'message' => 'Tarea no encontrada'], 404);
        }

        $aula = $tarea->publicacion?->aula;
        if (!$aula) {
            return response()->json(['success' => false, 'message' => 'Aula no encontrada'], 404);
        }

        // admins: pueden ver todas las entregas dentro de su institución
        if ($user instanceof Planteladministrativos) {
            if ((int) $user->instituciones_id !== (int) $aula->instituciones_id) {
                return response()->json(['success' => false, 'message' => 'No permitido'], 403);
            }
            $data = $this->entregasConEstudiante((int) $tarea->id);
            return response()->json(['success' => true, 'data' => $data]);
        }

        // docentes: solo si está asignado a la materia del aula
        if ($user instanceof Planteldocentes) {
            if ((int) $user->instituciones_id !== (int) $aula->instituciones_id) {
                return response()->json(['success' => false, 'message' => 'No permitido'], 403);
            }
            if (!$this->docenteAsignadoMateria($user, (int) $aula->materias_id)) {
                return response()->json(['success' => false, 'message' => 'No permitido'], 403);
            }
            $data = $this->entregasConEstudiante((int) $tarea->id);
            return response()->json(['success' => true, 'data' => $data]);
        }

        // superadmin: todo
        if ($user instanceof Usuarioslcchs) {
            $data = $this->entregasConEstudiante((int) $tarea->id);
            return response()->json(['success' => true, 'data' => $data]);
        }

        // estudiante: solo su propia entrega
        if ($user instanceof Estudiantesifas) {
            $infoId = Infoestudiantesifas::query()
                ->where('estudiantesifas_id', (int) $user->id)
                ->where('instituciones_id', (int) $aula->instituciones_id)
                ->orderByDesc('id')
                ->value('id');

            if (!$infoId) {
                return response()->json(['success' => true, 'data' => []]);
            }

            $estaEnMateria = Calificaciones::query()
                ->where('infoestudiantesifas_id', (int) $infoId)
                ->where('materias_id', (int) $aula->materias_id)
                ->exists();

            if (!$estaEnMateria) {
                return response()->json(['success' => false, 'message' => 'No permitido'], 403);
            }

            $row = EntregaTarea::query()
                ->where('tareas_id', (int) $tarea->id)
                ->where('infoestudiantesifas_id', (int) $infoId)
                ->first();

            return response()->json(['success' => true, 'data' => $row ? [$row] : []]);
        }

        return response()->json(['success' => false, 'message' => 'Tipo de usuario no soportado'], 403);
    }

    private function entregasConEstudiante(int $tareaId)
    {
        try {
            $entregas = EntregaTarea::query()
                ->with('calificacion')
                ->where('tareas_id', $tareaId)
                ->orderByDesc('id')
                ->get();
        } catch (\Throwable $e) {
            // \Log::error('EntregaTarea entregasConEstudiante: error', ['tareaId' => $tareaId, 'error' => $e->getMessage()]);
            return collect([]);
        }

        $infoIds = $entregas->pluck('infoestudiantesifas_id')->filter()->unique()->values();

        $infos = Infoestudiantesifas::query()
            ->whereIn('id', $infoIds)
            ->get()
            ->keyBy('id');

        $estudianteIds = $infos->pluck('estudiantesifas_id')->filter()->unique()->values();

        $estudiantes = Estudiantesifas::query()
            ->whereIn('id', $estudianteIds)
            ->get(['id', 'Nombres', 'Apellidos', 'foto'])
            ->keyBy('id');

        return $entregas->map(function ($entrega) use ($infos, $estudiantes) {
            $info = $infos->get($entrega->infoestudiantesifas_id);
            $est  = $info ? $estudiantes->get($info->estudiantesifas_id) : null;

            $entrega->estudiante_nombres   = $est->Nombres ?? null;
            $entrega->estudiante_apellidos  = $est->Apellidos ?? null;
            $entrega->estudiante_foto       = $est->foto ?? null;

            return $entrega;
        });
    }

    public function submit(Request $request, $tareaId)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'No autenticado'], 401);
        }

        if (!($user instanceof Estudiantesifas)) {
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
            $infoId = (int) Infoestudiantesifas::query()
                ->where('estudiantesifas_id', (int) $user->id)
                ->where('instituciones_id', (int) $aula->instituciones_id)
                ->orderByDesc('id')
                ->value('id');
        }

        if ($infoId <= 0) {
            return response()->json(['success' => false, 'message' => 'El estudiante no tiene inscripción válida en esta institución'], 422);
        }

        // validar que el estudiante esté vinculado a la materia (vía calificaciones)
        $estaEnMateria = Calificaciones::query()
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
