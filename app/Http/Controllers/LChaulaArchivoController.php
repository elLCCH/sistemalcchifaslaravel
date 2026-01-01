<?php

namespace App\Http\Controllers;

use App\Http\Middleware\UpdateTokenExpiration;
use App\Models\Archivo;
use App\Models\ArchivoRelacion;
use App\Models\EntregaTarea;
use App\Models\PublicacionAula;
use App\Models\usuarioslcchs;
use App\Models\planteladministrativos;
use App\Models\planteldocentes;
use App\Models\estudiantesifas;
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
        if ($user instanceof planteldocentes) {
            return ['tipo' => 'PLANTELDOCENTE', 'id' => (int) $user->id];
        }
        if ($user instanceof planteladministrativos) {
            return ['tipo' => 'ADMIN', 'id' => (int) $user->id];
        }
        if ($user instanceof estudiantesifas) {
            return ['tipo' => 'ESTUDIANTE', 'id' => (int) $user->id];
        }
        if ($user instanceof usuarioslcchs) {
            return ['tipo' => 'SUPERADMIN', 'id' => (int) $user->id];
        }
        return ['tipo' => 'OTRO', 'id' => (int) ($user->id ?? 0)];
    }

    public function listByRelacion(Request $request)
    {
        $request->validate([
            'relacion_tipo' => ['required', 'string', 'max:30'],
            'relacion_id' => ['required', 'integer'],
        ]);

        $tipo = strtoupper(trim((string) $request->get('relacion_tipo')));
        $id = (int) $request->get('relacion_id');

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

        if ($relTipo === 'PUBLICACION') {
            $pub = PublicacionAula::query()->with('aula')->where('id', $relId)->first();
            if (!$pub || !$pub->aula) {
                return response()->json(['success' => false, 'message' => 'Publicación no encontrada'], 404);
            }
            $institucionId = (int) $pub->aula->instituciones_id;
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

        // Control básico de institución (evita subidas cruzadas)
        if (($user instanceof planteldocentes || $user instanceof planteladministrativos) && (int) $user->instituciones_id !== (int) $institucionId) {
            return response()->json(['success' => false, 'message' => 'No permitido'], 403);
        }

        $file = $request->file('file');
        if (!$file) {
            return response()->json(['success' => false, 'message' => 'Archivo no enviado'], 400);
        }

        $baseDir = 'archivos/institucion' . (int) $institucionId . '/lchaula/' . $pathExtra;

        if (!File::exists(public_path($baseDir))) {
            File::makeDirectory(public_path($baseDir), 0755, true, true);
        }

        $uuid = (string) Str::uuid();
        $original = $file->getClientOriginalName();
        $stored = $uuid . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $original);

        $file->move(public_path($baseDir), $stored);

        $actor = $this->actorInfo($user);

        $archivo = Archivo::query()->create([
            'instituciones_id' => (int) $institucionId,
            'nombre_original' => $original,
            'nombre_almacenado' => $stored,
            'ruta' => $baseDir,
            'tamano' => (int) $file->getSize(),
            'tipo_mime' => (string) $file->getClientMimeType(),
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

        // control básico de institución
        if (($user instanceof planteldocentes || $user instanceof planteladministrativos) && (int) $user->instituciones_id !== (int) $archivo->instituciones_id) {
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
