<?php

namespace App\Http\Controllers;

use App\Http\Middleware\UpdateTokenExpiration;
use App\Models\AulaParticipante;
use App\Models\AulaVirtual;
use App\Models\Calificaciones;
use App\Models\Infoestudiantesifas;
use App\Models\Materias;
use App\Models\Planteladministrativos;
use App\Models\Planteldocentes;
use App\Models\Planteldocentesmaterias;
use App\Models\Usuarioslcchs;
use App\Models\Estudiantesifas;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AulaVirtualController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }

    private function materiaInstitucionId(int $materiaId): ?int
    {
        $inst = Materias::query()
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->where('materias.id', (int) $materiaId)
            ->value('carreras.instituciones_id');

        return $inst ? (int) $inst : null;
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
        // Asignación explícita
        $ok = Planteldocentesmaterias::query()
            ->where('planteldocentes_id', (int) $doc->id)
            ->where('materias_id', (int) $materiaId)
            ->exists();
        if ($ok) return true;

        // Modos especiales: asignación por inscripción
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

    private function canAccessAula($user, AulaVirtual $aula): bool
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

    private function ensureParticipantForActor($user, AulaVirtual $aula): void
    {
        if ($user instanceof Planteldocentes) {
            // Si está asignado a la materia, lo registramos como participante con permisos.
            if (!$this->docenteAsignadoMateria($user, (int) $aula->materias_id)) return;

            AulaParticipante::query()->firstOrCreate(
                [
                    'aulas_virtuales_id' => (int) $aula->id,
                    'tipo' => 'DOCENTE',
                    'planteldocentes_id' => (int) $user->id,
                ],
                [
                    'rol' => 'DOCENTE',
                    'puede_publicar' => 1,
                    'puede_calificar' => 1,
                    'puede_administrar' => 0,
                    'estado' => 'ACTIVO',
                    'visibilidad' => 'VISIBLE',
                ]
            );
        }

        if ($user instanceof Planteladministrativos) {
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
    }

    public function byMateria(Request $request, $materiaId)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'No autenticado'], 401);
        }

        $materiaId = (int) $materiaId;
        if ($materiaId <= 0) {
            return response()->json(['success' => false, 'message' => 'Materia inválida'], 422);
        }

        $materiaInst = $this->materiaInstitucionId($materiaId);
        if (!$materiaInst) {
            return response()->json(['success' => false, 'message' => 'Materia no encontrada'], 404);
        }

        // SUPERADMIN: puede acceder a cualquier institución
        if ($user instanceof Usuarioslcchs) {
            // ok
        } elseif ($user instanceof Planteladministrativos || $user instanceof Planteldocentes) {
            if ((int) $user->instituciones_id !== (int) $materiaInst) {
                return response()->json(['success' => false, 'message' => 'No permitido'], 403);
            }

            if ($user instanceof Planteldocentes && !$this->docenteAsignadoMateria($user, $materiaId)) {
                return response()->json(['success' => false, 'message' => 'No permitido'], 403);
            }
        } elseif ($user instanceof Estudiantesifas) {
            if (!$this->estudianteInscritoMateria($user, $materiaId, (int) $materiaInst)) {
                return response()->json(['success' => false, 'message' => 'No permitido'], 403);
            }
        } else {
            return response()->json(['success' => false, 'message' => 'Tipo de usuario no soportado'], 403);
        }

        $autoCreate = $request->boolean('auto_create');

        $aula = AulaVirtual::query()
            ->where('instituciones_id', (int) $materiaInst)
            ->where('materias_id', (int) $materiaId)
            ->first();

        // Solo docentes/admin/super pueden autocrear
        if (!$aula && $autoCreate && !($user instanceof Estudiantesifas)) {
            $aula = AulaVirtual::query()->create([
                'instituciones_id' => (int) $materiaInst,
                'materias_id' => (int) $materiaId,
                'estado' => 'ACTIVO',
                'visibilidad' => 'VISIBLE',
            ]);
        }

        if (!$aula) {
            return response()->json(['success' => false, 'message' => 'Aula no encontrada'], 404);
        }

        // Si es docente/admin, asegurar registro en participantes (para publicar/subir)
        $this->ensureParticipantForActor($user, $aula);

        return response()->json([
            'success' => true,
            'data' => [
                'aula' => $aula,
                'participantes_count' => AulaParticipante::query()->where('aulas_virtuales_id', (int) $aula->id)->count(),
            ],
        ]);
    }

    private function enrichAulas($aulas)
    {
        $aulasCollection = collect($aulas);
        $materiasIds = $aulasCollection->pluck('materias_id')->filter()->unique()->values();

        if ($materiasIds->isEmpty()) return $aulasCollection;

        $materias = Materias::query()
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->whereIn('materias.id', $materiasIds)
            ->select(
                'materias.id',
                'materias.Paralelo',
                'materias.Turno',
                'plandeestudios.NombreMateria',
                'plandeestudios.SiglaMateria',
                'plandeestudios.LvlCurso'
            )
            ->get()
            ->keyBy('id');

        return $aulasCollection->map(function ($aula) use ($materias) {
            $m = $materias->get($aula->materias_id);
            $aula->materia_nombre = $m->NombreMateria ?? null;
            $aula->materia_sigla = $m->SiglaMateria ?? null;
            $aula->materia_curso = $m->LvlCurso ?? null;
            $aula->materia_paralelo = $m->Paralelo ?? null;
            $aula->materia_turno = $m->Turno ?? null;
            return $aula;
        });
    }

    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'No autenticado'], 401);
        }

        $query = AulaVirtual::query();

        // SUPERADMIN (Usuarioslcchs): puede listar por institucion si la manda
        if ($user instanceof Usuarioslcchs) {
            if ($request->filled('instituciones_id')) {
                $query->where('instituciones_id', (int) $request->get('instituciones_id'));
            }
            return response()->json(['success' => true, 'data' => $this->enrichAulas($query->orderByDesc('id')->get())]);
        }

        // ADMINISTRATIVO: lista por su institución
        if ($user instanceof Planteladministrativos) {
            $query->where('instituciones_id', (int) $user->instituciones_id);
            return response()->json(['success' => true, 'data' => $this->enrichAulas($query->orderByDesc('id')->get())]);
        }

        // DOCENTE: lista aulas de sus materias asignadas
        if ($user instanceof Planteldocentes) {
            $materiasIds = Planteldocentesmaterias::query()
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
            return response()->json(['success' => true, 'data' => $this->enrichAulas($data)]);
        }

        // ESTUDIANTE: lista aulas en las que tiene materias (vía calificaciones)
        if ($user instanceof Estudiantesifas) {
            $sub = AulaVirtual::query()
                ->join('calificaciones', 'aulas_virtuales.materias_id', '=', 'calificaciones.materias_id')
                ->join('infoestudiantesifas', 'calificaciones.infoestudiantesifas_id', '=', 'infoestudiantesifas.id')
                ->where('infoestudiantesifas.estudiantesifas_id', (int) $user->id)
                ->select('aulas_virtuales.*')
                ->distinct();

            return response()->json(['success' => true, 'data' => $this->enrichAulas($sub->orderByDesc('aulas_virtuales.id')->get())]);
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

        if (!$this->canAccessAula($user, $aula)) {
            return response()->json(['success' => false, 'message' => 'No permitido'], 403);
        }

        $this->ensureParticipantForActor($user, $aula);

        // Enrich with materia info
        $enriched = $this->enrichAulas([$aula]);
        $aulaEnriched = $enriched->first() ?? $aula;

        return response()->json([
            'success' => true,
            'data' => [
                'aula' => $aulaEnriched,
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

        if (!($user instanceof Planteladministrativos || $user instanceof Planteldocentes || $user instanceof Usuarioslcchs)) {
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
        if ($user instanceof Usuarioslcchs) {
            $institucionId = (int) ($validated['instituciones_id'] ?? 0);
            if ($institucionId <= 0) {
                return response()->json(['success' => false, 'message' => 'instituciones_id es requerido para superadmin'], 422);
            }
        } else {
            $institucionId = (int) $user->instituciones_id;
        }

        // validar que la materia pertenezca a la institución
        $materiaInstitucionId = Materias::query()
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
        if ($user instanceof Planteldocentes) {
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
        if ($user instanceof Planteladministrativos) {
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

        if (!$this->canAccessAula($user, $aula)) {
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

        if (!($user instanceof Usuarioslcchs)) {
            return response()->json(['success' => false, 'message' => 'Solo superadmin puede eliminar aulas'], 403);
        }

        $aula->delete();
        return response()->json(['success' => true, 'message' => 'Aula eliminada']);
    }
}
