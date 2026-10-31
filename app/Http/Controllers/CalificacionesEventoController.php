<?php

namespace App\Http\Controllers;

use App\Models\CalificacionesEvento;
use App\Models\RubricasEvento;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;
use Illuminate\Support\Facades\DB;

class CalificacionesEventoController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }

    public function index(Request $request)
    {
        $query = CalificacionesEvento::with(['jurado', 'estudianteEvento', 'rubrica']);

        if ($request->has('jurado_id')) {
            $query->where('jurado_id', $request->input('jurado_id'));
        }

        if ($request->has('estudiantesevento_id')) {
            $query->where('estudiantesevento_id', $request->input('estudiantesevento_id'));
        }

        if ($request->has('nroinstancia')) {
            $query->where('nroinstancia', $request->input('nroinstancia'));
        }

        if ($request->has('evento_id')) {
            $query->whereHas('rubrica', function ($q) {
                $q->where('evento_id', request()->input('evento_id'));
            });
        }

        $calificaciones = $query->get();
        return response()->json(['data' => $calificaciones]);
    }

    public function create()
    {
        //
    }

    private function participantesQuery(Request $request, int $eventoId)
    {
        $user = $request->user();
        $q = DB::table('estudianteseventos')->where('eventos_id', $eventoId)
            ->where(fn ($w) => $w->whereNull('EstadoInscripcion')->orWhere('EstadoInscripcion', '!=', 'RECHAZADO'));
        if (!empty($user?->instituciones_id)) {
            $q->where('instituciones_id', $user->instituciones_id);
        }
        return $q;
    }

    public static function instancia(Request $request): int
    {
        return max(1, (int) $request->input('nroinstancia', 1));
    }

    // La instancia 1 incluye a todos; las siguientes solo a los clasificados.
    public static function enInstancia(int $estudianteEventoId, int $instancia): bool
    {
        return $instancia <= 1
            || DB::table('clasificacioneseventos')
                ->where('estudiantesevento_id', $estudianteEventoId)
                ->where('nroinstancia', $instancia)->exists();
    }

    public static function filtrarInstancia($query, int $instancia)
    {
        if ($instancia > 1) {
            $query->whereIn('id', DB::table('clasificacioneseventos')->where('nroinstancia', $instancia)->select('estudiantesevento_id'));
        }
        return $query;
    }

    public function tablero(Request $request)
    {
        $request->validate(['evento_id' => 'required|integer|exists:eventos,id']);
        $eventoId = (int) $request->input('evento_id');
        $instancia = self::instancia($request);
        $esp = $request->input('especialidad');
        $cat = $request->input('categoria');

        $todosIds = $this->participantesQuery($request, $eventoId)->pluck('id');
        $clasificaciones = DB::table('clasificacioneseventos')->whereIn('estudiantesevento_id', $todosIds)
            ->get(['estudiantesevento_id', 'nroinstancia']);
        $instancias = $clasificaciones->pluck('nroinstancia')->push(1)->unique()->sort()->values();

        $participantes = self::filtrarInstancia($this->participantesQuery($request, $eventoId), $instancia)
            ->when($esp, fn ($q) => $q->where('Especialidad', $esp))
            ->when($cat, fn ($q) => $q->where('Categoria', $cat))
            ->orderBy('Ap_Paterno')->orderBy('Ap_Materno')->orderBy('Nombres')
            ->get(['id', 'Nombres', 'Ap_Paterno', 'Ap_Materno', 'Carnet', 'Foto', 'Especialidad', 'Categoria', 'EstadoInscripcion', 'NombreInstitucion']);

        $rubricas = RubricasEvento::where('evento_id', $eventoId)
            ->when($esp, fn ($q) => $q->where('especialidad', $esp))
            ->when($cat, fn ($q) => $q->where('categoria', $cat))
            ->orderBy('id')->get();

        $asignaciones = \App\Models\JuradoAsignacion::with('jurado:id,nombres,apellidos,carnet,foto,activo')
            ->where('evento_id', $eventoId)
            ->when($esp, fn ($q) => $q->where('especialidad', $esp))
            ->when($cat, fn ($q) => $q->where('categoria', $cat))
            ->get();

        $calificaciones = CalificacionesEvento::whereIn('estudiantesevento_id', $participantes->pluck('id'))
            ->where('nroinstancia', $instancia)
            ->get(['id', 'jurado_id', 'estudiantesevento_id', 'rubrica_id', 'nroinstancia', 'puntaje_asignado', 'observaciones', 'updated_at']);

        return response()->json(['data' => [
            'participantes' => $participantes,
            'rubricas' => $rubricas,
            'asignaciones' => $asignaciones,
            'calificaciones' => $calificaciones,
            'instancias' => $instancias,
            'clasificaciones' => $clasificaciones,
            'desiertos' => \App\Models\LugarDesierto::where('evento_id', $eventoId)->where('nroinstancia', $instancia)
                ->get(['especialidad', 'categoria', 'lugar']),
        ]]);
    }

    // Marca o desmarca un lugar como desierto para un grupo (especialidad + categoría) en una instancia.
    public function alternarDesierto(Request $request)
    {
        $v = $request->validate([
            'evento_id' => 'required|integer|exists:eventos,id',
            'especialidad' => 'nullable|string|max:100',
            'categoria' => 'nullable|string|max:100',
            'nroinstancia' => 'nullable|integer|min:1',
            'lugar' => 'required|integer|min:1|max:100',
            'desierto' => 'required|boolean',
        ]);
        $user = $request->user();
        if (!empty($user?->instituciones_id)
            && !\App\Models\Eventos::where('id', $v['evento_id'])->where('instituciones_id', $user->instituciones_id)->exists()) {
            return response()->json(['data' => 'Evento no válido'], 422);
        }

        $clave = [
            'evento_id' => $v['evento_id'],
            'especialidad' => trim((string) ($v['especialidad'] ?? '')),
            'categoria' => trim((string) ($v['categoria'] ?? '')),
            'nroinstancia' => (int) ($v['nroinstancia'] ?? 1),
            'lugar' => $v['lugar'],
        ];
        if ($v['desierto']) {
            \App\Models\LugarDesierto::firstOrCreate($clave);
        } else {
            \App\Models\LugarDesierto::where($clave)->delete();
        }

        return response()->json(['data' => 'OK']);
    }

    // Marca o desmarca participantes como clasificados a la instancia siguiente.
    public function clasificar(Request $request)
    {
        $data = $request->validate([
            'evento_id' => 'required|integer|exists:eventos,id',
            'nroinstancia' => 'required|integer|min:2',
            'estudiantes_ids' => 'required|array|min:1',
            'estudiantes_ids.*' => 'integer',
            'clasificar' => 'required|boolean',
        ]);
        $destino = (int) $data['nroinstancia'];

        $ids = $this->participantesQuery($request, (int) $data['evento_id'])
            ->whereIn('id', $data['estudiantes_ids'])->pluck('id');
        if ($ids->isEmpty()) {
            return response()->json(['data' => 'Participantes no encontrados'], 404);
        }

        if ($data['clasificar']) {
            $validos = $ids->filter(fn ($id) => self::enInstancia((int) $id, $destino - 1))->values();
            DB::transaction(function () use ($validos, $destino) {
                foreach ($validos as $id) {
                    \App\Models\ClasificacionEvento::firstOrCreate(['estudiantesevento_id' => $id, 'nroinstancia' => $destino]);
                }
            });
            return response()->json(['data' => 'CLASIFICADOS', 'afectados' => $validos->count()]);
        }

        $bloqueados = CalificacionesEvento::whereIn('estudiantesevento_id', $ids)
            ->where('nroinstancia', '>=', $destino)->distinct()->pluck('estudiantesevento_id');
        $quitar = $ids->diff($bloqueados)->values();
        DB::table('clasificacioneseventos')->whereIn('estudiantesevento_id', $quitar)
            ->where('nroinstancia', '>=', $destino)->delete();

        return response()->json([
            'data' => 'ACTUALIZADO',
            'afectados' => $quitar->count(),
            'bloqueados' => $bloqueados->count(),
        ]);
    }

    public function guardarLote(Request $request)
    {
        $data = $request->validate([
            'jurado_id' => 'required|integer|exists:jurados,id',
            'estudiantesevento_id' => 'required|integer|exists:estudianteseventos,id',
            'nroinstancia' => 'nullable|integer|min:1',
            'items' => 'required|array|min:1',
            'items.*.rubrica_id' => 'required|integer|exists:rubricaseventos,id',
            'items.*.puntaje_asignado' => ['nullable', 'regex:/^(-|\d{1,3})$/'],
            'items.*.observaciones' => 'nullable|string',
        ]);
        $instancia = self::instancia($request);
        $data['nroinstancia'] = $instancia;

        $user = $request->user();
        $est = DB::table('estudianteseventos')->where('id', $data['estudiantesevento_id'])
            ->when(!empty($user?->instituciones_id), fn ($q) => $q->where('instituciones_id', $user->instituciones_id))
            ->first();
        if (!$est) {
            return response()->json(['data' => 'Participante no encontrado'], 404);
        }
        if (!self::enInstancia((int) $est->id, $instancia)) {
            return response()->json(['data' => "El participante no clasificó a la instancia {$instancia}"], 422);
        }

        $asignado = \App\Models\JuradoAsignacion::where('jurado_id', $data['jurado_id'])
            ->where('evento_id', $est->eventos_id)
            ->where('especialidad', $est->Especialidad)
            ->where('categoria', $est->Categoria)
            ->exists();
        if (!$asignado) {
            return response()->json(['data' => 'El jurado no está asignado a la especialidad/categoría de este participante'], 422);
        }

        $rubricas = RubricasEvento::whereIn('id', collect($data['items'])->pluck('rubrica_id'))->get()->keyBy('id');
        foreach ($data['items'] as $item) {
            $r = $rubricas[$item['rubrica_id']];
            if ((int) $r->evento_id !== (int) $est->eventos_id
                || mb_strtoupper($r->especialidad) !== mb_strtoupper((string) $est->Especialidad)
                || mb_strtoupper($r->categoria) !== mb_strtoupper((string) $est->Categoria)) {
                return response()->json(['data' => "La rúbrica '{$r->criterio}' no corresponde a este participante"], 422);
            }
            if (isset($item['puntaje_asignado']) && is_numeric($item['puntaje_asignado']) && (int) $item['puntaje_asignado'] > (int) $r->puntaje_maximo) {
                return response()->json(['data' => "El puntaje de '{$r->criterio}' no puede exceder {$r->puntaje_maximo}"], 422);
            }
        }

        DB::transaction(function () use ($data, $instancia) {
            foreach ($data['items'] as $item) {
                $where = [
                    'jurado_id' => $data['jurado_id'],
                    'estudiantesevento_id' => $data['estudiantesevento_id'],
                    'rubrica_id' => $item['rubrica_id'],
                    'nroinstancia' => $instancia,
                ];
                if (!isset($item['puntaje_asignado'])) {
                    CalificacionesEvento::where($where)->delete();
                    continue;
                }
                CalificacionesEvento::updateOrCreate($where, [
                    'puntaje_asignado' => $item['puntaje_asignado'],
                    'observaciones' => $item['observaciones'] ?? null,
                ]);
            }
        });

        return response()->json(['data' => 'GUARDADO']);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'jurado_id' => 'required|exists:jurados,id',
            'estudiantesevento_id' => 'required|exists:estudianteseventos,id',
            'rubrica_id' => 'required|exists:rubricaseventos,id',
            'nroinstancia' => 'nullable|integer|min:1',
            'puntaje_asignado' => ['required', 'regex:/^(-|\d{1,3})$/'],
            'observaciones' => 'nullable|string',
        ]);
        $validated['nroinstancia'] = max(1, (int) ($validated['nroinstancia'] ?? 1));

        $rubrica = RubricasEvento::findOrFail($validated['rubrica_id']);
        
        if (is_numeric($validated['puntaje_asignado']) && (int) $validated['puntaje_asignado'] > (int) $rubrica->puntaje_maximo) {
            return response()->json([
                'data' => 'El puntaje asignado no puede exceder el puntaje máximo de ' . $rubrica->puntaje_maximo,
            ], 422);
        }

        $calificacion = CalificacionesEvento::create($validated);
        $calificacion->load(['jurado', 'estudianteEvento', 'rubrica']);
        return response()->json(['data' => $calificacion], 201);
    }

    public function show(CalificacionesEvento $calificacionesEvento)
    {
        $calificacionesEvento->load(['jurado', 'estudianteEvento', 'rubrica']);
        return response()->json(['data' => $calificacionesEvento]);
    }

    public function edit(CalificacionesEvento $calificacionesEvento)
    {
        //
    }

    public function update(Request $request, CalificacionesEvento $calificacionesEvento)
    {
        $validated = $request->validate([
            'puntaje_asignado' => ['sometimes', 'regex:/^(-|\d{1,3})$/'],
            'observaciones' => 'sometimes|nullable|string',
        ]);

        if (isset($validated['puntaje_asignado']) && is_numeric($validated['puntaje_asignado'])) {
            $rubrica = $calificacionesEvento->rubrica;
            if ((int) $validated['puntaje_asignado'] > (int) $rubrica->puntaje_maximo) {
                return response()->json([
                    'data' => 'El puntaje asignado no puede exceder el puntaje máximo de ' . $rubrica->puntaje_maximo,
                ], 422);
            }
        }

        $calificacionesEvento->update($validated);
        $calificacionesEvento->load(['jurado', 'estudianteEvento', 'rubrica']);
        return response()->json(['data' => $calificacionesEvento]);
    }

    public function destroy(CalificacionesEvento $calificacionesEvento)
    {
        $calificacionesEvento->delete();
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }

    public function obtenerCalificacionesPorEstudiante($estudianteEventoId)
    {
        $calificaciones = CalificacionesEvento::where('estudiantesevento_id', $estudianteEventoId)
            ->with(['jurado', 'rubrica'])
            ->get();

        return response()->json(['data' => $calificaciones]);
    }

    public function obtenerCalificacionesPorJurado($juradoId, $eventoId)
    {
        $calificaciones = CalificacionesEvento::whereHas('rubrica', function ($q) use ($eventoId) {
            $q->where('evento_id', $eventoId);
        })
            ->where('jurado_id', $juradoId)
            ->with(['jurado', 'estudianteEvento', 'rubrica'])
            ->get();

        return response()->json(['data' => $calificaciones]);
    }
}