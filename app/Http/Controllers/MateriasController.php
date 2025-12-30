<?php

namespace App\Http\Controllers;

use App\Models\materias;
use App\Models\infoestudiantesifas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;
class MateriasController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }
    //controllerPHPlcch materias, $
    //#region Inicio Controller de Crud PHP de materias
    public function index(Request $request)
    {
        $user = $request->user();
        $isSuperAdmin = empty($user?->instituciones_id);
        $query = materias::query()
            ->select([
            'materias.*',
            'plandeestudios.NombreMateria',
            'plandeestudios.SiglaMateria',
            'plandeestudios.ModoMateria',
            'plandeestudios.LvlCurso',
            'carreras.Resolucion',
            'carreras.NombreCarrera',
            $isSuperAdmin ? 'instituciones.Nombre as NombreInstitucion' : DB::raw('NULL as NombreInstitucion'),
            'anios.Anio',
            ])
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->join('instituciones', 'carreras.instituciones_id', '=', 'instituciones.id')
            ->leftJoin('anios', 'plandeestudios.anio_id', '=', 'anios.id')
            ->orderBy('plandeestudios.LvlCurso')
            ->orderBy('materias.Paralelo')
            ->orderBy('plandeestudios.Rango');

        // Por seguridad/volumen: si el usuario tiene institución, filtrar por esa institución
        // a menos que se pida explícitamente otra (casos administrativos).
        if ($request->filled('instituciones_id')) {
            $query->where('carreras.instituciones_id', $request->get('instituciones_id'));
        } elseif (!empty($user?->instituciones_id)) {
            $query->where('carreras.instituciones_id', $user->instituciones_id);
        }

        // Filtros opcionales
        $anioId = $request->query('anio_id');
        $resolucion = trim((string) $request->query('resolucion', ''));

        if ($anioId !== null && $anioId !== '' && (int) $anioId > 0) {
            $query->where('plandeestudios.anio_id', (int) $anioId);
        }

        if ($resolucion !== '') {
            $query->where('carreras.Resolucion', $resolucion);
        }

        $materias = $query->get();

        return response()->json(['data' => $materias]);
    }

    public function byInfo(Request $request, $infoId)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $isSuperAdmin = empty($user?->instituciones_id);

        $info = infoestudiantesifas::query()
            ->where('id', $infoId)
            ->when(!$isSuperAdmin, function ($q) use ($user) {
                $q->where('instituciones_id', $user->instituciones_id);
            })
            ->firstOrFail();

        $institucionId = (int) ($info->instituciones_id ?? 0);
        if ($institucionId <= 0) {
            abort(404);
        }

        $query = materias::query()
            ->select([
                'materias.*',
                'plandeestudios.NombreMateria',
                'plandeestudios.SiglaMateria',
                'plandeestudios.ModoMateria',
                'plandeestudios.LvlCurso',
                'plandeestudios.RangoLvlCurso',
                'plandeestudios.Rango',
                'carreras.Resolucion',
                'carreras.NombreCarrera',
                DB::raw('NULL as NombreInstitucion'),
                'anios.Anio',
            ])
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->join('instituciones', 'carreras.instituciones_id', '=', 'instituciones.id')
            ->leftJoin('anios', 'plandeestudios.anio_id', '=', 'anios.id')
            ->where('carreras.instituciones_id', $institucionId);

        // NUEVO: filtros por Año (anios.id) y Resolución (carreras.Resolucion)
        // Se priorizan estos filtros para evitar cargar demasiada información con el tiempo.
        $anioId = $request->query('anio_id');
        $resolucion = trim((string) $request->query('resolucion', ''));

        $all = (string) $request->query('all', '0');
        $modoAll = in_array(strtolower($all), ['1', 'true', 'si', 'yes'], true);

        if ($modoAll) {
            // En modo manual, se muestran TODAS las materias del Año/Resolución (global) seleccionados.
            // Esto evita traer todo el universo de la institución.
            if ($anioId !== null && $anioId !== '' && (int) $anioId > 0) {
                $query->where('plandeestudios.anio_id', (int) $anioId);
            }

            if ($resolucion !== '') {
                $query->where('carreras.Resolucion', $resolucion);
            }

            $materias = $query
                ->orderBy('plandeestudios.RangoLvlCurso')
                ->orderBy('plandeestudios.Rango')
                ->orderBy('plandeestudios.NombreMateria')
                ->get();

            return response()->json(['data' => $materias]);
        }

        // Si se mandan filtros por anio/resolucion, se usan en lugar de Curso/Paralelo.
        if ($anioId !== null && $anioId !== '' && (int) $anioId > 0) {
            $query->where('plandeestudios.anio_id', (int) $anioId);
        }

        if ($resolucion !== '') {
            $query->where('carreras.Resolucion', $resolucion);
        }

        $usaFiltrosAnioResolucion = ($anioId !== null && $anioId !== '' && (int) $anioId > 0) || ($resolucion !== '');

        if (!$usaFiltrosAnioResolucion) {
            // Comportamiento anterior: filtrar por curso/paralelo de la inscripción.
            if (!empty($info->Curso_Solicitado)) {
                $query->where('plandeestudios.LvlCurso', $info->Curso_Solicitado);
            }

            if (!empty($info->Paralelo_Solicitado)) {
                $query->where('materias.Paralelo', $info->Paralelo_Solicitado);
            }
        }

        $materias = $query
            ->orderBy('plandeestudios.RangoLvlCurso')
            ->orderBy('plandeestudios.Rango')
            ->orderBy('plandeestudios.NombreMateria')
            ->get();

        return response()->json(['data' => $materias]);
    }

    private function resolveInstitucionId(Request $request, $user): ?int
    {
        $isSuperAdmin = empty($user?->instituciones_id);
        if (!$isSuperAdmin) {
            return !empty($user?->instituciones_id) ? (int) $user->instituciones_id : null;
        }
        return $request->filled('instituciones_id') ? (int) $request->get('instituciones_id') : null;
    }

    public function bulkUpdateParalelo(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $validated = $request->validate([
            'anio_id' => ['required', 'integer', 'min:1'],
            'resolucion' => ['required', 'string'],
            'lvlcurso' => ['required', 'string'],
            'paralelo_actual' => ['required', 'string'],
            'paralelo_nuevo' => ['required', 'string'],
            'instituciones_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $anioId = (int) $validated['anio_id'];
        $resolucion = trim((string) $validated['resolucion']);
        $lvlCurso = trim((string) $validated['lvlcurso']);
        $paraleloActual = trim((string) $validated['paralelo_actual']);
        $paraleloNuevo = trim((string) $validated['paralelo_nuevo']);

        if ($resolucion === '' || $lvlCurso === '' || $paraleloActual === '' || $paraleloNuevo === '') {
            return response()->json(['message' => 'Datos incompletos'], 422);
        }

        $institucionId = $this->resolveInstitucionId($request, $user);

        // IDs del grupo (curso+paralelo actual) dentro del filtro Año+Resolución (+ institución)
        $idsQuery = materias::query()
            ->select(['materias.id'])
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->where('plandeestudios.anio_id', $anioId)
            ->where('carreras.Resolucion', $resolucion)
            ->where('plandeestudios.LvlCurso', $lvlCurso)
            ->where('materias.Paralelo', $paraleloActual);

        if (!empty($institucionId)) {
            $idsQuery->where('carreras.instituciones_id', $institucionId);
        }

        $ids = $idsQuery->pluck('materias.id')->map(fn ($x) => (int) $x)->values();
        if ($ids->isEmpty()) {
            return response()->json(['message' => 'No hay materias para ese curso y paralelo'], 404);
        }

        // Validación global del filtro: no permitir que el paralelo nuevo ya exista en el mismo Año+Resolución (+ institución)
        $existsQuery = materias::query()
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->where('plandeestudios.anio_id', $anioId)
            ->where('carreras.Resolucion', $resolucion)
            ->where('materias.Paralelo', $paraleloNuevo)
            ->whereNotIn('materias.id', $ids->all());

        if (!empty($institucionId)) {
            $existsQuery->where('carreras.instituciones_id', $institucionId);
        }

        if ($existsQuery->exists()) {
            return response()->json(['message' => 'Ese paralelo ya existe para el mismo Año y Resolución'], 422);
        }

        $updated = materias::query()
            ->whereIn('id', $ids->all())
            ->update(['Paralelo' => $paraleloNuevo]);

        return response()->json(['updated' => $updated]);
    }

    public function bulkAddCursoParalelo(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $validated = $request->validate([
            'anio_id' => ['required', 'integer', 'min:1'],
            'resolucion' => ['required', 'string'],
            'lvlcurso' => ['required', 'string'],
            'paralelo_nuevo' => ['required', 'string'],
            'instituciones_id' => ['nullable', 'integer', 'min:1'],
            'EstadoHabilitacion' => ['nullable', 'string'],
            'EstadoEnvio' => ['nullable', 'string'],
        ]);

        $anioId = (int) $validated['anio_id'];
        $resolucion = trim((string) $validated['resolucion']);
        $lvlCurso = trim((string) $validated['lvlcurso']);
        $paraleloNuevo = trim((string) $validated['paralelo_nuevo']);

        if ($resolucion === '' || $lvlCurso === '' || $paraleloNuevo === '') {
            return response()->json(['message' => 'Datos incompletos'], 422);
        }

        $institucionId = $this->resolveInstitucionId($request, $user);

        // Validación global: paralelo no debe existir en el mismo Año+Resolución (+ institución)
        $existsQuery = materias::query()
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->where('plandeestudios.anio_id', $anioId)
            ->where('carreras.Resolucion', $resolucion)
            ->where('materias.Paralelo', $paraleloNuevo);

        if (!empty($institucionId)) {
            $existsQuery->where('carreras.instituciones_id', $institucionId);
        }

        if ($existsQuery->exists()) {
            return response()->json(['message' => 'Ese paralelo ya existe para el mismo Año y Resolución'], 422);
        }

        // Planes del curso para el filtro actual
        $planesQuery = DB::table('plandeestudios')
            ->select(['plandeestudios.id'])
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->where('plandeestudios.anio_id', $anioId)
            ->where('carreras.Resolucion', $resolucion)
            ->where('plandeestudios.LvlCurso', $lvlCurso);

        if (!empty($institucionId)) {
            $planesQuery->where('carreras.instituciones_id', $institucionId);
        }

        $planIds = $planesQuery->pluck('plandeestudios.id')->map(fn ($x) => (int) $x)->values();
        if ($planIds->isEmpty()) {
            return response()->json(['message' => 'No se encontraron planes de estudio para ese curso con el filtro actual'], 404);
        }

        $estadoHab = array_key_exists('EstadoHabilitacion', $validated) ? (string) ($validated['EstadoHabilitacion'] ?? '') : '';
        $estadoEnv = array_key_exists('EstadoEnvio', $validated) ? (string) ($validated['EstadoEnvio'] ?? '') : '';

        $rows = [];
        foreach ($planIds as $pid) {
            $rows[] = [
                'plandeestudios_id' => $pid,
                'Paralelo' => $paraleloNuevo,
                'EstadoHabilitacion' => $estadoHab,
                'EstadoEnvio' => $estadoEnv,
            ];
        }

        materias::insert($rows);

        return response()->json(['inserted' => count($rows)]);
    }

    public function bulkDeleteCursoParalelo(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $validated = $request->validate([
            'anio_id' => ['required', 'integer', 'min:1'],
            'resolucion' => ['required', 'string'],
            'lvlcurso' => ['required', 'string'],
            'paralelo_actual' => ['required', 'string'],
            'instituciones_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $anioId = (int) $validated['anio_id'];
        $resolucion = trim((string) $validated['resolucion']);
        $lvlCurso = trim((string) $validated['lvlcurso']);
        $paraleloActual = trim((string) $validated['paralelo_actual']);

        if ($resolucion === '' || $lvlCurso === '' || $paraleloActual === '') {
            return response()->json(['message' => 'Datos incompletos'], 422);
        }

        $institucionId = $this->resolveInstitucionId($request, $user);

        $idsQuery = materias::query()
            ->select(['materias.id'])
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->where('plandeestudios.anio_id', $anioId)
            ->where('carreras.Resolucion', $resolucion)
            ->where('plandeestudios.LvlCurso', $lvlCurso)
            ->where('materias.Paralelo', $paraleloActual);

        if (!empty($institucionId)) {
            $idsQuery->where('carreras.instituciones_id', $institucionId);
        }

        $ids = $idsQuery->pluck('materias.id')->map(fn ($x) => (int) $x)->values();
        if ($ids->isEmpty()) {
            return response()->json(['message' => 'No hay materias para ese curso y paralelo'], 404);
        }

        $deleted = materias::query()->whereIn('id', $ids->all())->delete();

        return response()->json(['deleted' => $deleted]);
    }
    
    
    public function store(Request $request)
    {
        $materias = $request->all();
        materias::insert($materias);
        return response()->json(['data' => $materias]);
    }
    
    public function show($id)
    {
        $materias = materias::where('id','=',$id)->firstOrFail();
        return response()->json(['data' => $materias]);
    }
    
    
    public function update(Request $request)
    {
        $materias = $request->all();
        materias::where('id','=',$request->id)->update($materias);
        return response()->json(['data' => $materias]);
    }
    
    public function destroy($id)
    {
        materias::destroy($id);
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
    //#endregion Fin Controller de Crud PHP de materias
}
