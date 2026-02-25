<?php

namespace App\Http\Controllers;

use App\Http\Middleware\UpdateTokenExpiration;
use App\Models\AulaParticipante;
use App\Models\AulaVirtual;
use App\Models\Calificaciones;
use App\Models\Estudiantesifas;
use App\Models\Infoestudiantesifas;
use App\Models\Materias;
use App\Models\Tarea;
use App\Models\Planteladministrativos;
use App\Models\Planteldocentes;
use App\Models\Planteldocentesmaterias;
use App\Models\Usuarioslcchs;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class TareaController extends Controller
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

    private function canEditarTarea($user, AulaVirtual $aula): bool
    {
        if ($user instanceof Usuarioslcchs) {
            return true;
        }

        if ($user instanceof Planteladministrativos) {
            return (int) $user->instituciones_id === (int) $aula->instituciones_id;
        }

        if ($user instanceof Planteldocentes) {
            if (!$this->canViewAula($user, $aula)) return false;
            return AulaParticipante::query()
                ->where('aulas_virtuales_id', (int) $aula->id)
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

        $aula = $tarea->publicacion?->aula;
        if (!$aula) {
            return response()->json(['success' => false, 'message' => 'Aula no encontrada'], 404);
        }

        if (!$this->canViewAula($user, $aula)) {
            return response()->json(['success' => false, 'message' => 'No permitido'], 403);
        }

        // Enriquecer con info de materia
        try {
            $materia = Materias::query()
                ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
                ->where('materias.id', $aula->materias_id)
                ->select(
                    'materias.id',
                    'materias.Paralelo',
                    'materias.Turno',
                    'plandeestudios.NombreMateria',
                    'plandeestudios.SiglaMateria',
                    'plandeestudios.LvlCurso'
                )
                ->first();

            if ($materia) {
                $aula->materia_nombre   = $materia->NombreMateria;
                $aula->materia_sigla    = $materia->SiglaMateria;
                $aula->materia_curso    = $materia->LvlCurso;
                $aula->materia_paralelo = $materia->Paralelo;
                $aula->materia_turno    = $materia->Turno;
            }
        } catch (\Throwable $e) {
            // \Log::warning('Tarea show: enrichment failed', ['error' => $e->getMessage()]);
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

        $aula = $tarea->publicacion?->aula;
        if (!$aula) {
            return response()->json(['success' => false, 'message' => 'No se pudo resolver el aula de la tarea'], 422);
        }

        if (!$this->canEditarTarea($user, $aula)) {
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
