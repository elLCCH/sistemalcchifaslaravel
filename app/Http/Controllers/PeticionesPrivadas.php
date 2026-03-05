<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\DB;
use App\Models\Usuarioslcchs;

use Illuminate\Http\Request;

class PeticionesPrivadas extends Controller
{
    private function getInstitucionIdPrivado(Request $request): int
    {
        $user = $request->user();

        $instUser = (int) ($user?->instituciones_id ?? 0);
        if ($instUser > 0) return $instUser;

        // Super LCCH puede consultar por institución explícita.
        if ($user instanceof Usuarioslcchs) {
            $inst = (int) $request->input('institucionId', $request->input('instituciones_id', 0));
            return $inst;
        }

        return 0;
    }

    function CargarEstudiantesMateriaPrivado(Request $request) {
        $materiaId = (int) $request->input('materiaId', 0);
        $anioId = (int) $request->input('anioId', 0);
        if ($materiaId <= 0 || $anioId <= 0) {
            return response()->json(['error' => 'materiaId y anioId son obligatorios'], 422);
        }

        $institucionId = $this->getInstitucionIdPrivado($request);
        if ($institucionId <= 0) {
            return response()->json(['message' => 'instituciones_id es requerido'], 403);
        }

                $consulta = DB::select("SELECT estudiantesifas.Ap_Paterno,estudiantesifas.Ap_Materno,estudiantesifas.Nombre,infoestudiantesifas.InstrumentoMusical,infoestudiantesifas.InstrumentoMusicalSecundario,
        estudiantesifas.Celular,estudiantesifas.Edad, estudiantesifas.CI, estudiantesifas.Nombre_Padre,estudiantesifas.Nombre_Madre,estudiantesifas.NumCelP,estudiantesifas.NumCelM, estudiantesifas.Sexo,
        infoestudiantesifas.Categoria,infoestudiantesifas.CantidadMateriasAsignadas,infoestudiantesifas.id,

                EXISTS(
                        SELECT 1
                        FROM calificaciones c2
                        INNER JOIN materias m2 ON m2.id = c2.materias_id
                        INNER JOIN plandeestudios p2 ON p2.id = m2.plandeestudios_id
                        INNER JOIN carreras ca2 ON ca2.id = p2.carreras_id
                        WHERE c2.infoestudiantesifas_id = infoestudiantesifas.id
                            AND p2.anio_id = plandeestudios.anio_id
                            AND p2.LvlCurso = plandeestudios.LvlCurso
                            AND m2.Paralelo = materias.Paralelo
                            AND ca2.instituciones_id = carreras.instituciones_id
                            AND p2.ModoMateria = 'MODO INSTRUMENTOS DE ESPECIALIDAD'
                ) AS TieneModoEspecialidad,
                EXISTS(
                        SELECT 1
                        FROM calificaciones c2
                        INNER JOIN materias m2 ON m2.id = c2.materias_id
                        INNER JOIN plandeestudios p2 ON p2.id = m2.plandeestudios_id
                        INNER JOIN carreras ca2 ON ca2.id = p2.carreras_id
                        WHERE c2.infoestudiantesifas_id = infoestudiantesifas.id
                            AND p2.anio_id = plandeestudios.anio_id
                            AND p2.LvlCurso = plandeestudios.LvlCurso
                            AND m2.Paralelo = materias.Paralelo
                            AND ca2.instituciones_id = carreras.instituciones_id
                            AND p2.ModoMateria = 'MODO PRÁCTICA DE CONJUNTOS'
                ) AS TieneModoPracticaConjuntos,
                EXISTS(
                        SELECT 1
                        FROM calificaciones c2
                        INNER JOIN materias m2 ON m2.id = c2.materias_id
                        INNER JOIN plandeestudios p2 ON p2.id = m2.plandeestudios_id
                        INNER JOIN carreras ca2 ON ca2.id = p2.carreras_id
                        WHERE c2.infoestudiantesifas_id = infoestudiantesifas.id
                            AND p2.anio_id = plandeestudios.anio_id
                            AND p2.LvlCurso = plandeestudios.LvlCurso
                            AND m2.Paralelo = materias.Paralelo
                            AND ca2.instituciones_id = carreras.instituciones_id
                            AND p2.ModoMateria = 'MODO INSTRUMENTO COMPLEMENTARIO'
                ) AS TieneModoInstrumentoComplementario,

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
                LEFT JOIN carreras ON carreras.id = plandeestudios.carreras_id
    WHERE calificaciones.materias_id = ?
      AND plandeestudios.anio_id = ?
      AND carreras.instituciones_id = ?;", [$materiaId, $anioId, $institucionId]);
        return response()->json(['data' => $consulta]);
        
    }
    function CargarEstudiantesMezcladosMateriasPrivado(Request $request) {

        $anioId   = $request->input('anioId');
        $lvlCurso = $request->input('LvlCurso');
        $paralelo = $request->input('Paralelo');
        $institucionId = $this->getInstitucionIdPrivado($request);

        if (!$anioId || !$lvlCurso || !$paralelo) {
            return response()->json([
                'error' => 'anioId, LvlCurso y Paralelo son obligatorios'
            ], 422);
        }

        if (!$institucionId) {
            return response()->json(['message' => 'instituciones_id es requerido'], 403);
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

                                EXISTS(
                                        SELECT 1
                                        FROM calificaciones c2
                                        INNER JOIN materias m2 ON m2.id = c2.materias_id
                                        INNER JOIN plandeestudios p2 ON p2.id = m2.plandeestudios_id
                                        INNER JOIN carreras ca2 ON ca2.id = p2.carreras_id
                                        WHERE c2.infoestudiantesifas_id = infoestudiantesifas.id
                                            AND p2.anio_id = plandeestudios.anio_id
                                            AND p2.LvlCurso = plandeestudios.LvlCurso
                                            AND m2.Paralelo = materias.Paralelo
                                            AND ca2.instituciones_id = carreras.instituciones_id
                                            AND p2.ModoMateria = 'MODO INSTRUMENTOS DE ESPECIALIDAD'
                                ) AS TieneModoEspecialidad,
                                EXISTS(
                                        SELECT 1
                                        FROM calificaciones c2
                                        INNER JOIN materias m2 ON m2.id = c2.materias_id
                                        INNER JOIN plandeestudios p2 ON p2.id = m2.plandeestudios_id
                                        INNER JOIN carreras ca2 ON ca2.id = p2.carreras_id
                                        WHERE c2.infoestudiantesifas_id = infoestudiantesifas.id
                                            AND p2.anio_id = plandeestudios.anio_id
                                            AND p2.LvlCurso = plandeestudios.LvlCurso
                                            AND m2.Paralelo = materias.Paralelo
                                            AND ca2.instituciones_id = carreras.instituciones_id
                                            AND p2.ModoMateria = 'MODO PRÁCTICA DE CONJUNTOS'
                                ) AS TieneModoPracticaConjuntos,
                                EXISTS(
                                        SELECT 1
                                        FROM calificaciones c2
                                        INNER JOIN materias m2 ON m2.id = c2.materias_id
                                        INNER JOIN plandeestudios p2 ON p2.id = m2.plandeestudios_id
                                        INNER JOIN carreras ca2 ON ca2.id = p2.carreras_id
                                        WHERE c2.infoestudiantesifas_id = infoestudiantesifas.id
                                            AND p2.anio_id = plandeestudios.anio_id
                                            AND p2.LvlCurso = plandeestudios.LvlCurso
                                            AND m2.Paralelo = materias.Paralelo
                                            AND ca2.instituciones_id = carreras.instituciones_id
                                            AND p2.ModoMateria = 'MODO INSTRUMENTO COMPLEMENTARIO'
                                ) AS TieneModoInstrumentoComplementario,
                
                estudiantesifas.Celular,estudiantesifas.Edad, estudiantesifas.CI, estudiantesifas.Nombre_Padre,estudiantesifas.Nombre_Madre,estudiantesifas.NumCelP,estudiantesifas.NumCelM, estudiantesifas.Sexo,
                infoestudiantesifas.Categoria,infoestudiantesifas.CantidadMateriasAsignadas,infoestudiantesifas.id,

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

    function CargarInfoPlanesAniosCarrerasInstitucionesPrivado(Request $request) {
        $anioId   = $request->input('anioId');
        $lvlCurso = $request->input('LvlCurso');
        $paralelo = $request->input('Paralelo');
        $institucionId = $this->getInstitucionIdPrivado($request);
        $modo = $request->input('Modo');
        $materiaId   = $request->input('materiaId');

        if (!$institucionId) {
            return response()->json(['message' => 'instituciones_id es requerido'], 403);
        }
        if ($modo=='xMATERIA') {
            
            $consulta = DB::select("SELECT DISTINCT
                instituciones.Nombre,anios.Anio,carreras.Area,carreras.NombreCarrera,carreras.Resolucion,plandeestudios.LvlCurso,materias.Paralelo,plandeestudios.NombreMateria
                
                FROM plandeestudios
                INNER JOIN materias ON materias.plandeestudios_id = plandeestudios.id
                INNER JOIN calificaciones ON calificaciones.materias_id = materias.id
                INNER JOIN anios ON anios.id = plandeestudios.anio_id
                INNER JOIN carreras ON carreras.id = plandeestudios.carreras_id
                INNER JOIN instituciones ON instituciones.id = carreras.instituciones_id

                WHERE calificaciones.materias_id = ? AND plandeestudios.anio_id = ? AND carreras.instituciones_id = ? LIMIT 1;", [$materiaId, $anioId, $institucionId]);

            
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

    function CargarInformacionCuadroInscripciones(Request $request)
    {
        $user = $request->user();
        // Endpoint es POST. Aceptamos también query params por compatibilidad.
        $anioId = (int) $request->input('anio_id', $request->query('anio_id', 0));
        if ($anioId <= 0) {
            return response()->json(['message' => 'anio_id es requerido'], 422);
        }

        $includeSinAsignar = filter_var(
            $request->input('include_sin_asignar', $request->query('include_sin_asignar', '0')),
            FILTER_VALIDATE_BOOLEAN
        );

        // "nivel" es el nombre histórico; "curso_solicitado" se acepta por compatibilidad.
        $cursoSolicitado = $request->input('nivel', $request->input('curso_solicitado', $request->query('nivel', $request->query('curso_solicitado', 'MIXTO'))));

        $institucionId = (int) ($user?->instituciones_id ?? 0);
        if ($institucionId <= 0) {
            $institucionId = (int) $request->input('instituciones_id', 0);
        }
        if ($institucionId <= 0) {
            return response()->json(['message' => 'instituciones_id es requerido'], 403);
        }

        $anioValor = trim((string) (DB::table('anios')->where('id', $anioId)->value('Anio') ?? ''));
        if ($anioValor === '') {
            return response()->json(['message' => 'Año inválido'], 422);
        }

        // Si se selecciona una gestión base (ej. "2026"), incluir también sus sub-gestiones ("2026/1", "2026/2", ...)
        // para que no se excluyan estudiantes ya asignados a un subperiodo.
        $anioBase = null;
        if (preg_match('/^(\d{4})/', $anioValor, $m)) {
            $anioBase = (int) $m[1];
        }
        $anioEsBase = !str_contains($anioValor, '/');

        // Subquery coherente con lo que se muestra en la tabla de inscripciones.
        $anioLabelSubquery = "COALESCE((
            SELECT MAX(a.Anio)
            FROM calificaciones c
            INNER JOIN materias m ON m.id = c.materias_id
            INNER JOIN plandeestudios p ON p.id = m.plandeestudios_id
            INNER JOIN anios a ON a.id = p.anio_id
            WHERE c.infoestudiantesifas_id = infoestudiantesifas.id
        ), 'SIN ASIGNAR')";

        $carreraSubquery = "(
            SELECT ca.NombreCarrera
            FROM calificaciones c
            INNER JOIN materias m ON m.id = c.materias_id
            INNER JOIN plandeestudios p ON p.id = m.plandeestudios_id
            INNER JOIN carreras ca ON ca.id = p.carreras_id
            INNER JOIN anios a ON a.id = p.anio_id
            WHERE c.infoestudiantesifas_id = infoestudiantesifas.id
            ORDER BY a.Anio DESC, p.id DESC
            LIMIT 1
        )";

        $areaSubquery = "(
            SELECT ca.Area
            FROM calificaciones c
            INNER JOIN materias m ON m.id = c.materias_id
            INNER JOIN plandeestudios p ON p.id = m.plandeestudios_id
            INNER JOIN carreras ca ON ca.id = p.carreras_id
            INNER JOIN anios a ON a.id = p.anio_id
            WHERE c.infoestudiantesifas_id = infoestudiantesifas.id
            ORDER BY a.Anio DESC, p.id DESC
            LIMIT 1
        )";

        $resolucionSubquery = "(
            SELECT ca.Resolucion
            FROM calificaciones c
            INNER JOIN materias m ON m.id = c.materias_id
            INNER JOIN plandeestudios p ON p.id = m.plandeestudios_id
            INNER JOIN carreras ca ON ca.id = p.carreras_id
            INNER JOIN anios a ON a.id = p.anio_id
            WHERE c.infoestudiantesifas_id = infoestudiantesifas.id
            ORDER BY a.Anio DESC, p.id DESC
            LIMIT 1
        )";

        // Nivel: priorizar carreras.Nivel (asignados) → fallback a Curso_Solicitado
        $nivelSubquery = "COALESCE(
            (
                SELECT ca.Nivel
                FROM calificaciones c
                INNER JOIN materias m ON m.id = c.materias_id
                INNER JOIN plandeestudios p ON p.id = m.plandeestudios_id
                INNER JOIN carreras ca ON ca.id = p.carreras_id
                INNER JOIN anios a ON a.id = p.anio_id
                WHERE c.infoestudiantesifas_id = infoestudiantesifas.id
                ORDER BY a.Anio DESC, p.id DESC
                LIMIT 1
            ),
            CASE
                WHEN UPPER(TRIM(COALESCE(infoestudiantesifas.Curso_Solicitado,''))) LIKE '%SUPERIOR%' THEN 'TECNICO SUPERIOR'
                WHEN UPPER(TRIM(COALESCE(infoestudiantesifas.Curso_Solicitado,''))) LIKE '%MEDIO%' THEN 'TECNICO MEDIO'
                ELSE 'CAPACITACIÓN'
            END
        )";

        $q = DB::table('infoestudiantesifas')
            ->join('estudiantesifas', 'estudiantesifas.id', '=', 'infoestudiantesifas.estudiantesifas_id')
            ->where('infoestudiantesifas.instituciones_id', $institucionId)
            ->select([
                'estudiantesifas.Ap_Paterno',
                'estudiantesifas.Ap_Materno',
                'estudiantesifas.Nombre',
                'estudiantesifas.CI',
                DB::raw('estudiantesifas.FechaNac as FechNac'),
                'estudiantesifas.Sexo',
                'estudiantesifas.Direccion',
                'estudiantesifas.Edad',
                'infoestudiantesifas.Curso_Solicitado',
                'infoestudiantesifas.Turno',
                'infoestudiantesifas.Matricula',
                'infoestudiantesifas.Categoria',
                'infoestudiantesifas.FechInsc',
                DB::raw($anioLabelSubquery . ' as Anio'),
                DB::raw($areaSubquery . ' as Area'),
                DB::raw($carreraSubquery . ' as Carrera'),
                DB::raw($resolucionSubquery . ' as Malla'),
                DB::raw($nivelSubquery . ' as Nivel'),
            ]);

        // Filtro por nivel/curso (opcional)
        $cursoSolicitadoStr = trim((string) ($cursoSolicitado ?? ''));
        $cursoSolicitadoUp = mb_strtoupper($cursoSolicitadoStr, 'UTF-8');
        if ($cursoSolicitadoStr !== '' && $cursoSolicitadoUp !== 'MIXTO') {
            if (str_contains($cursoSolicitadoUp, 'SUPERIOR')) {
                // Asignados con carreras.Nivel SUPERIOR, o sin asignar con Curso_Solicitado SUPERIOR
                $q->where(function ($ww) use ($nivelSubquery) {
                    $ww->whereRaw('UPPER(' . $nivelSubquery . ") LIKE '%SUPERIOR%'");
                });
            } elseif (str_contains($cursoSolicitadoUp, 'MEDIO')) {
                // Asignados con carreras.Nivel MEDIO, o sin asignar con Curso_Solicitado MEDIO
                $q->where(function ($ww) use ($nivelSubquery) {
                    $ww->whereRaw('UPPER(' . $nivelSubquery . ") LIKE '%MEDIO%'");
                });
            } elseif (str_contains($cursoSolicitadoUp, 'CAPACITACIÓN') || str_contains($cursoSolicitadoUp, 'CAPACITACION')) {
                // Asignados con carreras.Nivel CAPACITACIÓN, o sin asignar con Curso_Solicitado != SUPERIOR y != MEDIO
                $q->where(function ($ww) use ($nivelSubquery) {
                    $ww->whereRaw('UPPER(' . $nivelSubquery . ") LIKE '%CAPAC%'");
                });
            } else {
                $q->where('infoestudiantesifas.Curso_Solicitado', $cursoSolicitadoStr);
            }
        }

        // Filtro por año (exacto) + opción de incluir SIN ASIGNAR
        if ($includeSinAsignar) {
            $q->where(function ($outer) use ($anioLabelSubquery, $anioValor, $anioEsBase, $anioBase) {
                // Asignados (por calificaciones) en la gestión seleccionada.
                $outer->where(function ($w) use ($anioLabelSubquery, $anioValor, $anioEsBase) {
                    if ($anioEsBase) {
                        $w->where(function ($ww) use ($anioLabelSubquery, $anioValor) {
                            $ww->whereRaw($anioLabelSubquery . ' = ?', [$anioValor])
                               ->orWhereRaw($anioLabelSubquery . ' LIKE ?', [$anioValor . '/%']);
                        });
                    } else {
                        $w->whereRaw($anioLabelSubquery . ' = ?', [$anioValor]);
                    }
                });

                // SIN ASIGNAR (sin calificaciones) pero acotado al año base por fecha de inscripción.
                $outer->orWhere(function ($w) use ($anioBase) {
                    $w->whereNotExists(function ($qq) {
                        $qq->select(DB::raw(1))
                            ->from('calificaciones as c')
                            ->whereRaw('c.infoestudiantesifas_id = infoestudiantesifas.id');
                    });
                    if (!empty($anioBase)) {
                        $w->whereRaw('YEAR(COALESCE(infoestudiantesifas.FechInsc, infoestudiantesifas.created_at)) = ?', [$anioBase]);
                    }
                });
            });
        } else {
            $q->where(function ($w) use ($anioLabelSubquery, $anioValor, $anioEsBase) {
                if ($anioEsBase) {
                    $w->where(function ($ww) use ($anioLabelSubquery, $anioValor) {
                        $ww->whereRaw($anioLabelSubquery . ' = ?', [$anioValor])
                           ->orWhereRaw($anioLabelSubquery . ' LIKE ?', [$anioValor . '/%']);
                    });
                } else {
                    $w->whereRaw($anioLabelSubquery . ' = ?', [$anioValor]);
                }
            });
        }

        $rows = $q
            // Orden cronológico: primero el que se inscribió primero.
            // Fallback a created_at si FechInsc viene NULL.
            ->orderByRaw('COALESCE(infoestudiantesifas.FechInsc, infoestudiantesifas.created_at) ASC')
            ->orderBy('estudiantesifas.Ap_Paterno')
            ->orderBy('estudiantesifas.Ap_Materno')
            ->orderBy('estudiantesifas.Nombre')
            ->get();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'anio_id' => $anioId,
                'anio' => $anioValor,
                'include_sin_asignar' => $includeSinAsignar ? 1 : 0,
                'instituciones_id' => $institucionId,
                'nivel' => $cursoSolicitadoStr,
                'total' => (int) $rows->count(),
            ],
        ]);
    }

    function CargarListaPreliminarAlumnos2026(Request $request)
    {
        $user = $request->user();

        $anioId = (int) $request->input('anio_id', $request->query('anio_id', 0));
        if ($anioId <= 0) {
            return response()->json(['message' => 'anio_id es requerido'], 422);
        }

        $includeSinAsignar = filter_var(
            $request->input('include_sin_asignar', $request->query('include_sin_asignar', '0')),
            FILTER_VALIDATE_BOOLEAN
        );

        $institucionId = (int) ($user?->instituciones_id ?? 0);
        if ($institucionId <= 0) {
            $institucionId = (int) $request->input('instituciones_id', 0);
        }
        if ($institucionId <= 0) {
            return response()->json(['message' => 'instituciones_id es requerido'], 403);
        }

        $anioValor = trim((string) (DB::table('anios')->where('id', $anioId)->value('Anio') ?? ''));
        if ($anioValor === '') {
            return response()->json(['message' => 'Año inválido'], 422);
        }

        // Si se selecciona una gestión base (ej. "2026"), incluir también sub-gestiones ("2026/1", ...)
        $anioBase = null;
        if (preg_match('/^(\d{4})/', $anioValor, $m)) {
            $anioBase = (int) $m[1];
        }
        $anioEsBase = !str_contains($anioValor, '/');

        $cursos = $request->input('cursos', []);
        if (!is_array($cursos) || count($cursos) === 0) {
            return response()->json(['message' => 'cursos es requerido'], 422);
        }

        $pairs = [];
        foreach ($cursos as $c) {
            $curso = trim((string) ($c['curso'] ?? $c['LvlCurso'] ?? $c['Curso_Solicitado'] ?? ''));
            $paralelo = trim((string) ($c['paralelo'] ?? $c['Paralelo'] ?? $c['Paralelo_Solicitado'] ?? ''));
            if ($curso === '') continue;
            // paralelo puede venir vacío para representar "SIN DETERMINAR"
            $pairs[] = ['curso' => $curso, 'paralelo' => $paralelo];
        }
        if (count($pairs) === 0) {
            return response()->json(['message' => 'cursos inválido'], 422);
        }

        // Subquery coherente con lo que se muestra en la tabla de inscripciones.
        $anioLabelSubquery = "COALESCE((
            SELECT MAX(a.Anio)
            FROM calificaciones c
            INNER JOIN materias m ON m.id = c.materias_id
            INNER JOIN plandeestudios p ON p.id = m.plandeestudios_id
            INNER JOIN anios a ON a.id = p.anio_id
            WHERE c.infoestudiantesifas_id = infoestudiantesifas.id
        ), 'SIN ASIGNAR')";

        $q = DB::table('infoestudiantesifas')
            ->join('estudiantesifas', 'estudiantesifas.id', '=', 'infoestudiantesifas.estudiantesifas_id')
            ->where('infoestudiantesifas.instituciones_id', $institucionId)
            ->select([
                'estudiantesifas.Ap_Paterno',
                'estudiantesifas.Ap_Materno',
                'estudiantesifas.Nombre',
                'infoestudiantesifas.InstrumentoMusical',
                'infoestudiantesifas.InstrumentoMusicalSecundario',
                'infoestudiantesifas.Curso_Solicitado',
                'infoestudiantesifas.Paralelo_Solicitado',
                DB::raw($anioLabelSubquery . ' as Anio'),
            ]);

        // Filtrar por curso/paralelo seleccionados
        // Si paralelo es vacío, se interpreta como "SIN DETERMINAR" (NULL o '')
        $q->where(function ($outer) use ($pairs) {
            foreach ($pairs as $p) {
                $outer->orWhere(function ($w) use ($p) {
                    $w->where('infoestudiantesifas.Curso_Solicitado', $p['curso']);

                    if (trim((string) ($p['paralelo'] ?? '')) === '') {
                        $w->whereRaw("TRIM(COALESCE(infoestudiantesifas.Paralelo_Solicitado, '')) = ''");
                    } else {
                        $w->whereRaw("TRIM(COALESCE(infoestudiantesifas.Paralelo_Solicitado, '')) = ?", [$p['paralelo']]);
                    }
                });
            }
        });

        // Filtro por año (exacto) + opción de incluir SIN ASIGNAR
        if ($includeSinAsignar) {
            $q->where(function ($outer) use ($anioLabelSubquery, $anioValor, $anioEsBase, $anioBase) {
                $outer->where(function ($w) use ($anioLabelSubquery, $anioValor, $anioEsBase) {
                    if ($anioEsBase) {
                        $w->where(function ($ww) use ($anioLabelSubquery, $anioValor) {
                            $ww->whereRaw($anioLabelSubquery . ' = ?', [$anioValor])
                               ->orWhereRaw($anioLabelSubquery . ' LIKE ?', [$anioValor . '/%']);
                        });
                    } else {
                        $w->whereRaw($anioLabelSubquery . ' = ?', [$anioValor]);
                    }
                });

                $outer->orWhere(function ($w) use ($anioBase) {
                    $w->whereNotExists(function ($qq) {
                        $qq->select(DB::raw(1))
                            ->from('calificaciones as c')
                            ->whereRaw('c.infoestudiantesifas_id = infoestudiantesifas.id');
                    });
                    if (!empty($anioBase)) {
                        $w->whereRaw('YEAR(COALESCE(infoestudiantesifas.FechInsc, infoestudiantesifas.created_at)) = ?', [$anioBase]);
                    }
                });
            });
        } else {
            $q->where(function ($w) use ($anioLabelSubquery, $anioValor, $anioEsBase) {
                if ($anioEsBase) {
                    $w->where(function ($ww) use ($anioLabelSubquery, $anioValor) {
                        $ww->whereRaw($anioLabelSubquery . ' = ?', [$anioValor])
                           ->orWhereRaw($anioLabelSubquery . ' LIKE ?', [$anioValor . '/%']);
                    });
                } else {
                    $w->whereRaw($anioLabelSubquery . ' = ?', [$anioValor]);
                }
            });
        }

        $rows = $q
            ->orderBy('infoestudiantesifas.Curso_Solicitado')
            ->orderBy('infoestudiantesifas.Paralelo_Solicitado')
            ->orderBy('estudiantesifas.Ap_Paterno')
            ->orderBy('estudiantesifas.Ap_Materno')
            ->orderBy('estudiantesifas.Nombre')
            ->get();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'anio_id' => $anioId,
                'anio' => $anioValor,
                'include_sin_asignar' => $includeSinAsignar ? 1 : 0,
                'instituciones_id' => $institucionId,
                'total' => (int) $rows->count(),
            ],
        ]);
    }



    
}
