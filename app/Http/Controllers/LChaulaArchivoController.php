<?php

namespace App\Http\Controllers;

use App\Http\Middleware\UpdateTokenExpiration;
use App\Models\AulaParticipante;
use App\Models\AulaVirtual;
use App\Models\Archivo;
use App\Models\ArchivoRelacion;
use App\Models\Calificaciones;
use App\Models\EntregaTarea;
use App\Models\Infoestudiantesifas;
use App\Models\Materias;
use App\Models\Planteldocentesmaterias;
use App\Models\PublicacionAula;
use App\Models\Usuarioslcchs;
use App\Models\Planteladministrativos;
use App\Models\Planteldocentes;
use App\Models\Estudiantesifas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Routing\Controller;

class LChaulaArchivoController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }

    private function actorInfo($user): array
    {
        if ($user instanceof Planteldocentes) {
            return ['tipo' => 'PLANTELDOCENTE', 'id' => (int) $user->id];
        }
        if ($user instanceof Planteladministrativos) {
            return ['tipo' => 'ADMIN', 'id' => (int) $user->id];
        }
        if ($user instanceof Estudiantesifas) {
            return ['tipo' => 'ESTUDIANTE', 'id' => (int) $user->id];
        }
        if ($user instanceof Usuarioslcchs) {
            return ['tipo' => 'SUPERADMIN', 'id' => (int) $user->id];
        }
        return ['tipo' => 'OTRO', 'id' => (int) ($user->id ?? 0)];
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

    private function docentePuedePublicar(Planteldocentes $doc, AulaVirtual $aula): bool
    {
        return AulaParticipante::query()
            ->where('aulas_virtuales_id', (int) $aula->id)
            ->where('tipo', 'DOCENTE')
            ->where('planteldocentes_id', (int) $doc->id)
            ->where('puede_publicar', 1)
            ->exists();
    }

    public function listByRelacion(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'No autenticado'], 401);
        }

        $request->validate([
            'relacion_tipo' => ['required', 'string', 'max:30'],
            'relacion_id' => ['required', 'integer'],
        ]);

        $tipo = strtoupper(trim((string) $request->get('relacion_tipo')));
        $id = (int) $request->get('relacion_id');

        if ($tipo === 'PUBLICACION') {
            $pub = PublicacionAula::query()->with('aula')->where('id', (int) $id)->first();
            if (!$pub || !$pub->aula) {
                return response()->json(['success' => false, 'message' => 'Publicación no encontrada'], 404);
            }
            if (!$this->canViewAula($user, $pub->aula)) {
                return response()->json(['success' => false, 'message' => 'No permitido'], 403);
            }
        } elseif ($tipo === 'ENTREGA') {
            $entrega = EntregaTarea::query()->with('tarea.publicacion.aula')->where('id', (int) $id)->first();
            $aula = $entrega?->tarea?->publicacion?->aula;
            if (!$entrega || !$aula) {
                return response()->json(['success' => false, 'message' => 'Entrega no encontrada'], 404);
            }

            if (!$this->canViewAula($user, $aula)) {
                return response()->json(['success' => false, 'message' => 'No permitido'], 403);
            }

            // estudiante: solo puede ver archivos de su entrega
            if ($user instanceof Estudiantesifas) {
                $infoId = (int) Infoestudiantesifas::query()
                    ->where('estudiantesifas_id', (int) $user->id)
                    ->where('instituciones_id', (int) $aula->instituciones_id)
                    ->orderByDesc('id')
                    ->value('id');

                if ($infoId <= 0 || (int) $entrega->infoestudiantesifas_id !== $infoId) {
                    return response()->json(['success' => false, 'message' => 'No permitido'], 403);
                }
            }
        } else {
            return response()->json(['success' => false, 'message' => 'relacion_tipo inválido'], 422);
        }

        $data = ArchivoRelacion::query()
            ->with('archivo')
            ->where('relacion_tipo', $tipo)
            ->where('relacion_id', $id)
            ->orderByDesc('id')
            ->get();

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function upload(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'No autenticado'], 401);
        }

        $validated = $request->validate([
            'file' => ['required', 'file'],
            'relacion_tipo' => ['required', 'string', 'max:30'],
            'relacion_id' => ['required', 'integer'],
        ]);

        $relTipo = strtoupper(trim((string) $validated['relacion_tipo']));
        $relId = (int) $validated['relacion_id'];

        $institucionId = null;
        $pathExtra = '';
        $aula = null;

        if ($relTipo === 'PUBLICACION') {
            $pub = PublicacionAula::query()->with('aula')->where('id', $relId)->first();
            if (!$pub || !$pub->aula) {
                return response()->json(['success' => false, 'message' => 'Publicación no encontrada'], 404);
            }
            $aula = $pub->aula;
            $institucionId = (int) $aula->instituciones_id;
            $pathExtra = 'aulas/' . (int) $pub->aula->id . '/publicaciones/' . (int) $pub->id;
        } elseif ($relTipo === 'ENTREGA') {
            $entrega = EntregaTarea::query()->with('tarea.publicacion.aula')->where('id', $relId)->first();
            $aula = $entrega?->tarea?->publicacion?->aula;
            if (!$entrega || !$aula) {
                return response()->json(['success' => false, 'message' => 'Entrega no encontrada'], 404);
            }
            $institucionId = (int) $aula->instituciones_id;
            $pathExtra = 'aulas/' . (int) $aula->id . '/entregas/' . (int) $entrega->id;
        } else {
            return response()->json(['success' => false, 'message' => 'relacion_tipo inválido'], 422);
        }

        if (!$aula || !$this->canViewAula($user, $aula)) {
            return response()->json(['success' => false, 'message' => 'No permitido'], 403);
        }

        // Reglas por tipo de relación
        if ($relTipo === 'PUBLICACION') {
            if ($user instanceof Estudiantesifas) {
                return response()->json(['success' => false, 'message' => 'No permitido'], 403);
            }
            if ($user instanceof Planteldocentes && !$this->docentePuedePublicar($user, $aula)) {
                return response()->json(['success' => false, 'message' => 'No permitido'], 403);
            }
        }

        if ($relTipo === 'ENTREGA') {
            if (!($user instanceof Estudiantesifas)) {
                return response()->json(['success' => false, 'message' => 'No permitido'], 403);
            }

            // Validar límite de 20MB total por entrega
            $existingSize = (int) \App\Models\ArchivoRelacion::query()
                ->join('archivos', 'archivos_relaciones.archivos_id', '=', 'archivos.id')
                ->where('archivos_relaciones.relacion_tipo', 'ENTREGA')
                ->where('archivos_relaciones.relacion_id', $relId)
                ->sum('archivos.tamano');

            $newSize = (int) ($request->file('file')?->getSize() ?? 0);
            $maxTotal = 20 * 1024 * 1024; // 20 MB

            if (($existingSize + $newSize) > $maxTotal) {
                $usedMb = round($existingSize / 1024 / 1024, 2);
                return response()->json([
                    'success' => false,
                    'message' => "Límite de 20 MB por entrega excedido (ya tienes {$usedMb} MB subidos)."
                ], 422);
            }

            $entrega = EntregaTarea::query()->with('tarea.publicacion.aula')->where('id', $relId)->first();
            $aulaEntrega = $entrega?->tarea?->publicacion?->aula;
            if (!$entrega || !$aulaEntrega) {
                return response()->json(['success' => false, 'message' => 'Entrega no encontrada'], 404);
            }

            $infoId = (int) Infoestudiantesifas::query()
                ->where('estudiantesifas_id', (int) $user->id)
                ->where('instituciones_id', (int) $aulaEntrega->instituciones_id)
                ->orderByDesc('id')
                ->value('id');

            if ($infoId <= 0 || (int) $entrega->infoestudiantesifas_id !== $infoId) {
                return response()->json(['success' => false, 'message' => 'No permitido'], 403);
            }
        }

        $file = $request->file('file');
        if (!$file) {
            return response()->json(['success' => false, 'message' => 'Archivo no enviado'], 400);
        }

        // Capturar metadatos ANTES de mover (por seguridad)
        $fileSize = (int) $file->getSize();
        $fileMime = (string) $file->getClientMimeType();
        $original = $file->getClientOriginalName();

        $baseDir = 'archivos/institucion' . (int) $institucionId . '/lchaula/' . $pathExtra;

        try {
            if (!File::exists(public_path($baseDir))) {
                File::makeDirectory(public_path($baseDir), 0755, true, true);
            }

            $uuid = (string) Str::uuid();
            $stored = $uuid . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $original);

            $file->move(public_path($baseDir), $stored);
        } catch (\Throwable $e) {
            // \Log::error('LChaula upload file move error', ['error' => $e->getMessage(), 'baseDir' => $baseDir]);
            return response()->json(['success' => false, 'message' => 'Error al guardar archivo: ' . $e->getMessage()], 500);
        }

        $actor = $this->actorInfo($user);

        try {
            $archivo = Archivo::query()->create([
                'instituciones_id' => (int) $institucionId,
                'nombre_original' => $original,
                'nombre_almacenado' => $stored,
                'ruta' => $baseDir,
                'tamano' => $fileSize,
                'tipo_mime' => $fileMime,
                'subido_por_tipo' => $actor['tipo'],
                'subido_por_id' => $actor['id'],
                'estado' => 'ACTIVO',
                'visibilidad' => 'VISIBLE',
            ]);

            $rel = ArchivoRelacion::query()->create([
                'archivos_id' => (int) $archivo->id,
                'relacion_tipo' => $relTipo,
                'relacion_id' => $relId,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // \Log::error('LChaula upload DB error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Error al registrar archivo en BD: ' . $e->getMessage()], 500);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'archivo' => $archivo,
                'relacion' => $rel,
                'filePath' => $baseDir . '/' . $stored,
            ],
            'message' => 'Archivo subido',
        ], 201);
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'No autenticado'], 401);
        }

        $archivo = Archivo::query()->where('id', (int) $id)->first();
        if (!$archivo) {
            return response()->json(['success' => false, 'message' => 'Archivo no encontrado'], 404);
        }

        // Resolver relación para autorizar (si no hay relación, bloquear)
        $rel = ArchivoRelacion::query()->where('archivos_id', (int) $archivo->id)->orderByDesc('id')->first();
        if (!$rel) {
            return response()->json(['success' => false, 'message' => 'No permitido'], 403);
        }

        $tipo = strtoupper(trim((string) $rel->relacion_tipo));
        $relId = (int) $rel->relacion_id;

        if ($tipo === 'PUBLICACION') {
            $pub = PublicacionAula::query()->with('aula')->where('id', (int) $relId)->first();
            if (!$pub || !$pub->aula) {
                return response()->json(['success' => false, 'message' => 'No permitido'], 403);
            }
            if (!$this->canViewAula($user, $pub->aula)) {
                return response()->json(['success' => false, 'message' => 'No permitido'], 403);
            }

            if ($user instanceof Planteldocentes && !$this->docentePuedePublicar($user, $pub->aula)) {
                return response()->json(['success' => false, 'message' => 'No permitido'], 403);
            }
            if ($user instanceof Estudiantesifas) {
                return response()->json(['success' => false, 'message' => 'No permitido'], 403);
            }
        } elseif ($tipo === 'ENTREGA') {
            $entrega = EntregaTarea::query()->with('tarea.publicacion.aula')->where('id', (int) $relId)->first();
            $aula = $entrega?->tarea?->publicacion?->aula;
            if (!$entrega || !$aula) {
                return response()->json(['success' => false, 'message' => 'No permitido'], 403);
            }
            if (!$this->canViewAula($user, $aula)) {
                return response()->json(['success' => false, 'message' => 'No permitido'], 403);
            }

            if ($user instanceof Estudiantesifas) {
                if ((string) $archivo->subido_por_tipo !== 'ESTUDIANTE' || (int) $archivo->subido_por_id !== (int) $user->id) {
                    return response()->json(['success' => false, 'message' => 'No permitido'], 403);
                }

                $infoId = (int) Infoestudiantesifas::query()
                    ->where('estudiantesifas_id', (int) $user->id)
                    ->where('instituciones_id', (int) $aula->instituciones_id)
                    ->orderByDesc('id')
                    ->value('id');

                if ($infoId <= 0 || (int) $entrega->infoestudiantesifas_id !== $infoId) {
                    return response()->json(['success' => false, 'message' => 'No permitido'], 403);
                }
            }

            // docentes: solo dentro de su materia (canViewAula ya valida asignación)
            // admins/super: permitido
        } else {
            return response()->json(['success' => false, 'message' => 'No permitido'], 403);
        }

        $fullPath = public_path(trim((string) $archivo->ruta, '/\\') . '/' . (string) $archivo->nombre_almacenado);
        if (File::exists($fullPath)) {
            File::delete($fullPath);
        }

        ArchivoRelacion::query()->where('archivos_id', (int) $archivo->id)->delete();
        $archivo->delete();

        return response()->json(['success' => true, 'message' => 'Archivo eliminado']);
    }
}
