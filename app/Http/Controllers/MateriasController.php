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
    public function index()
    {
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

        if (request()->filled('instituciones_id')) {
            $query->where('carreras.instituciones_id', request()->get('instituciones_id'));
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

        if (!empty($info->Curso_Solicitado)) {
            $query->where('plandeestudios.LvlCurso', $info->Curso_Solicitado);
        }

        if (!empty($info->Paralelo_Solicitado)) {
            $query->where('materias.Paralelo', $info->Paralelo_Solicitado);
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
