<?php

namespace App\Http\Controllers;

use App\Http\Middleware\UpdateTokenExpiration;
use App\Models\AulaParticipante;
use App\Models\AulaVirtual;
use App\Models\Calificaciones;
use App\Models\Estudiantesifas;
use App\Models\Infoestudiantesifas;
use App\Models\Materias;
use App\Models\Planteldocentesmaterias;
use App\Models\PublicacionAula;
use App\Models\Tarea;
use App\Models\Planteladministrativos;
use App\Models\Planteldocentes;
use App\Models\Usuarioslcchs;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PublicacionAulaController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }

    private function actorTipo($user): string
    {
        if ($user instanceof Planteldocentes) {
            return 'PLANTELDOCENTE';
        }
        if ($user instanceof Planteladministrativos) {
            return 'ADMIN';
        }
        if ($user instanceof Usuarioslcchs) {
            return 'SUPERADMIN';
        }
        return 'OTRO';
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

    private function estudianteInscritoMateria(Estudiantesifas $est, int $materiaId, int $institucionId): bool
    {
        $infoId = (int) Infoestudiantesifas::query()
            ->where('estudiantesifas_id', (int) $est->id)
            ->where('instituciones_id', (int) $institucionId)
            ->orderByDesc('id')
            ->value('id');

        if ($infoId <= 0) return false;

        return Calificaciones::query()
            ->where('infoestudiantesifas_id', (int) $infoId)
            ->where('materias_id', (int) $materiaId)
            ->exists();
    }

    private function canViewAula($user, AulaVirtual $aula): bool
    {
        if ($user instanceof Usuarioslcchs) return true;

        $inst = (int) ($aula->instituciones_id ?? 0);
        $materiaId = (int) ($aula->materias_id ?? 0);
        if ($inst <= 0 || $materiaId <= 0) return false;

        if ($user instanceof Planteladministrativos) {
            return (int) $user->instituciones_id === $inst;
        }

        if ($user instanceof Planteldocentes) {
            if ((int) $user->instituciones_id !== $inst) return false;
            return $this->docenteAsignadoMateria($user, $materiaId);
        }

        if ($user instanceof Estudiantesifas) {
            return $this->estudianteInscritoMateria($user, $materiaId, $inst);
        }

        return false;
    }

    private function canPublicar($user, AulaVirtual $aula): bool
    {
        if (!$this->canViewAula($user, $aula)) return false;

        // administrativos: permitido por defecto
        if ($user instanceof Planteladministrativos) {
            return true;
        }

        if ($user instanceof Planteldocentes) {
            return AulaParticipante::query()
                ->where('aulas_virtuales_id', (int) $aula->id)
                ->where('tipo', 'DOCENTE')
                ->where('planteldocentes_id', (int) $user->id)
                ->where('puede_publicar', 1)
                ->exists();
        }

        return false;
    }

    public function index(Request $request, $aulaId)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'No autenticado'], 401);
        }

        $aula = AulaVirtual::query()->where('id', (int) $aulaId)->first();
        if (!$aula) {
            return response()->json(['success' => false, 'message' => 'Aula no encontrada'], 404);
        }

        if (!$this->canViewAula($user, $aula)) {
            return response()->json(['success' => false, 'message' => 'No permitido'], 403);
        }

        $data = PublicacionAula::query()
            ->with('tarea')
            ->where('aulas_virtuales_id', (int) $aula->id)
            ->orderByDesc('id')
            ->get();

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function store(Request $request, $aulaId)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'No autenticado'], 401);
        }

        $aula = AulaVirtual::query()->where('id', (int) $aulaId)->first();
        if (!$aula) {
            return response()->json(['success' => false, 'message' => 'Aula no encontrada'], 404);
        }

        if (!$this->canPublicar($user, $aula)) {
            return response()->json(['success' => false, 'message' => 'No permitido para publicar'], 403);
        }

        if (strtoupper((string) $aula->estado) === 'INACTIVO') {
            return response()->json(['success' => false, 'message' => 'Aula inactiva'], 422);
        }

        $validated = $request->validate([
            'tipo' => ['required', 'string', 'max:20'],
            'titulo' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
            'fecha_publicacion' => ['nullable', 'date'],
            'estado' => ['nullable', 'string', 'max:20'],
            'visibilidad' => ['nullable', 'string', 'max:15'],

            // si tipo=TAREA
            'tarea' => ['nullable', 'array'],
            'tarea.fecha_inicio' => ['nullable', 'date'],
            'tarea.fecha_entrega' => ['nullable', 'date'],
            'tarea.fecha_cierre' => ['nullable', 'date'],
            'tarea.permitir_entrega_tardia' => ['nullable', 'boolean'],
            'tarea.limite_tardia_horas' => ['nullable', 'integer'],
            'tarea.bloquear_recepcion' => ['nullable', 'string', 'max:15'],
            'tarea.puntaje_maximo' => ['nullable', 'integer'],
            'tarea.tipo_calificacion' => ['nullable', 'string', 'max:20'],
            'tarea.estado' => ['nullable', 'string', 'max:15'],
            'tarea.visibilidad' => ['nullable', 'string', 'max:15'],
        ]);

        $tipo = strtoupper(trim((string) $validated['tipo']));

        $pub = PublicacionAula::query()->create([
            'aulas_virtuales_id' => (int) $aula->id,
            'tipo' => $tipo,
            'titulo' => $validated['titulo'],
            'descripcion' => $validated['descripcion'] ?? null,
            'creado_por_tipo' => $this->actorTipo($user),
            'creado_por_id' => (int) $user->id,
            'fecha_publicacion' => isset($validated['fecha_publicacion']) ? Carbon::parse($validated['fecha_publicacion']) : null,
            'estado' => $validated['estado'] ?? 'PUBLICADO',
            'visibilidad' => $validated['visibilidad'] ?? 'VISIBLE',
        ]);

        if ($tipo === 'TAREA') {
            $payload = $validated['tarea'] ?? [];

            Tarea::query()->create([
                'publicaciones_aula_id' => (int) $pub->id,
                'fecha_inicio' => $payload['fecha_inicio'] ?? null,
                'fecha_entrega' => $payload['fecha_entrega'] ?? null,
                'fecha_cierre' => $payload['fecha_cierre'] ?? null,
                'permitir_entrega_tardia' => (int) ($payload['permitir_entrega_tardia'] ?? 0),
                'limite_tardia_horas' => $payload['limite_tardia_horas'] ?? null,
                'bloquear_recepcion' => $payload['bloquear_recepcion'] ?? null,
                'puntaje_maximo' => $payload['puntaje_maximo'] ?? 100,
                'tipo_calificacion' => $payload['tipo_calificacion'] ?? 'PUNTOS',
                'estado' => $payload['estado'] ?? 'ACTIVO',
                'visibilidad' => $payload['visibilidad'] ?? 'VISIBLE',
            ]);
        }

        return response()->json(['success' => true, 'data' => $pub->load('tarea'), 'message' => 'Publicación creada'], 201);
    }

    public function update(Request $request, $aulaId, $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'No autenticado'], 401);
        }

        $aula = AulaVirtual::query()->where('id', (int) $aulaId)->first();
        if (!$aula) {
            return response()->json(['success' => false, 'message' => 'Aula no encontrada'], 404);
        }

        if (!$this->canPublicar($user, $aula)) {
            return response()->json(['success' => false, 'message' => 'No permitido'], 403);
        }

        $pub = PublicacionAula::query()
            ->where('aulas_virtuales_id', (int) $aula->id)
            ->where('id', (int) $id)
            ->first();

        if (!$pub) {
            return response()->json(['success' => false, 'message' => 'Publicación no encontrada'], 404);
        }

        $validated = $request->validate([
            'titulo' => ['nullable', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
            'fecha_publicacion' => ['nullable', 'date'],
            'estado' => ['nullable', 'string', 'max:20'],
            'visibilidad' => ['nullable', 'string', 'max:15'],
        ]);

        $pub->update($validated);

        return response()->json(['success' => true, 'data' => $pub->fresh()->load('tarea'), 'message' => 'Publicación actualizada']);
    }

    public function destroy(Request $request, $aulaId, $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'No autenticado'], 401);
        }

        $aula = AulaVirtual::query()->where('id', (int) $aulaId)->first();
        if (!$aula) {
            return response()->json(['success' => false, 'message' => 'Aula no encontrada'], 404);
        }

        if (!$this->canPublicar($user, $aula)) {
            return response()->json(['success' => false, 'message' => 'No permitido'], 403);
        }

        $pub = PublicacionAula::query()
            ->where('aulas_virtuales_id', (int) $aula->id)
            ->where('id', (int) $id)
            ->first();

        if (!$pub) {
            return response()->json(['success' => false, 'message' => 'Publicación no encontrada'], 404);
        }

        $pub->delete();
        return response()->json(['success' => true, 'message' => 'Publicación eliminada']);
    }
}
