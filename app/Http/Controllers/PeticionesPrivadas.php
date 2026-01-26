<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\DB;

use Illuminate\Http\Request;

class PeticionesPrivadas extends Controller
{
    function CargarEstudiantesMateriaPrivado(Request $request) {
        $materiaId=$request->input('materiaId');
        $anioId=$request->input('anioId');
        $consulta = DB::select("SELECT estudiantesifas.Ap_Paterno,estudiantesifas.Ap_Materno,estudiantesifas.Nombre,infoestudiantesifas.InstrumentoMusical,infoestudiantesifas.InstrumentoMusicalSecundario,
        estudiantesifas.Celular,estudiantesifas.Edad, estudiantesifas.CI, estudiantesifas.Nombre_Padre,estudiantesifas.Nombre_Madre,estudiantesifas.NumCelP,estudiantesifas.NumCelM, estudiantesifas.Sexo,
        infoestudiantesifas.Categoria,infoestudiantesifas.CantidadMateriasAsignadas,infoestudiantesifas.id,
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
    function CargarEstudiantesMezcladosMateriasPrivado(Request $request) {

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

                estudiantesifas.Ap_Paterno,
                estudiantesifas.Ap_Materno,
                estudiantesifas.Nombre,
                

                infoestudiantesifas.InstrumentoMusical,
                infoestudiantesifas.InstrumentoMusicalSecundario,
                
                estudiantesifas.Celular,estudiantesifas.Edad, estudiantesifas.CI, estudiantesifas.Nombre_Padre,estudiantesifas.Nombre_Madre,estudiantesifas.NumCelP,estudiantesifas.NumCelM, estudiantesifas.Sexo,
                infoestudiantesifas.Categoria,infoestudiantesifas.CantidadMateriasAsignadas,infoestudiantesifas.id,

                Docente_Especialidad.Apellidos AS Docente_Especialidad_Apellidos,
                Docente_Especialidad.Nombres AS Docente_Especialidad_Nombres,
                Docente_Especialidad.CelularTrabajo AS Docente_Especialidad_CelularTrabajo,

                Docente_Practica_Conjuntos.Apellidos AS Docente_Practica_Apellidos,
                Docente_Practica_Conjuntos.Nombres AS Docente_Practica_Nombres,
                Docente_Practica_Conjuntos.CelularTrabajo AS Docente_Practica_Conjuntos_CelularTrabajo,

                Docente_Instrumento_Complementario.Apellidos AS Docente_Complementario_Apellidos,
                Docente_Instrumento_Complementario.Nombres AS Docente_Complementario_Nombres,
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

    function CargarInfoPlanesAniosCarrerasInstitucionesPrivado(Request $request) {
        $anioId   = $request->input('anioId');
        $lvlCurso = $request->input('LvlCurso');
        $paralelo = $request->input('Paralelo');
        $institucionId = $request->input('institucionId');
        $modo = $request->input('Modo');
        $materiaId   = $request->input('materiaId');
        if ($modo=='xMATERIA') {
            
            $consulta = DB::select("SELECT DISTINCT
                instituciones.Nombre,anios.Anio,carreras.Area,carreras.NombreCarrera,carreras.Resolucion,plandeestudios.LvlCurso,materias.Paralelo,plandeestudios.NombreMateria
                
                FROM plandeestudios
                INNER JOIN materias ON materias.plandeestudios_id = plandeestudios.id
                INNER JOIN calificaciones ON calificaciones.materias_id = materias.id
                INNER JOIN anios ON anios.id = plandeestudios.anio_id
                INNER JOIN carreras ON carreras.id = plandeestudios.carreras_id
                INNER JOIN instituciones ON instituciones.id = carreras.instituciones_id

                WHERE calificaciones.materias_id = $materiaId AND plandeestudios.anio_id = $anioId LIMIT 1;");

            
        }else{
            //seria todo mezclado
            $consulta = DB::select("SELECT DISTINCT
                instituciones.Nombre,anios.Anio,carreras.Area,carreras.NombreCarrera,carreras.Resolucion,plandeestudios.LvlCurso,materias.Paralelo
                
                FROM plandeestudios
                INNER JOIN materias ON materias.plandeestudios_id = plandeestudios.id
                INNER JOIN calificaciones ON calificaciones.materias_id = materias.id
                INNER JOIN anios ON anios.id = plandeestudios.anio_id
                INNER JOIN carreras ON carreras.id = plandeestudios.carreras_id
                INNER JOIN instituciones ON instituciones.id = carreras.instituciones_id
                WHERE 
                    plandeestudios.anio_id = ?
                AND plandeestudios.LvlCurso = ?
                AND materias.Paralelo = ?
                AND carreras.instituciones_id = ?
            ", [$anioId, $lvlCurso, $paralelo,$institucionId]);
        }
        return response()->json(['data' => $consulta]);
    }
}
