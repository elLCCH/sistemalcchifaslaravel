<?php

namespace App\Http\Controllers;

use App\Models\infoestudiantesifas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;
class InfoestudiantesifasController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }
    //controllerPHPlcch infoestudiantesifas, $
    //#region Inicio Controller de Crud PHP de infoestudiantesifas
    public function index()
    {
        $infoestudiantesifas = infoestudiantesifas::all();
        $user = request()->user();

        $query = infoestudiantesifas::query()
            ->leftJoin('instituciones', 'infoestudiantesifas.instituciones_id', '=', 'instituciones.id')
            ->leftJoin('estudiantesifas', 'infoestudiantesifas.estudiantesifas_id', '=', 'estudiantesifas.id')
            ->select([
                'infoestudiantesifas.*',
                'instituciones.Nombre as NombreInstitucion',
                'estudiantesifas.Ap_Paterno',
                'estudiantesifas.Ap_Materno',
                'estudiantesifas.Nombre',
                DB::raw("COALESCE((
                    SELECT MAX(a.Anio)
                    FROM calificaciones c
                    INNER JOIN materias m ON m.id = c.materias_id
                    INNER JOIN plandeestudios p ON p.id = m.plandeestudios_id
                    INNER JOIN anios a ON a.id = p.anio_id
                    WHERE c.infoestudiantesifas_id = infoestudiantesifas.id
                ), 'SIN ASIGNAR') as Anio"),
            ])
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('infoestudiantesifas.instituciones_id', $user->instituciones_id);
            })
            ->orderByDesc('infoestudiantesifas.FechInsc')
            ->orderByDesc('infoestudiantesifas.id');

        $infoestudiantesifas = $query->get();
        return response()->json(['data' => $infoestudiantesifas]);
    }
    
    
    public function store(Request $request)
    {
        $infoestudiantesifas = $request->all();
        $user = request()->user();
        if ($user->instituciones_id) {
            $infoestudiantesifas['instituciones_id'] = $user->instituciones_id;
        }
        infoestudiantesifas::insert($infoestudiantesifas);
        return response()->json(['data' => $infoestudiantesifas]);
    }
    
    public function show($id)
    {
        $infoestudiantesifas = infoestudiantesifas::where('id','=',$id)->firstOrFail();
        return response()->json(['data' => $infoestudiantesifas]);
    }
    
    
    public function update(Request $request)
    {
        $infoestudiantesifas = $request->all();
        infoestudiantesifas::where('id','=',$request->id)->update($infoestudiantesifas);
        return response()->json(['data' => $infoestudiantesifas]);
    }
    
    public function destroy($id)
    {
        infoestudiantesifas::destroy($id);
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
    //#endregion Fin Controller de Crud PHP de infoestudiantesifas
}
