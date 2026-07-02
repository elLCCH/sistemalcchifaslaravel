<?php

namespace App\Http\Controllers;

use App\Models\Calificaciones;
use App\Models\Infoestudiantesifas;
use App\Models\Materias;
use App\Models\Planteladministrativos;
use App\Models\Planteldocentes;
use App\Models\Usuarioslcchs;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;

class CalificacionesController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }

    private function isSuperAdmin($user): bool
    {
        return !empty($user) && empty($user->instituciones_id);
    }

    private function isAdminUser($user): bool
    {
        if (!$user) return false;
        return $this->isSuperAdmin($user) || ($user instanceof Planteladministrativos) || ($user instanceof Usuarioslcchs);
    }

    private function isDocenteUser($user): bool
    {
        return !empty($user) && ($user instanceof Planteldocentes);
    }

    private function ensureDocenteAsignado($user, int $materiaId): void
    {
        if (!$this->isDocenteUser($user)) return;

        // 1) Asignación explícita (modo normal)
        $ok = DB::table('planteldocentesmaterias')
            ->where('planteldocentes_id', (int) $user->id)
            ->where('materias_id', $materiaId)
            ->exists();

        if ($ok) return;

        // 2) Asignación por inscripción (usado por Perfil) para modos especiales.
        // En estos modos, el docente se vincula vía infoestudiantesifas.planteldocadmins_id*
        // y puede no existir registro en planteldocentesmaterias.
        $modoMateria = DB::table('materias')
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->where('materias.id', $materiaId)
            ->value('plandeestudios.ModoMateria');

        $modoMateria = trim((string) ($modoMateria ?? ''));
        if ($modoMateria === '') {
            abort(404);
        }

        $allowed = false;
        if ($modoMateria === 'MODO INSTRUMENTOS DE ESPECIALIDAD') {
            $allowed = DB::table('calificaciones as cal')
                ->join('infoestudiantesifas as info', 'cal.infoestudiantesifas_id', '=', 'info.id')
                ->where('cal.materias_id', $materiaId)
                ->where('info.planteldocadmins_id', (int) $user->id)
                ->exists();
        } elseif ($modoMateria === 'MODO PRÁCTICA DE CONJUNTOS') {
            $allowed = DB::table('calificaciones as cal')
                ->join('infoestudiantesifas as info', 'cal.infoestudiantesifas_id', '=', 'info.id')
                ->where('cal.materias_id', $materiaId)
                ->where('info.planteldocadmins_idPC', (int) $user->id)
                ->exists();
        } elseif ($modoMateria === 'MODO INSTRUMENTO COMPLEMENTARIO') {
            $allowed = DB::table('calificaciones as cal')
                ->join('infoestudiantesifas as info', 'cal.infoestudiantesifas_id', '=', 'info.id')
                ->where('cal.materias_id', $materiaId)
                ->where('info.planteldocadmins_idOtros', (int) $user->id)
                ->exists();
        }

        if (!$allowed) {
            abort(404);
        }
    }

    private function parseEvalCount($raw): int
    {
        $s = trim((string) ($raw ?? ''));
        if ($s === '') return 4;

        if (preg_match('/(\d+)/', $s, $m)) {
            $n = (int) $m[1];
        } else {
            $n = (int) $raw;
        }

        if ($n < 1 || $n > 4) return 4;
        return $n;
    }

    private function getEvaluationMode($estado): string
    {
        $s = strtoupper(trim((string) ($estado ?? '')));
        if ($s === '') return 'NORMAL';
        if (str_contains($s, 'SEGUNDA INSTANCIA')) return 'SEGUNDA_INSTANCIA';
        if (str_contains($s, 'EVALUACIÓN FINAL') || str_contains($s, 'EVALUACION FINAL')) return 'EVALUACION_FINAL';
        if ($s === 'TODAS LAS EVALUACIONES' || $s === 'TODAS' || str_contains($s, 'TODO')) return 'TODAS';
        return 'NORMAL';
    }

    private function parseEnabledEvaluations($estado, int $evalCountMax): array
    {
        $out = [];
        $raw = trim((string) ($estado ?? ''));
        if ($raw === '') return $out;

        $s = strtoupper($raw);
        if (str_contains($s, 'DESHABIL')) return $out;
        if (str_contains($s, 'SEGUNDA INSTANCIA') || str_contains($s, 'EVALUACIÓN FINAL') || str_contains($s, 'EVALUACION FINAL')) return $out;

        if (str_contains($s, 'HABILIT') || str_contains($s, 'TODAS') || str_contains($s, 'TODO')) {
            for ($i = 1; $i <= max(1, min(4, $evalCountMax)); $i++) $out[] = $i;
            return $out;
        }

        if (str_contains($s, 'PRIMERA') || preg_match('/\b1\b/', $s)) $out[] = 1;
        if (str_contains($s, 'SEGUNDA') || preg_match('/\b2\b/', $s)) $out[] = 2;
        if (str_contains($s, 'TERCERA') || preg_match('/\b3\b/', $s)) $out[] = 3;
        if (str_contains($s, 'CUARTA') || preg_match('/\b4\b/', $s)) $out[] = 4;

        $out = array_values(array_unique(array_filter($out, fn ($n) => $n >= 1 && $n <= 4)));
        sort($out);
        return $out;
    }

    private function getInstitucionIdFromInfo(int $infoId): ?int
    {
        $inst = Infoestudiantesifas::query()
            ->where('id', $infoId)
            ->value('instituciones_id');

        return $inst === null ? null : (int) $inst;
    }

    private function getInstitucionIdFromMateria(int $materiaId): ?int
    {
        $inst = DB::table('materias')
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->where('materias.id', $materiaId)
            ->value('carreras.instituciones_id');

        return $inst === null ? null : (int) $inst;
    }

    private function makeMateriaToken($user, int $materiaId): string
    {
        $payload = [
            'mid' => (int) $materiaId,
            'uid' => (int) ($user->id ?? 0),
            // 24h (suficiente para navegación desde Perfil)
            'exp' => time() + (24 * 60 * 60),
        ];

        return Crypt::encryptString(json_encode($payload));
    }

    private function decodeMateriaToken(string $token): ?array
    {
        try {
            $json = Crypt::decryptString($token);
            $arr = json_decode($json, true);
            if (!is_array($arr)) return null;
            $mid = (int) ($arr['mid'] ?? 0);
            $uid = (int) ($arr['uid'] ?? 0);
            $exp = (int) ($arr['exp'] ?? 0);
            if ($mid <= 0 || $uid <= 0 || $exp <= 0) return null;
            return ['mid' => $mid, 'uid' => $uid, 'exp' => $exp];
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function materiaToken(Request $request, $materiaId)
    {
        $user = $request->user();
        if (!$user) abort(404);

        $isSuperAdmin = $this->isSuperAdmin($user);

        $materiaId = (int) $materiaId;
        if ($materiaId <= 0) abort(404);

        $materiaInstitucionId = $this->getInstitucionIdFromMateria($materiaId);
        if (!$materiaInstitucionId) abort(404);

        if (!$isSuperAdmin && (int) $user->instituciones_id !== (int) $materiaInstitucionId) {
            abort(404);
        }

        // Reusar la misma regla de acceso docente (asignación explícita o por inscripción en modos especiales)
        $this->ensureDocenteAsignado($user, $materiaId);

        $token = $this->makeMateriaToken($user, $materiaId);
        return response()->json(['token' => $token]);
    }

    public function byMateriaToken(Request $request)
    {
        $user = $request->user();
        if (!$user) abort(404);

        $token = trim((string) $request->query('t', ''));
        if ($token === '') abort(404);

        $payload = $this->decodeMateriaToken($token);
        if (!$payload) abort(404);

        if ((int) ($user->id ?? 0) !== (int) $payload['uid']) {
            abort(404);
        }

        if (time() > (int) $payload['exp']) {
            abort(404);
        }

        return $this->byMateria($request, (int) $payload['mid']);
    }
    //#region Inicio Controller de Crud PHP de calificaciones
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(404);
        }

        $isSuperAdmin = $this->isSuperAdmin($user);

        $query = Calificaciones::query();
        if (!$isSuperAdmin) {
            $query
                ->join('materias', 'calificaciones.materias_id', '=', 'materias.id')
                ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
                ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
                ->where('carreras.instituciones_id', $user->instituciones_id)
                ->select(['calificaciones.*']);
        } else {
            $query->select(['calificaciones.*']);
        }

        return response()->json(['data' => $query->get()]);
    }

    /**
     * Calificaciones de un estudiante visible para el docente autenticado.
     * Devuelve las calificaciones agrupadas por materia.
     */
    public function byInfoDocente(Request $request, $infoId)
    {
        $user = $request->user();
        if (!$user || !$this->isDocenteUser($user)) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $infoId = (int) $infoId;
        if ($infoId <= 0) abort(404);

        $docenteId = (int) $user->id;

        // Obtener las materias_id donde el docente está asignado (directa o por inscripción del estudiante)
        // 1) Asignación directa vía planteldocentesmaterias
        $materiasDirectas = DB::table('planteldocentesmaterias')
            ->where('planteldocentes_id', $docenteId)
            ->pluck('materias_id')
            ->toArray();

        // 2) Materias donde el estudiante está inscrito y vinculado al docente por modo
        $materiasPorModo = DB::table('calificaciones as cal')
            ->join('infoestudiantesifas as info', 'cal.infoestudiantesifas_id', '=', 'info.id')
            ->join('materias', 'cal.materias_id', '=', 'materias.id')
            ->join('plandeestudios as pe', 'materias.plandeestudios_id', '=', 'pe.id')
            ->where('cal.infoestudiantesifas_id', $infoId)
            ->where(function ($q) use ($docenteId) {
                $q->where('info.planteldocadmins_id', $docenteId)
                  ->orWhere('info.planteldocadmins_idPC', $docenteId)
                  ->orWhere('info.planteldocadmins_idOtros', $docenteId);
            })
            ->pluck('cal.materias_id')
            ->toArray();

        $allMaterias = array_unique(array_merge($materiasDirectas, $materiasPorModo));
        if (empty($allMaterias)) {
            return response()->json(['data' => []]);
        }

        // Calificaciones del estudiante en esas materias
        $calificaciones = Calificaciones::query()
            ->join('materias', 'calificaciones.materias_id', '=', 'materias.id')
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->leftJoin('anios', 'plandeestudios.anio_id', '=', 'anios.id')
            ->where('calificaciones.infoestudiantesifas_id', $infoId)
            ->whereIn('calificaciones.materias_id', $allMaterias)
            ->select([
                'calificaciones.*',
                'materias.Paralelo as MateriaParalelo',
                'plandeestudios.NombreMateria',
                'plandeestudios.SiglaMateria',
                'plandeestudios.LvlCurso',
                'plandeestudios.ModoMateria',
                'anios.Anio',
                'carreras.NombreCarrera',
                'carreras.CantidadEvaluaciones',
            ])
            ->orderBy('plandeestudios.RangoLvlCurso')
            ->orderBy('plandeestudios.Rango')
            ->orderBy('plandeestudios.NombreMateria')
            ->get();

        // Agrupar por materia
        $grouped = [];
        foreach ($calificaciones as $c) {
            $mid = $c->materias_id;
            if (!isset($grouped[$mid])) {
                $grouped[$mid] = [
                    'materia_id' => $mid,
                    'nombre_materia' => $c->NombreMateria,
                    'sigla_materia' => $c->SiglaMateria,
                    'lvl_curso' => $c->LvlCurso,
                    'paralelo' => $c->MateriaParalelo,
                    'anio' => $c->Anio,
                    'carrera' => $c->NombreCarrera,
                    'cantidad_evaluaciones' => $c->CantidadEvaluaciones,
                    'calificacion' => null,
                ];
            }
            $grouped[$mid]['calificacion'] = $c;
        }

        return response()->json(['data' => array_values($grouped)]);
    }

    public function byInfo(Request $request, $infoId)
    {
        $user = $request->user();
        if (!$user) {
            abort(404);
        }

        // Docente no debe acceder por inscripción (podría ver materias ajenas).
        if ($this->isDocenteUser($user)) {
            return response()->json(['message' => 'Acceso no permitido'], 403);
        }

        $isSuperAdmin = $this->isSuperAdmin($user);
        $infoId = (int) $infoId;
        if ($infoId <= 0) {
            abort(404);
        }

        $infoInstitucionId = $this->getInstitucionIdFromInfo($infoId);
        if (!$infoInstitucionId) {
            abort(404);
        }

        if (!$isSuperAdmin && (int) $user->instituciones_id !== (int) $infoInstitucionId) {
            abort(404);
        }

        $query = Calificaciones::query()
            ->join('materias', 'calificaciones.materias_id', '=', 'materias.id')
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->leftJoin('anios', 'plandeestudios.anio_id', '=', 'anios.id')
            ->where('calificaciones.infoestudiantesifas_id', $infoId)
            ->where('carreras.instituciones_id', $infoInstitucionId)
            ->select([
                'calificaciones.*',
                'materias.Paralelo as MateriaParalelo',
                'materias.EstadoHabilitacion',
                'plandeestudios.NombreMateria',
                'plandeestudios.SiglaMateria',
                'plandeestudios.LvlCurso',
                'plandeestudios.anio_id',
                'plandeestudios.carreras_id',
                'anios.Anio',
                'anios.EdicionCalificaciones',
                'carreras.Resolucion',
                'carreras.NombreCarrera',
                'carreras.CantidadEvaluaciones',
                'carreras.LimiteMaxTeorico',
                'carreras.LimiteMaxPractico',
                'carreras.NotaAprobacion',
                'carreras.NotaMinRevalida',
            ])
            ->orderBy('plandeestudios.RangoLvlCurso')
            ->orderBy('plandeestudios.Rango')
            ->orderBy('plandeestudios.NombreMateria');

        $perPage = (int) $request->query('per_page', 200);
        if ($perPage < 1) {
            $perPage = 200;
        }
        if ($perPage > 500) {
            $perPage = 500;
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    public function bulkUpdate(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario inválido'], 422);
        }

        // Docente no debe editar por inscripción (podría modificar materias ajenas).
        if ($this->isDocenteUser($user)) {
            return response()->json(['message' => 'Acceso no permitido'], 403);
        }

        $isSuperAdmin = $this->isSuperAdmin($user);

        $validated = $request->validate([
            'infoestudiantesifas_id' => ['required', 'integer'],
            'avg_eval_count' => ['nullable', 'integer', 'min:1', 'max:4'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.id' => ['required', 'integer'],
            'items.*.Teorico1' => ['nullable', 'integer', 'min:0', 'max:100'],
            'items.*.Teorico2' => ['nullable', 'integer', 'min:0', 'max:100'],
            'items.*.Teorico3' => ['nullable', 'integer', 'min:0', 'max:100'],
            'items.*.Teorico4' => ['nullable', 'integer', 'min:0', 'max:100'],
            'items.*.Practico1' => ['nullable', 'integer', 'min:0', 'max:100'],
            'items.*.Practico2' => ['nullable', 'integer', 'min:0', 'max:100'],
            'items.*.Practico3' => ['nullable', 'integer', 'min:0', 'max:100'],
            'items.*.Practico4' => ['nullable', 'integer', 'min:0', 'max:100'],
            'items.*.PruebaRecuperacion' => ['nullable', 'integer', 'min:0', 'max:100'],
            'items.*.PromTeorico' => ['nullable', 'integer', 'min:0', 'max:100'],
            'items.*.PromPractico' => ['nullable', 'integer', 'min:0', 'max:100'],
            'items.*.Promedio' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $infoId = (int) $validated['infoestudiantesifas_id'];
        $avgEvalCount = (int) ($validated['avg_eval_count'] ?? 4);
        if ($avgEvalCount < 1 || $avgEvalCount > 4) {
            $avgEvalCount = 4;
        }

        $info = Infoestudiantesifas::query()
            ->where('id', $infoId)
            ->first();

        if (!$info) {
            return response()->json(['message' => 'Inscripción no encontrada'], 404);
        }

        $infoInstitucionId = (int) ($info->instituciones_id ?? 0);
        if ($infoInstitucionId <= 0) {
            return response()->json(['message' => 'Inscripción inválida'], 422);
        }

        if (!$isSuperAdmin && (int) $user->instituciones_id !== (int) $infoInstitucionId) {
            return response()->json(['message' => 'Inscripción no pertenece a la institución'], 403);
        }

        // Verificar EdicionCalificaciones del año asociado
        $anioCheck = DB::table('calificaciones')
            ->join('materias', 'calificaciones.materias_id', '=', 'materias.id')
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->leftJoin('anios', 'plandeestudios.anio_id', '=', 'anios.id')
            ->where('calificaciones.infoestudiantesifas_id', $infoId)
            ->select('anios.EdicionCalificaciones')
            ->first();

        $edCalif = strtoupper(trim((string) ($anioCheck->EdicionCalificaciones ?? '')));
        if ($edCalif !== 'HABILITADO') {
            return response()->json(['message' => 'La edición de calificaciones no está habilitada para esta gestión.'], 403);
        }

        $items = $validated['items'];
        $ids = collect($items)->pluck('id')->map(fn ($x) => (int) $x)->values();

        // Verifica pertenencia institución + que sean de la misma inscripción
        $allowed = Calificaciones::query()
            ->join('materias', 'calificaciones.materias_id', '=', 'materias.id')
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->where('calificaciones.infoestudiantesifas_id', $infoId)
            ->where('carreras.instituciones_id', $infoInstitucionId)
            ->whereIn('calificaciones.id', $ids)
            ->select(['calificaciones.*'])
            ->get()
            ->keyBy('id');

        if ($allowed->count() !== $ids->count()) {
            return response()->json(['message' => 'Uno o más registros no son válidos para esta institución/inscripción'], 403);
        }

        $avg = function (array $values) {
            $nums = array_values(array_filter($values, fn ($v) => is_int($v) || is_float($v)));
            if (count($nums) === 0) return null;
            $sum = array_reduce($nums, fn ($acc, $n) => $acc + $n, 0);
            return (int) round($sum / count($nums));
        };

        DB::beginTransaction();
        try {
            $updated = 0;

            foreach ($items as $payload) {
                $rowId = (int) $payload['id'];
                /** @var Calificaciones $row */
                $row = $allowed->get($rowId);
                if (!$row) {
                    continue;
                }

                // Asigna campos editables
                foreach (['Teorico1','Teorico2','Teorico3','Teorico4','Practico1','Practico2','Practico3','Practico4','PruebaRecuperacion'] as $k) {
                    if (array_key_exists($k, $payload)) {
                        $row->$k = $payload[$k];
                    }
                }

                // No recalcular promedios en backend: usar los valores enviados por el frontend si están presentes
                if (array_key_exists('PromTeorico', $payload)) {
                    $v = $payload['PromTeorico'];
                    if ($v !== null) { $v = (int) $v; if ($v < 0) $v = 0; if ($v > 100) $v = 100; $row->PromTeorico = $v; } else { $row->PromTeorico = null; }
                }
                if (array_key_exists('PromPractico', $payload)) {
                    $v = $payload['PromPractico'];
                    if ($v !== null) { $v = (int) $v; if ($v < 0) $v = 0; if ($v > 100) $v = 100; $row->PromPractico = $v; } else { $row->PromPractico = null; }
                }
                if (array_key_exists('Promedio', $payload)) {
                    $v = $payload['Promedio'];
                    if ($v !== null) { $v = (int) $v; if ($v < 0) $v = 0; if ($v > 100) $v = 100; $row->Promedio = $v; } else { $row->Promedio = null; }
                }

                $row->save();
                $updated++;
            }

            DB::commit();
            return response()->json(['data' => ['updated' => $updated]]);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function byMateria(Request $request, $materiaId)
    {
        $user = $request->user();
        if (!$user) {
            abort(404);
        }

        $isSuperAdmin = $this->isSuperAdmin($user);

        $materiaId = (int) $materiaId;
        if ($materiaId <= 0) {
            abort(404);
        }

        $materia = Materias::query()
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->leftJoin('anios', 'plandeestudios.anio_id', '=', 'anios.id')
            ->where('materias.id', $materiaId)
            ->select([
                'materias.id',
                'materias.Paralelo as MateriaParalelo',
                'materias.Turno as Turno',
                'materias.EstadoHabilitacion',
                'plandeestudios.NombreMateria',
                'plandeestudios.SiglaMateria',
                'plandeestudios.LvlCurso',
                'plandeestudios.ModoMateria as ModoMateria',
                'anios.Anio',
                'anios.EdicionCalificaciones',
                'carreras.CantidadEvaluaciones',
                'carreras.LimiteMaxTeorico',
                'carreras.LimiteMaxPractico',
                'carreras.NotaAprobacion',
                'carreras.NotaMinRevalida',
                'carreras.NombreCarrera',
                'carreras.Area',
                'carreras.Resolucion',
                'carreras.Nivel as Malla',
                'carreras.instituciones_id as instituciones_id',
            ])
            ->first();

        if (!$materia) {
            abort(404);
        }

        $materiaInstitucionId = (int) ($materia->instituciones_id ?? 0);
        if ($materiaInstitucionId <= 0) {
            abort(404);
        }

        if (!$isSuperAdmin && (int) $user->instituciones_id !== (int) $materiaInstitucionId) {
            abort(404);
        }

        $this->ensureDocenteAsignado($user, $materiaId);

        $docentes = DB::table('planteldocentesmaterias')
            ->join('planteldocentes', 'planteldocentesmaterias.planteldocentes_id', '=', 'planteldocentes.id')
            ->where('planteldocentesmaterias.materias_id', $materiaId)
            ->where('planteldocentes.instituciones_id', $materiaInstitucionId)
            ->select([
                'planteldocentes.id',
                'planteldocentes.Nombres',
                'planteldocentes.Apellidos',
            ])
            ->orderByRaw("(planteldocentes.Apellidos IS NULL OR TRIM(planteldocentes.Apellidos)='') DESC")
            ->orderBy('planteldocentes.Apellidos')
            ->orderByRaw("(planteldocentes.Nombres IS NULL OR TRIM(planteldocentes.Nombres)='') DESC")
            ->orderBy('planteldocentes.Nombres')
            ->get();

        // Fallback para modos especiales: los docentes pueden venir por inscripción
        // (infoestudiantesifas.planteldocadmins_id*) y no existir en planteldocentesmaterias.
        if ($docentes->count() === 0) {
            $modoMateria = trim((string) ($materia->ModoMateria ?? ''));
            $docCol = null;
            if ($modoMateria === 'MODO INSTRUMENTOS DE ESPECIALIDAD') {
                $docCol = 'info.planteldocadmins_id';
            } elseif ($modoMateria === 'MODO PRÁCTICA DE CONJUNTOS') {
                $docCol = 'info.planteldocadmins_idPC';
            } elseif ($modoMateria === 'MODO INSTRUMENTO COMPLEMENTARIO') {
                $docCol = 'info.planteldocadmins_idOtros';
            }

            if ($docCol) {
                $docentes = DB::table('calificaciones as cal')
                    ->join('infoestudiantesifas as info', 'cal.infoestudiantesifas_id', '=', 'info.id')
                    ->join('planteldocentes as d', function ($join) use ($docCol) {
                        $join->on(DB::raw($docCol), '=', 'd.id');
                    })
                    ->where('cal.materias_id', $materiaId)
                    ->where('d.instituciones_id', $materiaInstitucionId)
                    ->distinct()
                    ->select([
                        'd.id',
                        'd.Nombres',
                        'd.Apellidos',
                    ])
                    ->orderByRaw("(d.Apellidos IS NULL OR TRIM(d.Apellidos)='') DESC")
                    ->orderBy('d.Apellidos')
                    ->orderByRaw("(d.Nombres IS NULL OR TRIM(d.Nombres)='') DESC")
                    ->orderBy('d.Nombres')
                    ->get();
            }
        }

        $query = Calificaciones::query()
            ->join('infoestudiantesifas', 'calificaciones.infoestudiantesifas_id', '=', 'infoestudiantesifas.id')
            ->join('estudiantesifas', 'infoestudiantesifas.estudiantesifas_id', '=', 'estudiantesifas.id')
            ->join('materias', 'calificaciones.materias_id', '=', 'materias.id')
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->leftJoin('anios', 'plandeestudios.anio_id', '=', 'anios.id')
            ->where('calificaciones.materias_id', $materiaId)
            ->where('carreras.instituciones_id', $materiaInstitucionId)
            ->where('infoestudiantesifas.instituciones_id', $materiaInstitucionId)
            ->select([
                'calificaciones.*',
                'estudiantesifas.id as estudiantesifas_id',
                'estudiantesifas.Ap_Paterno',
                'estudiantesifas.Ap_Materno',
                'estudiantesifas.Nombre as Nombres',
                'estudiantesifas.CI',
                'estudiantesifas.Foto',
                'infoestudiantesifas.InstrumentoMusical',
                'infoestudiantesifas.InstrumentoMusicalSecundario',
                'infoestudiantesifas.planteldocadmins_id as docente_id_especialidad',
                'infoestudiantesifas.planteldocadmins_idPC as docente_id_conjuntos',
                'infoestudiantesifas.planteldocadmins_idOtros as docente_id_complementario',
                'materias.Paralelo as MateriaParalelo',
                'plandeestudios.NombreMateria',
                'plandeestudios.SiglaMateria',
                'plandeestudios.LvlCurso',
                'anios.Anio',
                'anios.EdicionCalificaciones',
                'carreras.CantidadEvaluaciones',
            ])
            ->orderByRaw("(estudiantesifas.Ap_Paterno IS NULL OR TRIM(estudiantesifas.Ap_Paterno)='') DESC")
            ->orderBy('estudiantesifas.Ap_Paterno')
            ->orderByRaw("(estudiantesifas.Ap_Materno IS NULL OR TRIM(estudiantesifas.Ap_Materno)='') DESC")
            ->orderBy('estudiantesifas.Ap_Materno')
            ->orderByRaw("(estudiantesifas.Nombre IS NULL OR TRIM(estudiantesifas.Nombre)='') DESC")
            ->orderBy('estudiantesifas.Nombre')
            ->orderBy('calificaciones.id');

        // Docente: en los 3 modos especiales solo debe ver SUS estudiantes (asignación por inscripción)
        if ($this->isDocenteUser($user)) {
            $modoMateria = trim((string) ($materia->ModoMateria ?? ''));
            if ($modoMateria === 'MODO INSTRUMENTOS DE ESPECIALIDAD') {
                $query->where('infoestudiantesifas.planteldocadmins_id', (int) $user->id);
            } elseif ($modoMateria === 'MODO PRÁCTICA DE CONJUNTOS') {
                $query->where('infoestudiantesifas.planteldocadmins_idPC', (int) $user->id);
            } elseif ($modoMateria === 'MODO INSTRUMENTO COMPLEMENTARIO') {
                $query->where('infoestudiantesifas.planteldocadmins_idOtros', (int) $user->id);
            }
        }

        // Admin/superadmin: puede filtrar por docente (opcional) en modos especiales.
        // Esto sirve para generar/visualizar por un docente específico sin mezclar estudiantes.
        if ($this->isAdminUser($user)) {
            $docenteId = (int) $request->query('docente_id', 0);
            if ($docenteId > 0) {
                $modoMateria = trim((string) ($materia->ModoMateria ?? ''));
                if ($modoMateria === 'MODO INSTRUMENTOS DE ESPECIALIDAD') {
                    $query->where('infoestudiantesifas.planteldocadmins_id', $docenteId);
                } elseif ($modoMateria === 'MODO PRÁCTICA DE CONJUNTOS') {
                    $query->where('infoestudiantesifas.planteldocadmins_idPC', $docenteId);
                } elseif ($modoMateria === 'MODO INSTRUMENTO COMPLEMENTARIO') {
                    $query->where('infoestudiantesifas.planteldocadmins_idOtros', $docenteId);
                }
            }
        }

        $perPage = (int) $request->query('per_page', 200);
        if ($perPage < 1) {
            $perPage = 200;
        }
        if ($perPage > 500) {
            $perPage = 500;
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'materia' => $materia,
            'docentes' => $docentes,
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    public function bulkUpdateMateria(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario inválido'], 422);
        }

        $isSuperAdmin = $this->isSuperAdmin($user);

        $validated = $request->validate([
            'materias_id' => ['required', 'integer'],
            'avg_eval_count' => ['nullable', 'integer', 'min:1', 'max:4'],
            'enabled_evals' => ['nullable', 'array', 'size:4'],
            'enabled_evals.*' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.id' => ['required', 'integer'],
            'items.*.Teorico1' => ['nullable', 'integer', 'min:0', 'max:100'],
            'items.*.Teorico2' => ['nullable', 'integer', 'min:0', 'max:100'],
            'items.*.Teorico3' => ['nullable', 'integer', 'min:0', 'max:100'],
            'items.*.Teorico4' => ['nullable', 'integer', 'min:0', 'max:100'],
            'items.*.Practico1' => ['nullable', 'integer', 'min:0', 'max:100'],
            'items.*.Practico2' => ['nullable', 'integer', 'min:0', 'max:100'],
            'items.*.Practico3' => ['nullable', 'integer', 'min:0', 'max:100'],
            'items.*.Practico4' => ['nullable', 'integer', 'min:0', 'max:100'],
            'items.*.PruebaRecuperacion' => ['nullable', 'integer', 'min:0', 'max:100'],
            'items.*.PromTeorico' => ['nullable', 'integer', 'min:0', 'max:100'],
            'items.*.PromPractico' => ['nullable', 'integer', 'min:0', 'max:100'],
            'items.*.Promedio' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $materiaId = (int) $validated['materias_id'];
        $this->ensureDocenteAsignado($user, $materiaId);

        $materiaInstitucionId = $this->getInstitucionIdFromMateria($materiaId);
        if (!$materiaInstitucionId) {
            return response()->json(['message' => 'Materia no encontrada'], 404);
        }
        if (!$isSuperAdmin && (int) $user->instituciones_id !== (int) $materiaInstitucionId) {
            return response()->json(['message' => 'Materia no pertenece a la institución'], 403);
        }

        $isAdminUser = $this->isAdminUser($user);

        $materiaMeta = DB::table('materias')
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->leftJoin('anios', 'plandeestudios.anio_id', '=', 'anios.id')
            ->where('materias.id', $materiaId)
            ->select([
                'materias.EstadoHabilitacion',
                'carreras.CantidadEvaluaciones',
                'carreras.LimiteMaxTeorico',
                'carreras.LimiteMaxPractico',
                'anios.EdicionCalificaciones',
            ])
            ->first();

        if (!$materiaMeta) {
            return response()->json(['message' => 'Materia no encontrada'], 404);
        }

        // Verificar EdicionCalificaciones del año asociado
        $edCalif = strtoupper(trim((string) ($materiaMeta->EdicionCalificaciones ?? '')));
        if ($edCalif !== 'HABILITADO') {
            return response()->json(['message' => 'La edición de calificaciones no está habilitada para esta gestión.'], 403);
        }

        $evalCountMax = $this->parseEvalCount($materiaMeta->CantidadEvaluaciones ?? 4);

        // Límites siempre desde carreras
        $maxTeo = (int) ($materiaMeta->LimiteMaxTeorico ?? 30);
        $maxPra = (int) ($materiaMeta->LimiteMaxPractico ?? 70);
        if ($maxTeo < 0) $maxTeo = 0;
        if ($maxTeo > 100) $maxTeo = 100;
        if ($maxPra < 0) $maxPra = 0;
        if ($maxPra > 100) $maxPra = 100;

        // Promedio: admin puede override (temporal), docente no
        $avgEvalCount = $evalCountMax;
        if ($isAdminUser) {
            $n = (int) ($validated['avg_eval_count'] ?? $evalCountMax);
            if ($n < 1 || $n > 4) $n = $evalCountMax;
            if ($n > $evalCountMax) $n = $evalCountMax;
            $avgEvalCount = $n;
        }

        // Evaluaciones habilitadas: admin puede override (temporal), docente siempre por EstadoHabilitacion
        $modoEvaluacion = $this->getEvaluationMode($materiaMeta->EstadoHabilitacion ?? null);
        $enabled = $this->parseEnabledEvaluations($materiaMeta->EstadoHabilitacion ?? null, $evalCountMax);

        if ($isAdminUser && is_array($validated['enabled_evals'] ?? null) && count($validated['enabled_evals']) === 4) {
            $enabled = [];
            $arr = $validated['enabled_evals'];
            for ($i = 0; $i < 4; $i++) {
                $flag = (bool) ($arr[$i] ?? false);
                $evalNum = $i + 1;
                if ($evalNum <= $evalCountMax && $flag) $enabled[] = $evalNum;
            }
        }

        $enabled = array_values(array_filter($enabled, fn ($n) => $n >= 1 && $n <= $evalCountMax));
        sort($enabled);

        if ($modoEvaluacion === 'NORMAL' && count($enabled) === 0) {
            return response()->json(['message' => 'Edición deshabilitada para esta materia'], 403);
        }

        $items = $validated['items'];
        $ids = collect($items)->pluck('id')->map(fn ($x) => (int) $x)->values();

        $allowed = Calificaciones::query()
            ->join('materias', 'calificaciones.materias_id', '=', 'materias.id')
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->where('calificaciones.materias_id', $materiaId)
            ->where('carreras.instituciones_id', $materiaInstitucionId)
            ->whereIn('calificaciones.id', $ids)
            ->select(['calificaciones.*'])
            ->get()
            ->keyBy('id');

        if ($allowed->count() !== $ids->count()) {
            return response()->json(['message' => 'Uno o más registros no son válidos para esta institución/materia'], 403);
        }

        $avg = function (array $values) {
            $nums = array_values(array_filter($values, fn ($v) => is_int($v) || is_float($v)));
            if (count($nums) === 0) return null;
            $sum = array_reduce($nums, fn ($acc, $n) => $acc + $n, 0);
            return (int) round($sum / count($nums));
        };

        DB::beginTransaction();
        try {
            $updated = 0;
            foreach ($items as $payload) {
                $rowId = (int) $payload['id'];
                /** @var Calificaciones $row */
                $row = $allowed->get($rowId);
                if (!$row) continue;

                if ($modoEvaluacion === 'NORMAL' || $modoEvaluacion === 'TODAS') {
                    // Aplicar solo claves permitidas según evaluaciones habilitadas
                    foreach ([1, 2, 3, 4] as $n) {
                        if ($n > $evalCountMax) continue;
                        if (!in_array($n, $enabled, true)) continue;

                        $tk = 'Teorico' . $n;
                        $pk = 'Practico' . $n;

                        if (array_key_exists($tk, $payload)) {
                            $v = $payload[$tk];
                            if ($v !== null) {
                                $v = (int) $v;
                                if ($v < 0) $v = 0;
                                if ($v > $maxTeo) $v = $maxTeo;
                            }
                            $row->$tk = $v;
                        }

                        if (array_key_exists($pk, $payload)) {
                            $v = $payload[$pk];
                            if ($v !== null) {
                                $v = (int) $v;
                                if ($v < 0) $v = 0;
                                if ($v > $maxPra) $v = $maxPra;
                            }
                            $row->$pk = $v;
                        }
                    }
                }

                if (array_key_exists('PruebaRecuperacion', $payload) && ($modoEvaluacion === 'SEGUNDA_INSTANCIA' || $modoEvaluacion === 'EVALUACION_FINAL' || $modoEvaluacion === 'NORMAL' || $modoEvaluacion === 'TODAS')) {
                    $row->PruebaRecuperacion = $payload['PruebaRecuperacion'];
                }

                // No recalcular promedios en backend: usar los valores enviados por el frontend si están presentes
                if (array_key_exists('PromTeorico', $payload)) {
                    $v = $payload['PromTeorico'];
                    if ($v !== null) { $v = (int) $v; if ($v < 0) $v = 0; if ($v > 100) $v = 100; $row->PromTeorico = $v; } else { $row->PromTeorico = null; }
                }
                if (array_key_exists('PromPractico', $payload)) {
                    $v = $payload['PromPractico'];
                    if ($v !== null) { $v = (int) $v; if ($v < 0) $v = 0; if ($v > 100) $v = 100; $row->PromPractico = $v; } else { $row->PromPractico = null; }
                }
                if (array_key_exists('Promedio', $payload)) {
                    $v = $payload['Promedio'];
                    if ($v !== null) { $v = (int) $v; if ($v < 0) $v = 0; if ($v > 100) $v = 100; $row->Promedio = $v; } else { $row->Promedio = null; }
                }

                $row->save();
                $updated++;
            }

            DB::commit();
            return response()->json(['data' => ['updated' => $updated]]);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
    
    
    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(404);
        }

        $isSuperAdmin = $this->isSuperAdmin($user);

        $validated = $request->validate([
            'infoestudiantesifas_id' => ['required', 'integer'],
            'materias_id' => ['required', 'integer'],
            'EstadoRegistroMateria' => ['nullable', 'string', 'max:50'],
            'Teorico1' => ['nullable', 'integer', 'min:0', 'max:100'],
            'Teorico2' => ['nullable', 'integer', 'min:0', 'max:100'],
            'Teorico3' => ['nullable', 'integer', 'min:0', 'max:100'],
            'Teorico4' => ['nullable', 'integer', 'min:0', 'max:100'],
            'Practico1' => ['nullable', 'integer', 'min:0', 'max:100'],
            'Practico2' => ['nullable', 'integer', 'min:0', 'max:100'],
            'Practico3' => ['nullable', 'integer', 'min:0', 'max:100'],
            'Practico4' => ['nullable', 'integer', 'min:0', 'max:100'],
            'PruebaRecuperacion' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $infoId = (int) $validated['infoestudiantesifas_id'];
        $materiaId = (int) $validated['materias_id'];

        $infoInstitucionId = $this->getInstitucionIdFromInfo($infoId);
        $materiaInstitucionId = $this->getInstitucionIdFromMateria($materiaId);

        if (!$infoInstitucionId || !$materiaInstitucionId) {
            return response()->json(['message' => 'Inscripción o materia no encontrada'], 404);
        }

        if ($infoInstitucionId !== $materiaInstitucionId) {
            return response()->json(['message' => 'La inscripción y la materia pertenecen a instituciones distintas'], 403);
        }

        if (!$isSuperAdmin && (int) $user->instituciones_id !== (int) $infoInstitucionId) {
            return response()->json(['message' => 'Acceso no permitido'], 403);
        }

        $created = Calificaciones::create($validated);
        return response()->json(['data' => $created], 201);
    }
    
    public function show($id)
    {
        $user = request()->user();
        if (!$user) {
            abort(404);
        }

        $isSuperAdmin = $this->isSuperAdmin($user);

        $row = Calificaciones::query()
            ->join('infoestudiantesifas', 'calificaciones.infoestudiantesifas_id', '=', 'infoestudiantesifas.id')
            ->join('materias', 'calificaciones.materias_id', '=', 'materias.id')
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->where('calificaciones.id', (int) $id)
            ->select([
                'calificaciones.*',
                'calificaciones.materias_id as materias_id',
                'infoestudiantesifas.instituciones_id as info_instituciones_id',
                'carreras.instituciones_id as materia_instituciones_id',
            ])
            ->first();

        if (!$row) {
            abort(404);
        }

        $infoInst = (int) ($row->info_instituciones_id ?? 0);
        $matInst = (int) ($row->materia_instituciones_id ?? 0);
        if ($infoInst <= 0 || $matInst <= 0 || $infoInst !== $matInst) {
            abort(404);
        }

        if (!$isSuperAdmin && (int) $user->instituciones_id !== $infoInst) {
            abort(404);
        }

        $this->ensureDocenteAsignado($user, (int) ($row->materias_id ?? 0));

        unset($row->info_instituciones_id, $row->materia_instituciones_id);
        return response()->json(['data' => $row]);
    }
    
    
    public function update(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(404);
        }

        $isSuperAdmin = $this->isSuperAdmin($user);

        $validated = $request->validate([
            'id' => ['required', 'integer'],
            'EstadoRegistroMateria' => ['nullable', 'string', 'max:50'],
            'Teorico1' => ['nullable', 'integer', 'min:0', 'max:100'],
            'Teorico2' => ['nullable', 'integer', 'min:0', 'max:100'],
            'Teorico3' => ['nullable', 'integer', 'min:0', 'max:100'],
            'Teorico4' => ['nullable', 'integer', 'min:0', 'max:100'],
            'Practico1' => ['nullable', 'integer', 'min:0', 'max:100'],
            'Practico2' => ['nullable', 'integer', 'min:0', 'max:100'],
            'Practico3' => ['nullable', 'integer', 'min:0', 'max:100'],
            'Practico4' => ['nullable', 'integer', 'min:0', 'max:100'],
            'PruebaRecuperacion' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $id = (int) $validated['id'];

        $row = Calificaciones::query()
            ->join('infoestudiantesifas', 'calificaciones.infoestudiantesifas_id', '=', 'infoestudiantesifas.id')
            ->join('materias', 'calificaciones.materias_id', '=', 'materias.id')
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->where('calificaciones.id', $id)
            ->select([
                'calificaciones.id',
                'calificaciones.materias_id as materias_id',
                'infoestudiantesifas.instituciones_id as info_instituciones_id',
                'carreras.instituciones_id as materia_instituciones_id',
            ])
            ->first();

        if (!$row) {
            abort(404);
        }

        $infoInst = (int) ($row->info_instituciones_id ?? 0);
        $matInst = (int) ($row->materia_instituciones_id ?? 0);
        if ($infoInst <= 0 || $matInst <= 0 || $infoInst !== $matInst) {
            abort(404);
        }

        if (!$isSuperAdmin && (int) $user->instituciones_id !== $infoInst) {
            abort(404);
        }

        $this->ensureDocenteAsignado($user, (int) ($row->materias_id ?? 0));

        $payload = $validated;
        unset($payload['id']);

        Calificaciones::query()->where('id', $id)->update($payload);

        return response()->json(['data' => ['updated' => true]]);
    }

    public function updateEstadoRegistro(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario inválido'], 422);
        }

        $isSuperAdmin = $this->isSuperAdmin($user);

        $validated = $request->validate([
            'id' => ['required', 'integer'],
            'EstadoRegistroMateria' => ['nullable', 'string', 'max:50'],
        ]);

        $id = (int) $validated['id'];

        $row = Calificaciones::query()
            ->join('infoestudiantesifas', 'calificaciones.infoestudiantesifas_id', '=', 'infoestudiantesifas.id')
            ->join('materias', 'calificaciones.materias_id', '=', 'materias.id')
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->where('calificaciones.id', $id)
            ->select([
                'calificaciones.id',
                'calificaciones.materias_id as materias_id',
                'infoestudiantesifas.instituciones_id as info_instituciones_id',
                'carreras.instituciones_id as materia_instituciones_id',
            ])
            ->first();

        if (!$row) {
            return response()->json(['message' => 'Registro no encontrado'], 404);
        }

        $infoInst = (int) ($row->info_instituciones_id ?? 0);
        $matInst = (int) ($row->materia_instituciones_id ?? 0);
        if ($infoInst <= 0 || $matInst <= 0 || $infoInst !== $matInst) {
            return response()->json(['message' => 'Registro inválido'], 422);
        }

        if (!$isSuperAdmin && (int) $user->instituciones_id !== $infoInst) {
            return response()->json(['message' => 'Acceso no permitido'], 403);
        }

        $this->ensureDocenteAsignado($user, (int) ($row->materias_id ?? 0));

        Calificaciones::query()
            ->where('id', $id)
            ->update([
                'EstadoRegistroMateria' => $validated['EstadoRegistroMateria'] ?? null,
            ]);

        return response()->json(['data' => ['updated' => true]]);
    }

    public function assign(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario inválido'], 422);
        }

        $isSuperAdmin = $this->isSuperAdmin($user);

        $validated = $request->validate([
            'infoestudiantesifas_id' => ['required', 'integer'],
            'materias_id' => ['required', 'integer'],
            'EstadoRegistroMateria' => ['nullable', 'string', 'max:50'],
            'forzar' => ['sometimes', 'boolean'],
        ]);

        $forzar = (bool) ($validated['forzar'] ?? false);

        $info = Infoestudiantesifas::query()
            ->where('id', $validated['infoestudiantesifas_id'])
            ->first();

        if (!$info) {
            return response()->json(['message' => 'Inscripción no encontrada'], 404);
        }

        $infoInstitucionId = (int) ($info->instituciones_id ?? 0);
        if ($infoInstitucionId <= 0) {
            return response()->json(['message' => 'Inscripción inválida'], 422);
        }

        if (!$isSuperAdmin && (int) $user->instituciones_id !== (int) $infoInstitucionId) {
            return response()->json(['message' => 'Inscripción no pertenece a la institución'], 403);
        }

        $materiaRow = Materias::query()
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->where('materias.id', $validated['materias_id'])
            ->select([
                'materias.id',
                'materias.Paralelo',
                'plandeestudios.LvlCurso',
                'carreras.instituciones_id as instituciones_id',
            ])
            ->first();

        if (!$materiaRow) {
            return response()->json(['message' => 'Materia no encontrada'], 404);
        }

        $materiaInstitucionId = (int) ($materiaRow->instituciones_id ?? 0);
        if ($materiaInstitucionId <= 0) {
            return response()->json(['message' => 'Materia inválida'], 422);
        }

        if ($materiaInstitucionId !== $infoInstitucionId) {
            return response()->json(['message' => 'La materia no pertenece a la institución de la inscripción'], 403);
        }

        if (!$forzar) {
            if (!empty($info->Curso_Solicitado) && !empty($materiaRow->LvlCurso) && $info->Curso_Solicitado !== $materiaRow->LvlCurso) {
                return response()->json(['message' => 'La materia no corresponde al curso solicitado'], 422);
            }

            if (!empty($info->Paralelo_Solicitado) && !empty($materiaRow->Paralelo) && $info->Paralelo_Solicitado !== $materiaRow->Paralelo) {
                return response()->json(['message' => 'La materia no corresponde al paralelo solicitado'], 422);
            }
        }

        DB::beginTransaction();
        try {
            $assignment = Calificaciones::query()->firstOrCreate(
                [
                    'infoestudiantesifas_id' => $validated['infoestudiantesifas_id'],
                    'materias_id' => $validated['materias_id'],
                ],
                [
                    'EstadoRegistroMateria' => $validated['EstadoRegistroMateria'] ?? null,
                ]
            );

            $count = Calificaciones::query()
                ->where('infoestudiantesifas_id', $validated['infoestudiantesifas_id'])
                ->count();

            $info->CantidadMateriasAsignadas = $count;
            $info->save();

            DB::commit();
            return response()->json(['data' => $assignment]);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function unassign(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario inválido'], 422);
        }

        $isSuperAdmin = $this->isSuperAdmin($user);

        $validated = $request->validate([
            'infoestudiantesifas_id' => ['required', 'integer'],
            'materias_id' => ['required', 'integer'],
        ]);

        $info = Infoestudiantesifas::query()
            ->where('id', $validated['infoestudiantesifas_id'])
            ->first();

        if (!$info) {
            return response()->json(['message' => 'Inscripción no encontrada'], 404);
        }

        $infoInstitucionId = (int) ($info->instituciones_id ?? 0);
        if ($infoInstitucionId <= 0) {
            return response()->json(['message' => 'Inscripción inválida'], 422);
        }

        if (!$isSuperAdmin && (int) $user->instituciones_id !== (int) $infoInstitucionId) {
            return response()->json(['message' => 'Inscripción no pertenece a la institución'], 403);
        }

        $materiaInstitucionId = $this->getInstitucionIdFromMateria((int) $validated['materias_id']);
        if (!$materiaInstitucionId) {
            return response()->json(['message' => 'Materia no encontrada'], 404);
        }
        if ((int) $materiaInstitucionId !== (int) $infoInstitucionId) {
            return response()->json(['message' => 'La materia no pertenece a la institución de la inscripción'], 403);
        }

        DB::beginTransaction();
        try {
            $deleted = Calificaciones::query()
                ->where('infoestudiantesifas_id', $validated['infoestudiantesifas_id'])
                ->where('materias_id', $validated['materias_id'])
                ->delete();

            $count = Calificaciones::query()
                ->where('infoestudiantesifas_id', $validated['infoestudiantesifas_id'])
                ->count();

            $info->CantidadMateriasAsignadas = $count;
            $info->save();

            DB::commit();

            return response()->json(['data' => ['deleted' => $deleted]]);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function assignBulkCurso(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario inválido'], 422);
        }

        $isSuperAdmin = $this->isSuperAdmin($user);

        $validated = $request->validate([
            'infoestudiantesifas_id' => ['required', 'integer'],
            'curso' => ['nullable', 'string', 'max:60'],
            'forzar' => ['sometimes', 'boolean'],
            'all_paralelos' => ['sometimes', 'boolean'],
            'EstadoRegistroMateria' => ['nullable', 'string', 'max:50'],
        ]);

        $info = Infoestudiantesifas::query()
            ->where('id', $validated['infoestudiantesifas_id'])
            ->first();

        if (!$info) {
            return response()->json(['message' => 'Inscripción no encontrada'], 404);
        }

        $infoInstitucionId = (int) ($info->instituciones_id ?? 0);
        if ($infoInstitucionId <= 0) {
            return response()->json(['message' => 'Inscripción inválida'], 422);
        }
        if (!$isSuperAdmin && (int) $user->instituciones_id !== (int) $infoInstitucionId) {
            return response()->json(['message' => 'Inscripción no pertenece a la institución'], 403);
        }

        $curso = trim((string) ($validated['curso'] ?? $info->Curso_Solicitado ?? ''));
        if ($curso === '') {
            return response()->json(['message' => 'Curso no definido para asignación masiva'], 422);
        }

        $allParalelos = (bool) ($validated['all_paralelos'] ?? false);

        $materiasQuery = Materias::query()
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->where('carreras.instituciones_id', $infoInstitucionId)
            ->where('plandeestudios.LvlCurso', $curso)
            ->select(['materias.id', 'materias.Paralelo']);

        if (!$allParalelos && !empty($info->Paralelo_Solicitado)) {
            $materiasQuery->where('materias.Paralelo', $info->Paralelo_Solicitado);
        }

        $materiasIds = $materiasQuery->pluck('materias.id')->values();

        if ($materiasIds->count() === 0) {
            return response()->json(['data' => ['created' => 0, 'total_materias' => 0]]);
        }

        $existing = Calificaciones::query()
            ->where('infoestudiantesifas_id', $validated['infoestudiantesifas_id'])
            ->whereIn('materias_id', $materiasIds)
            ->pluck('materias_id')
            ->map(fn($v) => (int) $v)
            ->all();
        $existingSet = array_flip($existing);

        $created = 0;

        DB::beginTransaction();
        try {
            foreach ($materiasIds as $mid) {
                $mid = (int) $mid;
                if (isset($existingSet[$mid])) {
                    continue;
                }

                Calificaciones::create([
                    'infoestudiantesifas_id' => (int) $validated['infoestudiantesifas_id'],
                    'materias_id' => $mid,
                    'EstadoRegistroMateria' => $validated['EstadoRegistroMateria'] ?? null,
                ]);
                $created++;
            }

            $count = Calificaciones::query()
                ->where('infoestudiantesifas_id', $validated['infoestudiantesifas_id'])
                ->count();

            $info->CantidadMateriasAsignadas = $count;
            $info->save();

            DB::commit();
            return response()->json(['data' => ['created' => $created, 'total_materias' => $materiasIds->count(), 'total_asignadas' => $count]]);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function assignBulkCategoria(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario inválido'], 422);
        }

        $isSuperAdmin = $this->isSuperAdmin($user);

        $validated = $request->validate([
            'infoestudiantesifas_id' => ['required', 'integer'],
            'curso' => ['required', 'string', 'max:60'],
            'paralelo' => ['nullable', 'string', 'max:20'],
            'forzar' => ['sometimes', 'boolean'],
            'EstadoRegistroMateria' => ['nullable', 'string', 'max:50'],
        ]);

        $forzar = (bool) ($validated['forzar'] ?? false);

        $info = Infoestudiantesifas::query()
            ->where('id', $validated['infoestudiantesifas_id'])
            ->first();

        if (!$info) {
            return response()->json(['message' => 'Inscripción no encontrada'], 404);
        }

        $infoInstitucionId = (int) ($info->instituciones_id ?? 0);
        if ($infoInstitucionId <= 0) {
            return response()->json(['message' => 'Inscripción inválida'], 422);
        }
        if (!$isSuperAdmin && (int) $user->instituciones_id !== (int) $infoInstitucionId) {
            return response()->json(['message' => 'Inscripción no pertenece a la institución'], 403);
        }

        $curso = trim((string) ($validated['curso'] ?? ''));
        if ($curso === '') {
            return response()->json(['message' => 'Curso no definido para asignación masiva'], 422);
        }

        $paralelo = trim((string) ($validated['paralelo'] ?? ''));

        $materiasQuery = Materias::query()
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->where('carreras.instituciones_id', $infoInstitucionId)
            ->where('plandeestudios.LvlCurso', $curso)
            ->select(['materias.id', 'materias.Paralelo']);

        if ($paralelo !== '') {
            $materiasQuery->where('materias.Paralelo', $paralelo);
        } elseif (!$forzar && !empty($info->Paralelo_Solicitado)) {
            $materiasQuery->where('materias.Paralelo', $info->Paralelo_Solicitado);
        }

        $materiasIds = $materiasQuery->pluck('materias.id')->values();

        if ($materiasIds->count() === 0) {
            return response()->json(['data' => ['created' => 0, 'total_materias' => 0]]);
        }

        $existing = Calificaciones::query()
            ->where('infoestudiantesifas_id', $validated['infoestudiantesifas_id'])
            ->whereIn('materias_id', $materiasIds)
            ->pluck('materias_id')
            ->map(fn($v) => (int) $v)
            ->all();
        $existingSet = array_flip($existing);

        $rowsToInsert = [];
        foreach ($materiasIds as $mid) {
            $mid = (int) $mid;
            if (isset($existingSet[$mid])) {
                continue;
            }
            $rowsToInsert[] = [
                'infoestudiantesifas_id' => (int) $validated['infoestudiantesifas_id'],
                'materias_id' => $mid,
                'EstadoRegistroMateria' => $validated['EstadoRegistroMateria'] ?? null,
            ];
        }

        DB::beginTransaction();
        try {
            $created = 0;
            if (count($rowsToInsert) > 0) {
                // Insert masivo; evita N requests desde el frontend.
                DB::table('calificaciones')->insert($rowsToInsert);
                $created = count($rowsToInsert);
            }

            $count = Calificaciones::query()
                ->where('infoestudiantesifas_id', $validated['infoestudiantesifas_id'])
                ->count();

            $info->CantidadMateriasAsignadas = $count;
            $info->save();

            DB::commit();
            return response()->json([
                'data' => [
                    'created' => $created,
                    'total_materias' => $materiasIds->count(),
                    'total_asignadas' => $count,
                ],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function unassignBulkCategoria(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario inválido'], 422);
        }

        $isSuperAdmin = $this->isSuperAdmin($user);

        $validated = $request->validate([
            'infoestudiantesifas_id' => ['required', 'integer'],
            'curso' => ['required', 'string', 'max:60'],
            'paralelo' => ['nullable', 'string', 'max:20'],
            'forzar' => ['sometimes', 'boolean'],
        ]);

        $forzar = (bool) ($validated['forzar'] ?? false);

        $info = Infoestudiantesifas::query()
            ->where('id', $validated['infoestudiantesifas_id'])
            ->first();

        if (!$info) {
            return response()->json(['message' => 'Inscripción no encontrada'], 404);
        }

        $infoInstitucionId = (int) ($info->instituciones_id ?? 0);
        if ($infoInstitucionId <= 0) {
            return response()->json(['message' => 'Inscripción inválida'], 422);
        }
        if (!$isSuperAdmin && (int) $user->instituciones_id !== (int) $infoInstitucionId) {
            return response()->json(['message' => 'Inscripción no pertenece a la institución'], 403);
        }

        $curso = trim((string) ($validated['curso'] ?? ''));
        if ($curso === '') {
            return response()->json(['message' => 'Curso no definido para desasignación masiva'], 422);
        }

        $paralelo = trim((string) ($validated['paralelo'] ?? ''));

        $materiasQuery = Materias::query()
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->where('carreras.instituciones_id', $infoInstitucionId)
            ->where('plandeestudios.LvlCurso', $curso)
            ->select(['materias.id', 'materias.Paralelo']);

        if ($paralelo !== '') {
            $materiasQuery->where('materias.Paralelo', $paralelo);
        } elseif (!$forzar && !empty($info->Paralelo_Solicitado)) {
            $materiasQuery->where('materias.Paralelo', $info->Paralelo_Solicitado);
        }

        $materiasIds = $materiasQuery->pluck('materias.id')->values();

        if ($materiasIds->count() === 0) {
            return response()->json(['data' => ['deleted' => 0, 'total_materias' => 0]]);
        }

        DB::beginTransaction();
        try {
            $deleted = Calificaciones::query()
                ->where('infoestudiantesifas_id', $validated['infoestudiantesifas_id'])
                ->whereIn('materias_id', $materiasIds)
                ->delete();

            $count = Calificaciones::query()
                ->where('infoestudiantesifas_id', $validated['infoestudiantesifas_id'])
                ->count();

            $info->CantidadMateriasAsignadas = $count;
            $info->save();

            DB::commit();
            return response()->json([
                'data' => [
                    'deleted' => $deleted,
                    'total_materias' => $materiasIds->count(),
                    'total_asignadas' => $count,
                ],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function assignBulkAnioResolucion(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario inválido'], 422);
        }

        $isSuperAdmin = $this->isSuperAdmin($user);

        $validated = $request->validate([
            'infoestudiantesifas_id' => ['required', 'integer'],
            'anio_id' => ['required', 'integer'],
            'resolucion' => ['required', 'string', 'max:50'],
            'EstadoRegistroMateria' => ['nullable', 'string', 'max:50'],
        ]);

        $info = Infoestudiantesifas::query()
            ->where('id', $validated['infoestudiantesifas_id'])
            ->first();

        if (!$info) {
            return response()->json(['message' => 'Inscripción no encontrada'], 404);
        }

        $infoInstitucionId = (int) ($info->instituciones_id ?? 0);
        if ($infoInstitucionId <= 0) {
            return response()->json(['message' => 'Inscripción inválida'], 422);
        }
        if (!$isSuperAdmin && (int) $user->instituciones_id !== (int) $infoInstitucionId) {
            return response()->json(['message' => 'Inscripción no pertenece a la institución'], 403);
        }

        $anioId = (int) $validated['anio_id'];
        $resolucion = trim((string) ($validated['resolucion'] ?? ''));
        if ($anioId <= 0 || $resolucion === '') {
            return response()->json(['message' => 'Año o Resolución no definidos para asignación masiva'], 422);
        }

        $materiasIds = Materias::query()
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->where('carreras.instituciones_id', $infoInstitucionId)
            ->where('plandeestudios.anio_id', $anioId)
            ->where('carreras.Resolucion', $resolucion)
            ->pluck('materias.id')
            ->values();

        if ($materiasIds->count() === 0) {
            return response()->json(['data' => ['created' => 0, 'total_materias' => 0]]);
        }

        $existing = Calificaciones::query()
            ->where('infoestudiantesifas_id', $validated['infoestudiantesifas_id'])
            ->whereIn('materias_id', $materiasIds)
            ->pluck('materias_id')
            ->map(fn($v) => (int) $v)
            ->all();
        $existingSet = array_flip($existing);

        $rowsToInsert = [];
        foreach ($materiasIds as $mid) {
            $mid = (int) $mid;
            if (isset($existingSet[$mid])) {
                continue;
            }
            $rowsToInsert[] = [
                'infoestudiantesifas_id' => (int) $validated['infoestudiantesifas_id'],
                'materias_id' => $mid,
                'EstadoRegistroMateria' => $validated['EstadoRegistroMateria'] ?? null,
            ];
        }

        DB::beginTransaction();
        try {
            $created = 0;
            if (count($rowsToInsert) > 0) {
                DB::table('calificaciones')->insert($rowsToInsert);
                $created = count($rowsToInsert);
            }

            $count = Calificaciones::query()
                ->where('infoestudiantesifas_id', $validated['infoestudiantesifas_id'])
                ->count();

            $info->CantidadMateriasAsignadas = $count;
            $info->save();

            DB::commit();
            return response()->json([
                'data' => [
                    'created' => $created,
                    'total_materias' => $materiasIds->count(),
                    'total_asignadas' => $count,
                ],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function unassignBulkAnioResolucion(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario inválido'], 422);
        }

        $isSuperAdmin = $this->isSuperAdmin($user);

        $validated = $request->validate([
            'infoestudiantesifas_id' => ['required', 'integer'],
            'anio_id' => ['required', 'integer'],
            'resolucion' => ['required', 'string', 'max:50'],
        ]);

        $info = Infoestudiantesifas::query()
            ->where('id', $validated['infoestudiantesifas_id'])
            ->first();

        if (!$info) {
            return response()->json(['message' => 'Inscripción no encontrada'], 404);
        }

        $infoInstitucionId = (int) ($info->instituciones_id ?? 0);
        if ($infoInstitucionId <= 0) {
            return response()->json(['message' => 'Inscripción inválida'], 422);
        }
        if (!$isSuperAdmin && (int) $user->instituciones_id !== (int) $infoInstitucionId) {
            return response()->json(['message' => 'Inscripción no pertenece a la institución'], 403);
        }

        $anioId = (int) $validated['anio_id'];
        $resolucion = trim((string) ($validated['resolucion'] ?? ''));
        if ($anioId <= 0 || $resolucion === '') {
            return response()->json(['message' => 'Año o Resolución no definidos para desasignación masiva'], 422);
        }

        $materiasIds = Materias::query()
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->where('carreras.instituciones_id', $infoInstitucionId)
            ->where('plandeestudios.anio_id', $anioId)
            ->where('carreras.Resolucion', $resolucion)
            ->pluck('materias.id')
            ->values();

        if ($materiasIds->count() === 0) {
            return response()->json(['data' => ['deleted' => 0, 'total_materias' => 0]]);
        }

        DB::beginTransaction();
        try {
            $deleted = Calificaciones::query()
                ->where('infoestudiantesifas_id', $validated['infoestudiantesifas_id'])
                ->whereIn('materias_id', $materiasIds)
                ->delete();

            $count = Calificaciones::query()
                ->where('infoestudiantesifas_id', $validated['infoestudiantesifas_id'])
                ->count();

            $info->CantidadMateriasAsignadas = $count;
            $info->save();

            DB::commit();
            return response()->json([
                'data' => [
                    'deleted' => $deleted,
                    'total_materias' => $materiasIds->count(),
                    'total_asignadas' => $count,
                ],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function unassignAll(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario inválido'], 422);
        }

        $isSuperAdmin = $this->isSuperAdmin($user);

        $validated = $request->validate([
            'infoestudiantesifas_id' => ['required', 'integer'],
        ]);

        $info = Infoestudiantesifas::query()
            ->where('id', $validated['infoestudiantesifas_id'])
            ->first();

        if (!$info) {
            return response()->json(['message' => 'Inscripción no encontrada'], 404);
        }

        $infoInstitucionId = (int) ($info->instituciones_id ?? 0);
        if ($infoInstitucionId <= 0) {
            return response()->json(['message' => 'Inscripción inválida'], 422);
        }
        if (!$isSuperAdmin && (int) $user->instituciones_id !== (int) $infoInstitucionId) {
            return response()->json(['message' => 'Inscripción no pertenece a la institución'], 403);
        }

        DB::beginTransaction();
        try {
            $deleted = Calificaciones::query()
                ->where('infoestudiantesifas_id', $validated['infoestudiantesifas_id'])
                ->delete();

            $info->CantidadMateriasAsignadas = 0;
            $info->save();

            DB::commit();
            return response()->json(['data' => ['deleted' => $deleted]]);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
    
    public function destroy($id)
    {
        $user = request()->user();
        if (!$user) {
            abort(404);
        }

        $isSuperAdmin = $this->isSuperAdmin($user);

        $row = Calificaciones::query()
            ->join('infoestudiantesifas', 'calificaciones.infoestudiantesifas_id', '=', 'infoestudiantesifas.id')
            ->join('materias', 'calificaciones.materias_id', '=', 'materias.id')
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->where('calificaciones.id', (int) $id)
            ->select([
                'calificaciones.id',
                'infoestudiantesifas.instituciones_id as info_instituciones_id',
                'carreras.instituciones_id as materia_instituciones_id',
            ])
            ->first();

        if (!$row) {
            abort(404);
        }

        $infoInst = (int) ($row->info_instituciones_id ?? 0);
        $matInst = (int) ($row->materia_instituciones_id ?? 0);
        if ($infoInst <= 0 || $matInst <= 0 || $infoInst !== $matInst) {
            abort(404);
        }

        if (!$isSuperAdmin && (int) $user->instituciones_id !== $infoInst) {
            abort(404);
        }

        Calificaciones::query()->where('id', (int) $id)->delete();
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }

    /**
     * Para la regla de 2da instancia: devuelve un mapa de infoestudiantesifas_id => conteo de materias
     * reprobadas y materias pendientes (sin completar todas las evaluaciones).
     * Solo cuenta materias de la misma gestión (anio_id) e institución.
     */
    public function reprobadasPorMateria(Request $request, $materiaId)
    {
        $user = $request->user();

        if (!$user) {
            abort(404);
        }

        $isSuperAdmin = $this->isSuperAdmin($user);

        $materiaId = (int) $materiaId;

        if ($materiaId <= 0) {
            abort(404);
        }

        $materiaMeta = DB::table('materias')
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->where('materias.id', $materiaId)
            ->select([
                'plandeestudios.anio_id',
                'carreras.instituciones_id',
                'carreras.NotaAprobacion',
            ])
            ->first();

        if (!$materiaMeta) {
            abort(404);
        }

        $institucionId = (int) $materiaMeta->instituciones_id;

        if (
            !$isSuperAdmin &&
            (int) $user->instituciones_id !== $institucionId
        ) {
            abort(404);
        }

        $anioId = (int) $materiaMeta->anio_id;
        $notaAprobacion = (int) ($materiaMeta->NotaAprobacion ?? 61);

        /*
        * Estudiantes inscritos en la materia seleccionada
        */
        $infoIds = Calificaciones::query()
            ->where('materias_id', $materiaId)
            ->pluck('infoestudiantesifas_id')
            ->unique()
            ->values();

        if ($infoIds->isEmpty()) {
            return response()->json([
                'data' => []
            ]);
        }

        /*
        * Todas las materias de esos estudiantes
        * dentro de la misma gestión académica
        */
        $allCalifs = Calificaciones::query()
            ->join('materias', 'calificaciones.materias_id', '=', 'materias.id')
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->where('carreras.instituciones_id', $institucionId)
            ->where('plandeestudios.anio_id', $anioId)
            ->whereIn('calificaciones.infoestudiantesifas_id', $infoIds)
            ->select([
                'calificaciones.infoestudiantesifas_id',
                'calificaciones.materias_id',
                'calificaciones.Promedio',
                'calificaciones.PruebaRecuperacion',
                'calificaciones.EstadoRegistroMateria',
            ])
            ->get();

        $result = [];

        $grouped = $allCalifs->groupBy('infoestudiantesifas_id');

        foreach ($grouped as $infoId => $califs) {

            $reprobadas = 0;
            $pendientes = 0;
            $totalMaterias = $califs->count();

            foreach ($califs as $cal) {

                /*
                * Si no tiene promedio aún
                * entonces está pendiente.
                */
                if ($cal->Promedio === null) {
                    $pendientes++;
                    continue;
                }

                /*
                * Si rindió recuperación,
                * esa es la nota final.
                */
                $notaFinal = $cal->PruebaRecuperacion !== null
                    ? (int) $cal->PruebaRecuperacion
                    : (int) $cal->Promedio;

                if ($notaFinal < $notaAprobacion) {
                    $reprobadas++;
                }
            }

            $result[$infoId] = [
                'reprobadas'    => $reprobadas,
                'pendientes'    => $pendientes,
                'total_materias'=> $totalMaterias,
            ];
        }

        return response()->json([
            'data' => $result
        ]);
    }
    //#endregion Fin Controller de Crud PHP de calificaciones
}
