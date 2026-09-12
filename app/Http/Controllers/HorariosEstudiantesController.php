<?php

namespace App\Http\Controllers;

use App\Models\Horarios_estudiantes;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;
use Illuminate\Support\Facades\DB;

class HorariosEstudiantesController extends Controller
{
    //controllerPHPlcch Horarios_estudiantes, $
    
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }
    //#region Inicio Controller de Crud PHP de Horarios_estudiantes
    public function index()
    {
        $Horarios_estudiantes = Horarios_estudiantes::all();
        return response()->json(['data' => $Horarios_estudiantes]);
        
    }
    
    
    public function obtenerHorario(Request $request, $infoId, $tipo)
    {
        $horarios = DB::table('horarios_estudiantes')
            ->where('infoestudiantesifas_id', (int) $infoId)
            ->where('tipo_asignacion', $tipo)
            ->orderBy('dia_semana')
            ->get();
        return response()->json(['data' => $horarios]);
    }

    public function guardarHorario(Request $request, $infoId, $tipo)
    {
        $dias = $request->input('dias', []); // Array de objetos: [{dia_semana: 1, hora_inicio: '14:00', hora_fin: '15:00'}, ...]
        
        DB::beginTransaction();
        try {
            // Borrar horarios anteriores para esta asignación
            DB::table('horarios_estudiantes')
                ->where('infoestudiantesifas_id', (int) $infoId)
                ->where('tipo_asignacion', $tipo)
                ->delete();

            // Insertar nuevos
            $insertData = [];
            foreach ($dias as $dia) {
                $insertData[] = [
                    'infoestudiantesifas_id' => (int) $infoId,
                    'tipo_asignacion'        => $tipo,
                    'dia_semana'             => (int) $dia['dia_semana'],
                    'hora_inicio'            => $dia['hora_inicio'] ?? null,
                    'hora_fin'               => $dia['hora_fin'] ?? null,
                    'created_at'             => now(),
                    'updated_at'             => now(),
                ];
            }
            if (!empty($insertData)) {
                DB::table('horarios_estudiantes')->insert($insertData);
            }
            DB::commit();
            return response()->json(['message' => 'Horarios actualizados correctamente']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error al guardar horario'], 500);
        }
    }
    public function guardarHorarioGrupal(Request $request, $tipo)
    {
        $infoIds = $request->input('infoIds', []); // Array de IDs seleccionados
        $dias = $request->input('dias', []);       // Array de horarios
        
        if (empty($infoIds)) {
            return response()->json(['message' => 'No hay estudiantes seleccionados'], 422);
        }

        DB::beginTransaction();
        try {
            // Borrar los horarios anteriores para todos los estudiantes seleccionados
            DB::table('horarios_estudiantes')
                ->whereIn('infoestudiantesifas_id', $infoIds)
                ->where('tipo_asignacion', $tipo)
                ->delete();

            // Preparar el array para inserción masiva
            $insertData = [];
            foreach ($infoIds as $infoId) {
                foreach ($dias as $dia) {
                    $insertData[] = [
                        'infoestudiantesifas_id' => (int) $infoId,
                        'tipo_asignacion'        => $tipo,
                        'dia_semana'             => (int) $dia['dia_semana'],
                        'hora_inicio'            => $dia['hora_inicio'] ?? null,
                        'hora_fin'               => $dia['hora_fin'] ?? null,
                        'created_at'             => now(),
                        'updated_at'             => now(),
                    ];
                }
            }
            if (!empty($insertData)) {
                DB::table('horarios_estudiantes')->insert($insertData);
            }
            
            DB::commit();
            return response()->json(['message' => 'Horarios grupales asignados correctamente']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error al guardar horarios grupales'], 500);
        }
    }

    public function store(Request $request)
    {
        $Horarios_estudiantes = $request->all();
        Horarios_estudiantes::insert($Horarios_estudiantes);
        return response()->json(['data' => $Horarios_estudiantes]);
        
    }
    
    public function show($id)
    {
        $Horarios_estudiantes = Horarios_estudiantes::where('id','=',$id)->firstOrFail();
        return response()->json(['data' => $Horarios_estudiantes]);
    }
    
    
    public function update(Request $request)
    {
        $Horarios_estudiantes = $request->all();
        Horarios_estudiantes::where('id','=',$request->id)->update($Horarios_estudiantes);
        return response()->json(['data' => $Horarios_estudiantes]);
    }
    
    public function destroy($id)
    {
        Horarios_estudiantes::destroy($id);
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
    //#endregion Fin Controller de Crud PHP de Horarios_estudiantes
}
