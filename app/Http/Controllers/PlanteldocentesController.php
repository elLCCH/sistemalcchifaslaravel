<?php

namespace App\Http\Controllers;

use App\Models\Planteldocentes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use Illuminate\Support\Facades\Hash;
use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;
class PlanteldocentesController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }

    

    //#region Inicio Controller de Crud PHP de planteldocentes
    public function index()
    {
        // $planteldocentes = planteldocentes::all();
        $user = request()->user();

        $query = \App\Models\Planteldocentes::query();
        if (!empty($user?->instituciones_id)) {
            $query->where('instituciones_id', $user->instituciones_id);
        }

        $planteldocentes = $query->get();

        if (empty($user?->instituciones_id)) {
            foreach ($planteldocentes as $planteldocente) {
                $institucion = \App\Models\Instituciones::find($planteldocente->instituciones_id);
                $planteldocente->NombreInstitucion = $institucion ? $institucion->Nombre : null;
            }
        } else {
            foreach ($planteldocentes as $planteldocente) {
                $planteldocente->NombreInstitucion = null;
            }
        }
        return response()->json(['data' => $planteldocentes]);
    }
    
    
    public function store(Request $request)
    {
        $planteldocentes = $request->all();
        $user = request()->user();
        if ($user->instituciones_id) {
            $planteldocentes['instituciones_id'] = $user->instituciones_id;
        }
        $planteldocentes['Contrasenia'] = !empty($request->input('Contrasenia'))
            ? Hash::make($request->input('Contrasenia'))
            : null;
        Planteldocentes::insert($planteldocentes);
        return response()->json(['data' => $planteldocentes]);
    }
    
    public function show($id)
    {
        $user = request()->user();
        $planteldocentes = Planteldocentes::query()
            ->where('id', '=', $id)
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('instituciones_id', $user->instituciones_id);
            })
            ->firstOrFail();

        if (!empty($user?->instituciones_id)) {
            $planteldocentes->NombreInstitucion = null;
        }
        return response()->json(['data' => $planteldocentes]);
    }
    
    
    public function update(Request $request)
    {
        // $planteldocentes = $request->all();
        // planteldocentes::where('id','=',$request->id)->update($planteldocentes);
        // return response()->json(['data' => $planteldocentes]);

        $user = $request->user();
        $planteldocentes = Planteldocentes::query()
            ->where('id', '=', $request->id)
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('instituciones_id', $user->instituciones_id);
            })
            ->firstOrFail();
        $requestData = $request->all();

        if ($request->has('Contrasenia')) {
            $raw = (string) $request->input('Contrasenia');
            if (trim($raw) !== '' && !\Illuminate\Support\Str::startsWith($raw, ['$2y$', '$2a$', '$argon2', '$bcrypt$'])) {
                $requestData['Contrasenia'] = Hash::make($raw);
            } else {
                // Vacío o ya es un hash → mantener la contraseña actual
                $requestData['Contrasenia'] = $planteldocentes->Contrasenia;
            }
        } else {
            // No se envió la contraseña, mantener la actual
            $requestData['Contrasenia'] = $planteldocentes->Contrasenia;
        }

        $planteldocentes->update($requestData);

        if (!empty($user?->instituciones_id)) {
            $planteldocentes->NombreInstitucion = null;
        }
        return response()->json(['data' => $planteldocentes]);
    }
    
    public function destroy($id)
    {
        $user = request()->user();
        $row = Planteldocentes::query()
            ->where('id', '=', $id)
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('instituciones_id', $user->instituciones_id);
            })
            ->firstOrFail();
        $row->delete();
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
    //#endregion Fin Controller de Crud PHP de planteldocentes


    public function obtenerDatosReportePlan(Request $request)
    {
        $estudiantesIds = $request->input('estudiantes_ids', []);
        $tipoAsignacion = $request->input('tipo', 'ESPECIALIDAD'); 
        $user = $request->user();

        if (empty($estudiantesIds)) {
            return response()->json(['data' => []], 400);
        }

        // 1. Obtener la Dirección de la institución
        $direccionInst = 'Dirección no registrada';
        if ($user && !empty($user->instituciones_id)) {
            $institucion = DB::table('instituciones')->where('id', $user->instituciones_id)->first();
            if ($institucion && !empty($institucion->Direccion)) {
                $direccionInst = $institucion->Direccion;
            }
        }

        // 2. Determinar qué llave foránea usar según el tipo
        $fkDocente = 'planteldocadmins_id'; // Por defecto (ESPECIALIDAD)
        if ($tipoAsignacion === 'COMPLEMENTARIO') {
            $fkDocente = 'planteldocadmins_idOtros';
        } elseif ($tipoAsignacion === 'PRACTICA_CONJUNTOS') {
            $fkDocente = 'planteldocadmins_idPC';
        }

        // Unión de tablas
        $datos = DB::table('infoestudiantesifas as iei')
            ->join('calificaciones as c', 'iei.id', '=', 'c.infoestudiantesifas_id')
            ->join('materias as m', 'c.materias_id', '=', 'm.id')
            ->join('plandeestudios as pe', 'm.plandeestudios_id', '=', 'pe.id')
            ->join('carreras as ca', 'pe.carreras_id', '=', 'ca.id')
            ->leftJoin('planteldocentes as pd', 'iei.' . $fkDocente, '=', 'pd.id')
            ->whereIn('iei.id', $estudiantesIds)
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('iei.instituciones_id', $user->instituciones_id);
            })
            ->when($tipoAsignacion === 'ESPECIALIDAD', function ($q) {
                $q->where('pe.NombreMateria', 'LIKE', '%ESPECIALIDAD%');
            })
            ->when($tipoAsignacion === 'COMPLEMENTARIO', function ($q) {
                $q->where('pe.NombreMateria', 'LIKE', '%COMPLEMENTARIO%');
            })
            ->select(
                'iei.id as estudiante_id', // NUEVO: Extraer el ID para mapear el curso por estudiante
                'ca.NombreCarrera',
                'ca.Nivel',
                'pe.LvlCurso',
                'm.Paralelo',
                'pe.NombreMateria',
                'pe.SiglaMateria',
                'pe.Horas',
                'iei.InstrumentoMusical',
                'iei.InstrumentoMusicalSecundario',
                'pd.Apellidos as DocenteApellidos',
                'pd.Nombres as DocenteNombres'
            )
            ->distinct()
            ->get();

        // Mapear el Grado/Curso oficial individual por cada estudiante (ID -> Grado)
        $gradosPorEstudiante = [];
        foreach($datos as $item) {
            $gradoStr = trim(($item->LvlCurso ?? '') . ' ' . ($item->Paralelo ?? ''));
            $gradosPorEstudiante[$item->estudiante_id] = $gradoStr ?: '—';
        }

        // Calcular y concatenar valores únicos curriculares para la cabecera
        $carreras = $datos->pluck('NombreCarrera')->filter()->unique()->implode(' / ');
        $niveles = $datos->pluck('Nivel')->filter()->unique()->implode(' / ');
        
        $cursosParalelos = $datos->map(function($item) {
            return trim(($item->LvlCurso ?? '') . ' ' . ($item->Paralelo ?? ''));
        })->filter()->unique()->implode(', ');

        $asignaturas = $datos->pluck('NombreMateria')->filter()->unique()->implode(', ');
        $siglas = $datos->pluck('SiglaMateria')->filter()->unique()->implode(' - ');
        $horas = $datos->pluck('Horas')->filter()->unique()->implode(' / ');

        $instrumentos = collect();
        if ($tipoAsignacion === 'ESPECIALIDAD') {
            $instrumentos = $datos->pluck('InstrumentoMusical')->filter()->unique();
        } elseif ($tipoAsignacion === 'COMPLEMENTARIO') {
            $instrumentos = $datos->pluck('InstrumentoMusicalSecundario')->filter()->unique();
        }
        
        $instrumentosStr = $instrumentos->isNotEmpty() ? ' - ' . $instrumentos->implode(', ') : '';
        $asignaturaFinal = $asignaturas . $instrumentosStr;

        $docentes = $datos->map(function($item) {
            $nombreCompleto = trim(($item->DocenteApellidos ?? '') . ' ' . ($item->DocenteNombres ?? ''));
            return $nombreCompleto ?: null;
        })->filter()->unique()->implode(' / ');

        return response()->json([
            'data' => [
                'Carrera' => $carreras ?: '—',
                'Nivel' => $niveles ?: '—',
                'Curso' => $cursosParalelos ?: '—',
                'Asignatura' => $asignaturaFinal ?: '—',
                'Codigo' => $siglas ?: '—',
                'HorasSemanales' => $horas ?: '—',
                'Docente' => $docentes ?: 'DOCENTE NO ASIGNADO',
                'Direccion' => $direccionInst, // Enviamos la dirección oficial
                'gradosEstudiantes' => $gradosPorEstudiante // Enviamos el mapa de cursos
            ]
        ]);
    }
}
