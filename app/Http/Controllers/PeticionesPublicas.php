<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PeticionesPublicas extends Controller
{
    function CargarEstudiantesMateriaPublico(Request $request) {
        $materiaId=$request->input('materiaId');
        $anioId=$request->input('anioId');
        $consulta = DB::select("SELECT estudiantesifas.Ap_Paterno,estudiantesifas.Ap_Materno,estudiantesifas.Nombre,infoestudiantesifas.InstrumentoMusical,infoestudiantesifas.InstrumentoMusicalSecundario,
        infoestudiantesifas.planteldocadmins_id,infoestudiantesifas.planteldocadmins_idPC,infoestudiantesifas.planteldocadmins_idOtros,
        Docente_Especialidad.Apellidos AS Docente_Especialidad_Apellidos,Docente_Especialidad.Nombres AS Docente_Especialidad_Nombres,Docente_Especialidad.CelularTrabajo AS Docente_Especialidad_CelularTrabajo,Docente_Especialidad.Foto AS Docente_Especialidad_Foto,
        Docente_Practica_Conjuntos.Apellidos AS Docente_Practica_Conjuntos_Apellidos,Docente_Practica_Conjuntos.Nombres AS Docente_Practica_Conjuntos_Nombres,Docente_Practica_Conjuntos.CelularTrabajo AS Docente_Practica_Conjuntos_CelularTrabajo,Docente_Practica_Conjuntos.Foto AS Docente_Practica_Conjuntos_Foto,
        Docente_Instrumento_Complementario.Apellidos AS Docente_Instrumento_Complementario_Apellidos,Docente_Instrumento_Complementario.Nombres AS Docente_Instrumento_Complementario_Nombres,Docente_Instrumento_Complementario.CelularTrabajo AS Docente_Instrumento_Complementario_CelularTrabajo,Docente_Instrumento_Complementario.Foto AS Docente_Instrumento_Complementario_Foto
        FROM calificaciones 
        LEFT JOIN materias ON materias.id = calificaciones.materias_id
        LEFT JOIN infoestudiantesifas ON infoestudiantesifas.id = calificaciones.infoestudiantesifas_id
        LEFT JOIN planteldocentes AS Docente_Especialidad ON Docente_Especialidad.id = infoestudiantesifas.planteldocadmins_id
        LEFT JOIN planteldocentes AS Docente_Practica_Conjuntos ON Docente_Practica_Conjuntos.id = infoestudiantesifas.planteldocadmins_idPC
        LEFT JOIN planteldocentes AS Docente_Instrumento_Complementario ON Docente_Instrumento_Complementario.id = infoestudiantesifas.planteldocadmins_idOtros
        LEFT JOIN estudiantesifas ON estudiantesifas.id = infoestudiantesifas.estudiantesifas_id
        LEFT JOIN plandeestudios ON plandeestudios.id = materias.plandeestudios_id
        WHERE calificaciones.materias_id = $materiaId AND plandeestudios.anio_id = $anioId;");
        return response()->json(['data' => $consulta]);
        
    }
    function CargarEstudiantesMezcladosMateriasPublico(Request $request) {

        $anioId   = $request->input('anioId');
        $lvlCurso = $request->input('LvlCurso');
        $paralelo = $request->input('Paralelo');
        $institucionId = $request->input('institucionId');

        if (!$anioId || !$lvlCurso || !$paralelo) {
            return response()->json([
                'error' => 'anioId, LvlCurso y Paralelo son obligatorios'
            ], 422);
        }

        $consulta = DB::select("SELECT DISTINCT

                infoestudiantesifas.id AS info_id,
                infoestudiantesifas.planteldocadmins_id,
                infoestudiantesifas.planteldocadmins_idPC,
                infoestudiantesifas.planteldocadmins_idOtros,

                estudiantesifas.Ap_Paterno,
                estudiantesifas.Ap_Materno,
                estudiantesifas.Nombre,

                infoestudiantesifas.InstrumentoMusical,
                infoestudiantesifas.InstrumentoMusicalSecundario,


                Docente_Especialidad.Apellidos AS Docente_Especialidad_Apellidos,
                Docente_Especialidad.Nombres AS Docente_Especialidad_Nombres,
                Docente_Especialidad.CelularTrabajo AS Docente_Especialidad_CelularTrabajo,

                Docente_Practica_Conjuntos.Apellidos AS Docente_Practica_Conjuntos_Apellidos,
                Docente_Practica_Conjuntos.Nombres AS Docente_Practica_Conjuntos_Nombres,
                Docente_Practica_Conjuntos.CelularTrabajo AS Docente_Practica_Conjuntos_CelularTrabajo,

                Docente_Instrumento_Complementario.Apellidos AS Docente_Instrumento_Complementario_Apellidos,
                Docente_Instrumento_Complementario.Nombres AS Docente_Instrumento_Complementario_Nombres,
                Docente_Instrumento_Complementario.CelularTrabajo AS Docente_Instrumento_Complementario_CelularTrabajo

            FROM calificaciones

            INNER JOIN materias 
                ON materias.id = calificaciones.materias_id

            INNER JOIN plandeestudios 
                ON plandeestudios.id = materias.plandeestudios_id

            INNER JOIN carreras 
                ON carreras.id = plandeestudios.carreras_id 

            INNER JOIN infoestudiantesifas 
                ON infoestudiantesifas.id = calificaciones.infoestudiantesifas_id

            INNER JOIN estudiantesifas 
                ON estudiantesifas.id = infoestudiantesifas.estudiantesifas_id

            LEFT JOIN planteldocentes AS Docente_Especialidad 
                ON Docente_Especialidad.id = infoestudiantesifas.planteldocadmins_id

            LEFT JOIN planteldocentes AS Docente_Practica_Conjuntos 
                ON Docente_Practica_Conjuntos.id = infoestudiantesifas.planteldocadmins_idPC

            LEFT JOIN planteldocentes AS Docente_Instrumento_Complementario 
                ON Docente_Instrumento_Complementario.id = infoestudiantesifas.planteldocadmins_idOtros

            WHERE 
                plandeestudios.anio_id = ?
            AND plandeestudios.LvlCurso = ?
            AND materias.Paralelo = ?
            AND carreras.instituciones_id = ?

            ORDER BY estudiantesifas.Ap_Paterno,
                    estudiantesifas.Ap_Materno,
                    estudiantesifas.Nombre
        ", [$anioId, $lvlCurso, $paralelo,$institucionId]);

        return response()->json(['data' => $consulta]);
    }
    function CargarInfoPublicaDocentes() {
        $consulta = DB::select("SELECT instituciones.Nombre, instituciones.Logo,
            planteldocentes.Apellidos, planteldocentes.Nombres,planteldocentes.Foto,
            planteldocentes.CelularTrabajo,planteldocentes.Cargo,planteldocentes.Biografia,
            planteldocentes.Visibilidad,planteldocentes.Estado
            FROM planteldocentes

            INNER JOIN instituciones 
                ON instituciones.id = planteldocentes.instituciones_id

            ORDER BY planteldocentes.Apellidos,
                    planteldocentes.Nombres
        ");

        return response()->json(['data' => $consulta]);
    }
    function CargarInfoPublicaAdministrativos() {
        $consulta = DB::select("SELECT instituciones.Nombre, instituciones.Logo,
            planteladministrativos.Apellidos, planteladministrativos.Nombres,planteladministrativos.Foto,
            planteladministrativos.CelularTrabajo,planteladministrativos.Cargo,planteladministrativos.Biografia,
            planteladministrativos.Visibilidad,planteladministrativos.Estado
            FROM planteladministrativos

            INNER JOIN instituciones 
                ON instituciones.id = planteladministrativos.instituciones_id

            ORDER BY planteladministrativos.Apellidos,
                    planteladministrativos.Nombres
        ");

        return response()->json(['data' => $consulta]);
    }

    function CargarMateriasEstudiantesMultiInstitucionPublico(Request $request) {
        $estId = $request->input('CI');
        $anioId = $request->input('Anio_id');
        $consulta = DB::select("SELECT 
            estudiantesifas.Ap_Paterno, estudiantesifas.Ap_Materno, estudiantesifas.Nombre, infoestudiantesifas.InstrumentoMusical, infoestudiantesifas.InstrumentoMusicalSecundario,
            Docente_Especialidad.Apellidos AS Docente_Especialidad_Apellidos, Docente_Especialidad.Nombres AS Docente_Especialidad_Nombres, Docente_Especialidad.CelularTrabajo AS Docente_Especialidad_CelularTrabajo, Docente_Especialidad.Foto AS Docente_Especialidad_Foto,
            Docente_Practica_Conjuntos.Apellidos AS Docente_Practica_Conjuntos_Apellidos, Docente_Practica_Conjuntos.Nombres AS Docente_Practica_Conjuntos_Nombres, Docente_Practica_Conjuntos.CelularTrabajo AS Docente_Practica_Conjuntos_CelularTrabajo, Docente_Practica_Conjuntos.Foto AS Docente_Practica_Conjuntos_Foto,
            Docente_Instrumento_Complementario.Apellidos AS Docente_Instrumento_Complementario_Apellidos, Docente_Instrumento_Complementario.Nombres AS Docente_Instrumento_Complementario_Nombres, Docente_Instrumento_Complementario.CelularTrabajo AS Docente_Instrumento_Complementario_CelularTrabajo, Docente_Instrumento_Complementario.Foto AS Docente_Instrumento_Complementario_Foto,
            instituciones.id AS institucion_id, instituciones.Nombre AS nameInstitucion, instituciones.Logo, instituciones.Caractisticas,
            carreras.id AS carrera_id, carreras.NombreCarrera,
            materias.id AS materia_id, materias.Paralelo, materias.Turno, materias.ModoAsistencia, materias.EstadoHabilitacion, materias.EstadoEnvio,
            plandeestudios.LvlCurso,plandeestudios.NombreMateria
        FROM calificaciones 
        LEFT JOIN materias ON materias.id = calificaciones.materias_id
        LEFT JOIN infoestudiantesifas ON infoestudiantesifas.id = calificaciones.infoestudiantesifas_id
        LEFT JOIN planteldocentes AS Docente_Especialidad ON Docente_Especialidad.id = infoestudiantesifas.planteldocadmins_id
        LEFT JOIN planteldocentes AS Docente_Practica_Conjuntos ON Docente_Practica_Conjuntos.id = infoestudiantesifas.planteldocadmins_idPC
        LEFT JOIN planteldocentes AS Docente_Instrumento_Complementario ON Docente_Instrumento_Complementario.id = infoestudiantesifas.planteldocadmins_idOtros
        LEFT JOIN estudiantesifas ON estudiantesifas.id = infoestudiantesifas.estudiantesifas_id
        LEFT JOIN plandeestudios ON plandeestudios.id = materias.plandeestudios_id
        LEFT JOIN carreras ON carreras.id = plandeestudios.carreras_id
        LEFT JOIN instituciones ON instituciones.id = carreras.instituciones_id
        WHERE estudiantesifas.CI = ? AND plandeestudios.anio_id = ?", [$estId, $anioId]);

        // Agrupar por institucion > carrera > materias
        $resultado = [];
        foreach ($consulta as $row) {
            $institucionId = $row->institucion_id;
            $carreraId = $row->carrera_id;
            $materiaId = $row->materia_id;

            // Institución
            if (!isset($resultado[$institucionId])) {
                $resultado[$institucionId] = [
                    'institucion_id' => $institucionId,
                    'nombre' => $row->nameInstitucion,
                    'logo' => $row->Logo,
                    'caractisticas' => $row->Caractisticas,
                    'carreras' => []
                ];
            }

            // Carrera
            if (!isset($resultado[$institucionId]['carreras'][$carreraId])) {
                $resultado[$institucionId]['carreras'][$carreraId] = [
                    'carrera_id' => $carreraId,
                    'nombre' => $row->NombreCarrera,
                    'materias' => []
                ];
            }

            // Materia
            $materiaData = [
                'materia_id' => $materiaId,
                'paralelo' => $row->Paralelo,
                'turno' => $row->Turno,
                'modo_asistencia' => $row->ModoAsistencia,
                'estado_habilitacion' => $row->EstadoHabilitacion,
                'estado_envio' => $row->EstadoEnvio,
                'lvl_curso' => $row->LvlCurso,
                'nombre_materia' => $row->NombreMateria,
                'docentes' => [
                    'especialidad' => [
                        'apellidos' => $row->Docente_Especialidad_Apellidos,
                        'nombres' => $row->Docente_Especialidad_Nombres,
                        'celular_trabajo' => $row->Docente_Especialidad_CelularTrabajo,
                        'foto' => $row->Docente_Especialidad_Foto
                    ],
                    'practica_conjuntos' => [
                        'apellidos' => $row->Docente_Practica_Conjuntos_Apellidos,
                        'nombres' => $row->Docente_Practica_Conjuntos_Nombres,
                        'celular_trabajo' => $row->Docente_Practica_Conjuntos_CelularTrabajo,
                        'foto' => $row->Docente_Practica_Conjuntos_Foto
                    ],
                    'instrumento_complementario' => [
                        'apellidos' => $row->Docente_Instrumento_Complementario_Apellidos,
                        'nombres' => $row->Docente_Instrumento_Complementario_Nombres,
                        'celular_trabajo' => $row->Docente_Instrumento_Complementario_CelularTrabajo,
                        'foto' => $row->Docente_Instrumento_Complementario_Foto
                    ]
                ]
            ];

            $resultado[$institucionId]['carreras'][$carreraId]['materias'][] = $materiaData;
        }

        // Reindexar para que sean arrays planos y no asociativos
        $resultado = array_values(array_map(function($institucion) {
            $institucion['carreras'] = array_values(array_map(function($carrera) {
                return $carrera;
            }, $institucion['carreras']));
            return $institucion;
        }, $resultado));

        return response()->json(['data' => $resultado]);
    }

    // function CargarMateriasEstudiantesMultiInstitucionPublico(Request $request) {
    //     $estId=$request->input('estId');
    //     $anioId=$request->input('anioId');
    //     $consulta = DB::select("SELECT estudiantesifas.Ap_Paterno,estudiantesifas.Ap_Materno,estudiantesifas.Nombre,infoestudiantesifas.InstrumentoMusical,infoestudiantesifas.InstrumentoMusicalSecundario,
    //     Docente_Especialidad.Apellidos AS Docente_Especialidad_Apellidos,Docente_Especialidad.Nombres AS Docente_Especialidad_Nombres,Docente_Especialidad.CelularTrabajo AS Docente_Especialidad_CelularTrabajo,Docente_Especialidad.Foto AS Docente_Especialidad_Foto,
    //     Docente_Practica_Conjuntos.Apellidos AS Docente_Practica_Conjuntos_Apellidos,Docente_Practica_Conjuntos.Nombres AS Docente_Practica_Conjuntos_Nombres,Docente_Practica_Conjuntos.CelularTrabajo AS Docente_Practica_Conjuntos_CelularTrabajo,Docente_Practica_Conjuntos.Foto AS Docente_Practica_Conjuntos_Foto,
    //     Docente_Instrumento_Complementario.Apellidos AS Docente_Instrumento_Complementario_Apellidos,Docente_Instrumento_Complementario.Nombres AS Docente_Instrumento_Complementario_Nombres,Docente_Instrumento_Complementario.CelularTrabajo AS Docente_Instrumento_Complementario_CelularTrabajo,Docente_Instrumento_Complementario.Foto AS Docente_Instrumento_Complementario_Foto,
    //     instituciones.Nombre AS nameInstitucion, instituciones.Logo, carreras.NombreCarrera
    //     FROM calificaciones 
    //     LEFT JOIN materias ON materias.id = calificaciones.materias_id
    //     LEFT JOIN infoestudiantesifas ON infoestudiantesifas.id = calificaciones.infoestudiantesifas_id
    //     LEFT JOIN planteldocentes AS Docente_Especialidad ON Docente_Especialidad.id = infoestudiantesifas.planteldocadmins_id
    //     LEFT JOIN planteldocentes AS Docente_Practica_Conjuntos ON Docente_Practica_Conjuntos.id = infoestudiantesifas.planteldocadmins_idPC
    //     LEFT JOIN planteldocentes AS Docente_Instrumento_Complementario ON Docente_Instrumento_Complementario.id = infoestudiantesifas.planteldocadmins_idOtros
    //     LEFT JOIN estudiantesifas ON estudiantesifas.id = infoestudiantesifas.estudiantesifas_id
    //     LEFT JOIN plandeestudios ON plandeestudios.id = materias.plandeestudios_id
    //     LEFT JOIN carreras ON carreras.id = plandeestudios.carreras_id
    //     LEFT JOIN instituciones ON instituciones.id = carreras.instituciones_id
    //     WHERE calificaciones.infoestudiantesifas_id = $estId AND plandeestudios.anio_id = $anioId;");
    //     return response()->json(['data' => $consulta]);
    // }
}
