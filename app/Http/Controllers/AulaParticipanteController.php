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
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AulaParticipanteController extends Controller
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

    private function canAdministrarAula($user, AulaVirtual $aula): bool
    {
        if ($user instanceof Usuarioslcchs) {
            return true;
        }

        if (($user instanceof Planteladministrativos || $user instanceof Planteldocentes) && (int) $user->instituciones_id !== (int) $aula->instituciones_id) {
            return false;
        }

        // administrativos: permitido por defecto dentro de su institución
        if ($user instanceof Planteladministrativos) {
            return true;
        }

        // docentes: si es participante con puede_administrar o si está asignado a la materia
        if ($user instanceof Planteldocentes) {
            $esAdminEnAula = AulaParticipante::query()
                ->where('aulas_virtuales_id', (int) $aula->id)
                ->where('tipo', 'DOCENTE')
                ->where('planteldocentes_id', (int) $user->id)
                ->where('puede_administrar', 1)
                ->exists();

            if ($esAdminEnAula) {
                return true;
            }

            return $this->docenteAsignadoMateria($user, (int) $aula->materias_id);
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

        if (!$this->canAdministrarAula($user, $aula)) {
            return response()->json(['success' => false, 'message' => 'No permitido'], 403);
        }

        $data = AulaParticipante::query()
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

        if (!$this->canAdministrarAula($user, $aula)) {
            return response()->json(['success' => false, 'message' => 'No permitido'], 403);
        }

        $validated = $request->validate([
            'tipo' => ['required', 'string', 'max:20'],
            'infoestudiantesifas_id' => ['nullable', 'integer'],
            'planteldocentes_id' => ['nullable', 'integer'],
            'planteladministrativos_id' => ['nullable', 'integer'],

            'rol' => ['nullable', 'string', 'max:30'],
            'puede_publicar' => ['nullable', 'boolean'],
            'puede_calificar' => ['nullable', 'boolean'],
            'puede_administrar' => ['nullable', 'boolean'],
            'estado' => ['nullable', 'string', 'max:15'],
            'visibilidad' => ['nullable', 'string', 'max:15'],
        ]);

        $tipo = strtoupper(trim((string) $validated['tipo']));

        // Validar IDs según tipo
        $infoId = $validated['infoestudiantesifas_id'] ?? null;
        $docId = $validated['planteldocentes_id'] ?? null;
        $admId = $validated['planteladministrativos_id'] ?? null;

        if ($tipo === 'ESTUDIANTE') {
            if (!$infoId) {
                return response()->json(['success' => false, 'message' => 'infoestudiantesifas_id requerido'], 422);
            }

            $inst = Infoestudiantesifas::query()->where('id', (int) $infoId)->value('instituciones_id');
            if (!$inst || (int) $inst !== (int) $aula->instituciones_id) {
                return response()->json(['success' => false, 'message' => 'El estudiante inscrito no pertenece a la institución del aula'], 422);
            }
        } elseif ($tipo === 'DOCENTE') {
            if (!$docId) {
                return response()->json(['success' => false, 'message' => 'planteldocentes_id requerido'], 422);
            }
        } elseif ($tipo === 'ADMIN') {
            if (!$admId) {
                return response()->json(['success' => false, 'message' => 'planteladministrativos_id requerido'], 422);
            }
        } else {
            return response()->json(['success' => false, 'message' => 'tipo inválido'], 422);
        }

        $participante = AulaParticipante::query()->create([
            'aulas_virtuales_id' => (int) $aula->id,
            'tipo' => $tipo,
            'infoestudiantesifas_id' => $infoId,
            'planteldocentes_id' => $docId,
            'planteladministrativos_id' => $admId,
            'rol' => $validated['rol'] ?? null,
            'puede_publicar' => (int) ($validated['puede_publicar'] ?? 0),
            'puede_calificar' => (int) ($validated['puede_calificar'] ?? 0),
            'puede_administrar' => (int) ($validated['puede_administrar'] ?? 0),
            'estado' => $validated['estado'] ?? 'ACTIVO',
            'visibilidad' => $validated['visibilidad'] ?? 'VISIBLE',
        ]);

        return response()->json(['success' => true, 'data' => $participante, 'message' => 'Participante agregado'], 201);
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

        if (!$this->canAdministrarAula($user, $aula)) {
            return response()->json(['success' => false, 'message' => 'No permitido'], 403);
        }

        $row = AulaParticipante::query()
            ->where('aulas_virtuales_id', (int) $aula->id)
            ->where('id', (int) $id)
            ->first();

        if (!$row) {
            return response()->json(['success' => false, 'message' => 'Participante no encontrado'], 404);
        }

        $validated = $request->validate([
            'rol' => ['nullable', 'string', 'max:30'],
            'puede_publicar' => ['nullable', 'boolean'],
            'puede_calificar' => ['nullable', 'boolean'],
            'puede_administrar' => ['nullable', 'boolean'],
            'estado' => ['nullable', 'string', 'max:15'],
            'visibilidad' => ['nullable', 'string', 'max:15'],
        ]);

        $row->update($validated);

        return response()->json(['success' => true, 'data' => $row, 'message' => 'Participante actualizado']);
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

        if (!$this->canAdministrarAula($user, $aula)) {
            return response()->json(['success' => false, 'message' => 'No permitido'], 403);
        }

        $deleted = AulaParticipante::query()
            ->where('aulas_virtuales_id', (int) $aula->id)
            ->where('id', (int) $id)
            ->delete();

        return response()->json(['success' => true, 'data' => (bool) $deleted, 'message' => $deleted ? 'Participante eliminado' : 'No encontrado']);
    }
}
