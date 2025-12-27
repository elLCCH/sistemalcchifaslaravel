<?php

namespace App\Http\Controllers;

use App\Models\materias;
use App\Models\infoestudiantesifas;
use Illuminate\Http\Request;

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
        $query = materias::query()
            ->select([
            'materias.*',
            'plandeestudios.NombreMateria',
            'plandeestudios.SiglaMateria',
            'plandeestudios.LvlCurso',
            'carreras.Resolucion',
            'carreras.NombreCarrera',
            'instituciones.Nombre as NombreInstitucion',
            'anios.Anio',
            ])
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->join('instituciones', 'carreras.instituciones_id', '=', 'instituciones.id')
            ->leftJoin('anios', 'plandeestudios.anio_id', '=', 'anios.id');

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
        if (!$user || !$user->instituciones_id) {
            abort(404);
        }

        $info = infoestudiantesifas::query()
            ->where('id', $infoId)
            ->where('instituciones_id', $user->instituciones_id)
            ->firstOrFail();

        $query = materias::query()
            ->select([
                'materias.*',
                'plandeestudios.NombreMateria',
                'plandeestudios.SiglaMateria',
                'plandeestudios.LvlCurso',
                'plandeestudios.RangoLvlCurso',
                'plandeestudios.Rango',
                'carreras.Resolucion',
                'carreras.NombreCarrera',
                'instituciones.Nombre as NombreInstitucion',
                'anios.Anio',
            ])
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->join('instituciones', 'carreras.instituciones_id', '=', 'instituciones.id')
            ->leftJoin('anios', 'plandeestudios.anio_id', '=', 'anios.id')
            ->where('carreras.instituciones_id', $user->instituciones_id);

        // NUEVO: filtros por Año (anios.id) y Resolución (carreras.Resolucion)
        // Se priorizan estos filtros para evitar cargar demasiada información con el tiempo.
        $anioId = $request->query('anio_id');
        $resolucion = trim((string) $request->query('resolucion', ''));

        $all = (string) $request->query('all', '0');
        $modoAll = in_array(strtolower($all), ['1', 'true', 'si', 'yes'], true);

        if ($modoAll) {
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
