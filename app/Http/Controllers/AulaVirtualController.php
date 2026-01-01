<?php

namespace App\Http\Controllers;

use App\Http\Middleware\UpdateTokenExpiration;
use App\Models\AulaParticipante;
use App\Models\AulaVirtual;
use App\Models\calificaciones;
use App\Models\infoestudiantesifas;
use App\Models\materias;
use App\Models\planteladministrativos;
use App\Models\planteldocentes;
use App\Models\planteldocentesmaterias;
use App\Models\usuarioslcchs;
use App\Models\estudiantesifas;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AulaVirtualController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'No autenticado'], 401);
        }

        $query = AulaVirtual::query();

        // SUPERADMIN (usuarioslcchs): puede listar por institucion si la manda
        if ($user instanceof usuarioslcchs) {
            if ($request->filled('instituciones_id')) {
                $query->where('instituciones_id', (int) $request->get('instituciones_id'));
            }
            return response()->json(['success' => true, 'data' => $query->orderByDesc('id')->get()]);
        }

        // ADMINISTRATIVO: lista por su institución
        if ($user instanceof planteladministrativos) {
            $query->where('instituciones_id', (int) $user->instituciones_id);
            return response()->json(['success' => true, 'data' => $query->orderByDesc('id')->get()]);
        }

        // DOCENTE: lista aulas de sus materias asignadas
        if ($user instanceof planteldocentes) {
            $materiasIds = planteldocentesmaterias::query()
                ->where('planteldocentes_id', (int) $user->id)
                ->pluck('materias_id')
                ->unique()
                ->values()
                ->all();

            $query->where('instituciones_id', (int) $user->instituciones_id);
            if (!empty($materiasIds)) {
                $query->whereIn('materias_id', $materiasIds);
            } else {
                $query->whereRaw('1=0');
            }

            // opcional: crear automáticamente aulas faltantes
            if ($request->boolean('auto_create') && !empty($materiasIds)) {
                foreach ($materiasIds as $materiaId) {
                    AulaVirtual::query()->firstOrCreate(
                        ['instituciones_id' => (int) $user->instituciones_id, 'materias_id' => (int) $materiaId],
                        ['estado' => 'ACTIVO', 'visibilidad' => 'VISIBLE']
                    );
                }
            }

            $data = $query->orderByDesc('id')->get();
            return response()->json(['success' => true, 'data' => $data]);
        }

        // ESTUDIANTE: lista aulas en las que tiene materias (vía calificaciones)
        if ($user instanceof estudiantesifas) {
            $sub = AulaVirtual::query()
                ->join('calificaciones', 'aulas_virtuales.materias_id', '=', 'calificaciones.materias_id')
                ->join('infoestudiantesifas', 'calificaciones.infoestudiantesifas_id', '=', 'infoestudiantesifas.id')
                ->where('infoestudiantesifas.estudiantesifas_id', (int) $user->id)
                ->select('aulas_virtuales.*')
                ->distinct();

            return response()->json(['success' => true, 'data' => $sub->orderByDesc('aulas_virtuales.id')->get()]);
        }

        return response()->json(['success' => false, 'message' => 'Tipo de usuario no soportado'], 403);
    }

    public function show(Request $request, $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'No autenticado'], 401);
        }

        $aula = AulaVirtual::query()->where('id', (int) $id)->first();
        if (!$aula) {
            return response()->json(['success' => false, 'message' => 'Aula no encontrada'], 404);
        }

        // control de institución (excepto superadmin)
        if (($user instanceof planteladministrativos || $user instanceof planteldocentes) && (int) $aula->instituciones_id !== (int) $user->instituciones_id) {
            return response()->json(['success' => false, 'message' => 'No permitido'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'aula' => $aula,
                'participantes_count' => AulaParticipante::query()->where('aulas_virtuales_id', (int) $aula->id)->count(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'No autenticado'], 401);
        }

        if (!($user instanceof planteladministrativos || $user instanceof planteldocentes || $user instanceof usuarioslcchs)) {
            return response()->json(['success' => false, 'message' => 'No permitido'], 403);
        }

        $validated = $request->validate([
            'instituciones_id' => ['nullable', 'integer'],
            'materias_id' => ['required', 'integer'],
            'nombre' => ['nullable', 'string', 'max:150'],
            'descripcion' => ['nullable', 'string'],
            'estado' => ['nullable', 'string', 'max:15'],
            'visibilidad' => ['nullable', 'string', 'max:15'],
        ]);

        $institucionId = null;
        if ($user instanceof usuarioslcchs) {
            $institucionId = (int) ($validated['instituciones_id'] ?? 0);
            if ($institucionId <= 0) {
                return response()->json(['success' => false, 'message' => 'instituciones_id es requerido para superadmin'], 422);
            }
        } else {
            $institucionId = (int) $user->instituciones_id;
        }

        // validar que la materia pertenezca a la institución
        $materiaInstitucionId = materias::query()
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->where('materias.id', (int) $validated['materias_id'])
            ->value('carreras.instituciones_id');

        if (!$materiaInstitucionId) {
            return response()->json(['success' => false, 'message' => 'Materia no encontrada'], 404);
        }
        if ((int) $materiaInstitucionId !== (int) $institucionId) {
            return response()->json(['success' => false, 'message' => 'La materia no pertenece a la institución'], 422);
        }

        $aula = AulaVirtual::query()->firstOrCreate(
            [
                'instituciones_id' => (int) $institucionId,
                'materias_id' => (int) $validated['materias_id'],
            ],
            [
                'nombre' => $validated['nombre'] ?? null,
                'descripcion' => $validated['descripcion'] ?? null,
                'estado' => $validated['estado'] ?? 'ACTIVO',
                'visibilidad' => $validated['visibilidad'] ?? 'VISIBLE',
            ]
        );

        // Si lo crea un docente, registrarlo como participante TITULAR con permisos completos
        if ($user instanceof planteldocentes) {
            AulaParticipante::query()->firstOrCreate(
                [
                    'aulas_virtuales_id' => (int) $aula->id,
                    'tipo' => 'DOCENTE',
                    'planteldocentes_id' => (int) $user->id,
                ],
                [
                    'rol' => 'TITULAR',
                    'puede_publicar' => 1,
                    'puede_calificar' => 1,
                    'puede_administrar' => 1,
                    'estado' => 'ACTIVO',
                    'visibilidad' => 'VISIBLE',
                ]
            );
        }

        // Si lo crea un administrativo, registrarlo como ADMIN con permisos de administración
        if ($user instanceof planteladministrativos) {
            AulaParticipante::query()->firstOrCreate(
                [
                    'aulas_virtuales_id' => (int) $aula->id,
                    'tipo' => 'ADMIN',
                    'planteladministrativos_id' => (int) $user->id,
                ],
                [
                    'rol' => 'ADMIN',
                    'puede_publicar' => 1,
                    'puede_calificar' => 0,
                    'puede_administrar' => 1,
                    'estado' => 'ACTIVO',
                    'visibilidad' => 'VISIBLE',
                ]
            );
        }

        return response()->json(['success' => true, 'data' => $aula, 'message' => 'Aula creada/asegurada'], 201);
    }

    public function update(Request $request, $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'No autenticado'], 401);
        }

        $aula = AulaVirtual::query()->where('id', (int) $id)->first();
        if (!$aula) {
            return response()->json(['success' => false, 'message' => 'Aula no encontrada'], 404);
        }

        if (($user instanceof planteladministrativos || $user instanceof planteldocentes) && (int) $aula->instituciones_id !== (int) $user->instituciones_id) {
            return response()->json(['success' => false, 'message' => 'No permitido'], 403);
        }

        $validated = $request->validate([
            'nombre' => ['nullable', 'string', 'max:150'],
            'descripcion' => ['nullable', 'string'],
            'estado' => ['nullable', 'string', 'max:15'],
            'visibilidad' => ['nullable', 'string', 'max:15'],
        ]);

        $aula->update($validated);

        return response()->json(['success' => true, 'data' => $aula, 'message' => 'Aula actualizada']);
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'No autenticado'], 401);
        }

        $aula = AulaVirtual::query()->where('id', (int) $id)->first();
        if (!$aula) {
            return response()->json(['success' => false, 'message' => 'Aula no encontrada'], 404);
        }

        if (!($user instanceof usuarioslcchs)) {
            return response()->json(['success' => false, 'message' => 'Solo superadmin puede eliminar aulas'], 403);
        }

        $aula->delete();
        return response()->json(['success' => true, 'message' => 'Aula eliminada']);
    }
}
