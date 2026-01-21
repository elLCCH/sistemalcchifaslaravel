<?php

namespace App\Http\Controllers;

use App\Http\Middleware\UpdateTokenExpiration;
use App\Models\Estudiantesifas;
use App\Models\Planteladministrativos;
use App\Models\Planteldocentes;
use App\Models\Usuarioslcchs;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class PerfilController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }

    public function materias(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $modoInstrumentosEspecialidad = 'MODO INSTRUMENTOS DE ESPECIALIDAD';
        $modoPracticaConjuntos = 'MODO PRÁCTICA DE CONJUNTOS';
        $modoInstrumentoComplementario = 'MODO INSTRUMENTO COMPLEMENTARIO';

        // 1 docente (si existe) por materia, para evitar duplicados en listados.
        $docentePorMateria = DB::table('planteldocentesmaterias as pdm')
            ->select([
                'pdm.materias_id',
                DB::raw('MIN(pdm.planteldocentes_id) as docente_id'),
            ])
            ->groupBy('pdm.materias_id');

        // Resúmenes de docente(s) por materia, según inscripción, para los 3 modos especiales.
        // Admin/Super: si hay 1 docente -> mostrarlo, si hay varios -> "VARIOS DOCENTES".
        $resumenEspecialidad = DB::table('calificaciones as cal')
            ->join('infoestudiantesifas as info', 'cal.infoestudiantesifas_id', '=', 'info.id')
            ->join('materias as m2', 'cal.materias_id', '=', 'm2.id')
            ->join('plandeestudios as p2', 'm2.plandeestudios_id', '=', 'p2.id')
            ->where('p2.ModoMateria', $modoInstrumentosEspecialidad)
            ->whereNotNull('info.planteldocadmins_id')
            ->select([
                'cal.materias_id as materias_id',
                DB::raw('COUNT(DISTINCT info.planteldocadmins_id) as docentes_distintos'),
                DB::raw('MIN(info.planteldocadmins_id) as docente_id'),
            ])
            ->groupBy('cal.materias_id');

        $resumenPracticaConjuntos = DB::table('calificaciones as cal')
            ->join('infoestudiantesifas as info', 'cal.infoestudiantesifas_id', '=', 'info.id')
            ->join('materias as m2', 'cal.materias_id', '=', 'm2.id')
            ->join('plandeestudios as p2', 'm2.plandeestudios_id', '=', 'p2.id')
            ->where('p2.ModoMateria', $modoPracticaConjuntos)
            ->whereNotNull('info.planteldocadmins_idPC')
            ->select([
                'cal.materias_id as materias_id',
                DB::raw('COUNT(DISTINCT info.planteldocadmins_idPC) as docentes_distintos'),
                DB::raw('MIN(info.planteldocadmins_idPC) as docente_id'),
            ])
            ->groupBy('cal.materias_id');

        $resumenComplementario = DB::table('calificaciones as cal')
            ->join('infoestudiantesifas as info', 'cal.infoestudiantesifas_id', '=', 'info.id')
            ->join('materias as m2', 'cal.materias_id', '=', 'm2.id')
            ->join('plandeestudios as p2', 'm2.plandeestudios_id', '=', 'p2.id')
            ->where('p2.ModoMateria', $modoInstrumentoComplementario)
            ->whereNotNull('info.planteldocadmins_idOtros')
            ->select([
                'cal.materias_id as materias_id',
                DB::raw('COUNT(DISTINCT info.planteldocadmins_idOtros) as docentes_distintos'),
                DB::raw('MIN(info.planteldocadmins_idOtros) as docente_id'),
            ])
            ->groupBy('cal.materias_id');

        if ($user instanceof Planteldocentes) {
            // 1) Materias asignadas explícitamente al docente
            $asignadas = DB::table('planteldocentesmaterias as pdm')
                ->join('planteldocentes as d', 'pdm.planteldocentes_id', '=', 'd.id')
                ->join('materias as m', 'pdm.materias_id', '=', 'm.id')
                ->join('plandeestudios as p', 'm.plandeestudios_id', '=', 'p.id')
                ->join('carreras as c', 'p.carreras_id', '=', 'c.id')
                ->join('instituciones as i', 'c.instituciones_id', '=', 'i.id')
                ->leftJoin('anios as a', 'p.anio_id', '=', 'a.id')
                ->where('pdm.planteldocentes_id', (int) $user->id)
                ->where('c.instituciones_id', (int) $user->instituciones_id)
                ->select([
                    'm.id as materia_id',
                    'm.Paralelo as materia_paralelo',
                    'm.Turno as materia_turno',
                    'm.ModoAsistencia as materia_modo_asistencia',
                    'm.EstadoHabilitacion as materia_estado_habilitacion',
                    'm.EstadoEnvio as materia_estado_envio',
                    'p.LvlCurso as lvl_curso',
                    'p.NombreMateria as nombre_materia',
                    'p.SiglaMateria as sigla_materia',
                    'a.Anio as gestion',
                    'c.NombreCarrera as carrera',
                    'c.Resolucion as resolucion',
                    DB::raw("TRIM(CONCAT(COALESCE(d.Nombres,''), ' ', COALESCE(d.Apellidos,''))) as docente_nombre"),
                    'd.Foto as docente_foto',
                    'd.id as docente_id',
                    'i.id as instituciones_id',
                    'i.Nombre as institucion_nombre',
                    'i.Logo as institucion_logo',
                ])
                ->orderBy('a.Anio')
                ->orderBy('p.RangoLvlCurso')
                ->orderBy('p.Rango')
                ->orderBy('p.NombreMateria')
                ->get();

            // 2) Materias especiales (no asignadas) donde tiene estudiantes por inscripción
            $especialidad = DB::table('calificaciones as cal')
                ->join('infoestudiantesifas as info', 'cal.infoestudiantesifas_id', '=', 'info.id')
                ->join('planteldocentes as d', 'info.planteldocadmins_id', '=', 'd.id')
                ->join('materias as m', 'cal.materias_id', '=', 'm.id')
                ->join('plandeestudios as p', 'm.plandeestudios_id', '=', 'p.id')
                ->join('carreras as c', 'p.carreras_id', '=', 'c.id')
                ->join('instituciones as i', 'c.instituciones_id', '=', 'i.id')
                ->leftJoin('anios as a', 'p.anio_id', '=', 'a.id')
                ->where('info.planteldocadmins_id', (int) $user->id)
                ->where('info.instituciones_id', (int) $user->instituciones_id)
                ->where('c.instituciones_id', (int) $user->instituciones_id)
                ->where('p.ModoMateria', $modoInstrumentosEspecialidad)
                ->distinct()
                ->select([
                    'm.id as materia_id',
                    'm.Paralelo as materia_paralelo',
                    'm.Turno as materia_turno',
                    'm.ModoAsistencia as materia_modo_asistencia',
                    'm.EstadoHabilitacion as materia_estado_habilitacion',
                    'm.EstadoEnvio as materia_estado_envio',
                    'p.LvlCurso as lvl_curso',
                    'p.NombreMateria as nombre_materia',
                    'p.SiglaMateria as sigla_materia',
                    'a.Anio as gestion',
                    'c.NombreCarrera as carrera',
                    'c.Resolucion as resolucion',
                    DB::raw("TRIM(CONCAT(COALESCE(d.Nombres,''), ' ', COALESCE(d.Apellidos,''))) as docente_nombre"),
                    'd.Foto as docente_foto',
                    'd.id as docente_id',
                    'i.id as instituciones_id',
                    'i.Nombre as institucion_nombre',
                    'i.Logo as institucion_logo',
                ])
                ->orderBy('a.Anio')
                ->orderBy('p.RangoLvlCurso')
                ->orderBy('p.Rango')
                ->orderBy('p.NombreMateria')
                ->get();

            $practica = DB::table('calificaciones as cal')
                ->join('infoestudiantesifas as info', 'cal.infoestudiantesifas_id', '=', 'info.id')
                ->join('planteldocentes as d', 'info.planteldocadmins_idPC', '=', 'd.id')
                ->join('materias as m', 'cal.materias_id', '=', 'm.id')
                ->join('plandeestudios as p', 'm.plandeestudios_id', '=', 'p.id')
                ->join('carreras as c', 'p.carreras_id', '=', 'c.id')
                ->join('instituciones as i', 'c.instituciones_id', '=', 'i.id')
                ->leftJoin('anios as a', 'p.anio_id', '=', 'a.id')
                ->where('info.planteldocadmins_idPC', (int) $user->id)
                ->where('info.instituciones_id', (int) $user->instituciones_id)
                ->where('c.instituciones_id', (int) $user->instituciones_id)
                ->where('p.ModoMateria', $modoPracticaConjuntos)
                ->distinct()
                ->select([
                    'm.id as materia_id',
                    'm.Paralelo as materia_paralelo',
                    'm.Turno as materia_turno',
                    'm.ModoAsistencia as materia_modo_asistencia',
                    'm.EstadoHabilitacion as materia_estado_habilitacion',
                    'm.EstadoEnvio as materia_estado_envio',
                    'p.LvlCurso as lvl_curso',
                    'p.NombreMateria as nombre_materia',
                    'p.SiglaMateria as sigla_materia',
                    'a.Anio as gestion',
                    'c.NombreCarrera as carrera',
                    'c.Resolucion as resolucion',
                    DB::raw("TRIM(CONCAT(COALESCE(d.Nombres,''), ' ', COALESCE(d.Apellidos,''))) as docente_nombre"),
                    'd.Foto as docente_foto',
                    'd.id as docente_id',
                    'i.id as instituciones_id',
                    'i.Nombre as institucion_nombre',
                    'i.Logo as institucion_logo',
                ])
                ->orderBy('a.Anio')
                ->orderBy('p.RangoLvlCurso')
                ->orderBy('p.Rango')
                ->orderBy('p.NombreMateria')
                ->get();

            $complementario = DB::table('calificaciones as cal')
                ->join('infoestudiantesifas as info', 'cal.infoestudiantesifas_id', '=', 'info.id')
                ->join('planteldocentes as d', 'info.planteldocadmins_idOtros', '=', 'd.id')
                ->join('materias as m', 'cal.materias_id', '=', 'm.id')
                ->join('plandeestudios as p', 'm.plandeestudios_id', '=', 'p.id')
                ->join('carreras as c', 'p.carreras_id', '=', 'c.id')
                ->join('instituciones as i', 'c.instituciones_id', '=', 'i.id')
                ->leftJoin('anios as a', 'p.anio_id', '=', 'a.id')
                ->where('info.planteldocadmins_idOtros', (int) $user->id)
                ->where('info.instituciones_id', (int) $user->instituciones_id)
                ->where('c.instituciones_id', (int) $user->instituciones_id)
                ->where('p.ModoMateria', $modoInstrumentoComplementario)
                ->distinct()
                ->select([
                    'm.id as materia_id',
                    'm.Paralelo as materia_paralelo',
                    'm.Turno as materia_turno',
                    'm.ModoAsistencia as materia_modo_asistencia',
                    'm.EstadoHabilitacion as materia_estado_habilitacion',
                    'm.EstadoEnvio as materia_estado_envio',
                    'p.LvlCurso as lvl_curso',
                    'p.NombreMateria as nombre_materia',
                    'p.SiglaMateria as sigla_materia',
                    'a.Anio as gestion',
                    'c.NombreCarrera as carrera',
                    'c.Resolucion as resolucion',
                    DB::raw("TRIM(CONCAT(COALESCE(d.Nombres,''), ' ', COALESCE(d.Apellidos,''))) as docente_nombre"),
                    'd.Foto as docente_foto',
                    'd.id as docente_id',
                    'i.id as instituciones_id',
                    'i.Nombre as institucion_nombre',
                    'i.Logo as institucion_logo',
                ])
                ->orderBy('a.Anio')
                ->orderBy('p.RangoLvlCurso')
                ->orderBy('p.Rango')
                ->orderBy('p.NombreMateria')
                ->get();

            $merged = collect($asignadas)
                ->concat($especialidad)
                ->concat($practica)
                ->concat($complementario)
                ->unique('materia_id')
                ->values();

            return response()->json([
                'tipo' => 'planteldocentes',
                'data' => $this->groupByInstitucion($merged),
            ]);
        }

        if ($user instanceof Planteladministrativos) {
            $rows = DB::table('materias as m')
                ->join('plandeestudios as p', 'm.plandeestudios_id', '=', 'p.id')
                ->join('carreras as c', 'p.carreras_id', '=', 'c.id')
                ->join('instituciones as i', 'c.instituciones_id', '=', 'i.id')
                ->leftJoinSub($docentePorMateria, 'pdm1', function ($join) {
                    $join->on('pdm1.materias_id', '=', 'm.id');
                })
                ->leftJoin('planteldocentes as d_asig', 'pdm1.docente_id', '=', 'd_asig.id')
                ->leftJoinSub($resumenEspecialidad, 'esp', function ($join) {
                    $join->on('esp.materias_id', '=', 'm.id');
                })
                ->leftJoin('planteldocentes as d_esp', 'esp.docente_id', '=', 'd_esp.id')
                ->leftJoinSub($resumenPracticaConjuntos, 'pc', function ($join) {
                    $join->on('pc.materias_id', '=', 'm.id');
                })
                ->leftJoin('planteldocentes as d_pc', 'pc.docente_id', '=', 'd_pc.id')
                ->leftJoinSub($resumenComplementario, 'ot', function ($join) {
                    $join->on('ot.materias_id', '=', 'm.id');
                })
                ->leftJoin('planteldocentes as d_ot', 'ot.docente_id', '=', 'd_ot.id')
                ->leftJoin('anios as a', 'p.anio_id', '=', 'a.id')
                ->where('c.instituciones_id', (int) $user->instituciones_id)
                ->select([
                    'm.id as materia_id',
                    'm.Paralelo as materia_paralelo',
                    'm.Turno as materia_turno',
                    'm.ModoAsistencia as materia_modo_asistencia',
                    'm.EstadoHabilitacion as materia_estado_habilitacion',
                    'm.EstadoEnvio as materia_estado_envio',
                    'p.LvlCurso as lvl_curso',
                    'p.NombreMateria as nombre_materia',
                    'p.SiglaMateria as sigla_materia',
                    'a.Anio as gestion',
                    'c.NombreCarrera as carrera',
                    'c.Resolucion as resolucion',
                    DB::raw("CASE WHEN p.ModoMateria = '{$modoInstrumentosEspecialidad}' AND COALESCE(esp.docentes_distintos,0) > 1 THEN 'VARIOS DOCENTES' WHEN p.ModoMateria = '{$modoInstrumentosEspecialidad}' THEN TRIM(CONCAT(COALESCE(d_esp.Nombres,''), ' ', COALESCE(d_esp.Apellidos,''))) WHEN p.ModoMateria = '{$modoPracticaConjuntos}' AND COALESCE(pc.docentes_distintos,0) > 1 THEN 'VARIOS DOCENTES' WHEN p.ModoMateria = '{$modoPracticaConjuntos}' THEN TRIM(CONCAT(COALESCE(d_pc.Nombres,''), ' ', COALESCE(d_pc.Apellidos,''))) WHEN p.ModoMateria = '{$modoInstrumentoComplementario}' AND COALESCE(ot.docentes_distintos,0) > 1 THEN 'VARIOS DOCENTES' WHEN p.ModoMateria = '{$modoInstrumentoComplementario}' THEN TRIM(CONCAT(COALESCE(d_ot.Nombres,''), ' ', COALESCE(d_ot.Apellidos,''))) ELSE TRIM(CONCAT(COALESCE(d_asig.Nombres,''), ' ', COALESCE(d_asig.Apellidos,''))) END as docente_nombre"),
                    DB::raw("CASE WHEN p.ModoMateria = '{$modoInstrumentosEspecialidad}' AND COALESCE(esp.docentes_distintos,0) = 1 THEN d_esp.Foto WHEN p.ModoMateria = '{$modoInstrumentosEspecialidad}' THEN NULL WHEN p.ModoMateria = '{$modoPracticaConjuntos}' AND COALESCE(pc.docentes_distintos,0) = 1 THEN d_pc.Foto WHEN p.ModoMateria = '{$modoPracticaConjuntos}' THEN NULL WHEN p.ModoMateria = '{$modoInstrumentoComplementario}' AND COALESCE(ot.docentes_distintos,0) = 1 THEN d_ot.Foto WHEN p.ModoMateria = '{$modoInstrumentoComplementario}' THEN NULL ELSE d_asig.Foto END as docente_foto"),
                    DB::raw("CASE WHEN p.ModoMateria = '{$modoInstrumentosEspecialidad}' AND COALESCE(esp.docentes_distintos,0) = 1 THEN d_esp.id WHEN p.ModoMateria = '{$modoInstrumentosEspecialidad}' THEN NULL WHEN p.ModoMateria = '{$modoPracticaConjuntos}' AND COALESCE(pc.docentes_distintos,0) = 1 THEN d_pc.id WHEN p.ModoMateria = '{$modoPracticaConjuntos}' THEN NULL WHEN p.ModoMateria = '{$modoInstrumentoComplementario}' AND COALESCE(ot.docentes_distintos,0) = 1 THEN d_ot.id WHEN p.ModoMateria = '{$modoInstrumentoComplementario}' THEN NULL ELSE d_asig.id END as docente_id"),
                    'i.id as instituciones_id',
                    'i.Nombre as institucion_nombre',
                    'i.Logo as institucion_logo',
                ])
                ->orderBy('a.Anio')
                ->orderBy('p.RangoLvlCurso')
                ->orderBy('p.Rango')
                ->orderBy('p.NombreMateria');

            return response()->json([
                'tipo' => 'planteladministrativos',
                'data' => $this->groupByInstitucion($rows->get()),
            ]);
        }

        if ($user instanceof Usuarioslcchs) {
            $rows = DB::table('materias as m')
                ->join('plandeestudios as p', 'm.plandeestudios_id', '=', 'p.id')
                ->join('carreras as c', 'p.carreras_id', '=', 'c.id')
                ->join('instituciones as i', 'c.instituciones_id', '=', 'i.id')
                ->leftJoinSub($docentePorMateria, 'pdm1', function ($join) {
                    $join->on('pdm1.materias_id', '=', 'm.id');
                })
                ->leftJoin('planteldocentes as d_asig', 'pdm1.docente_id', '=', 'd_asig.id')
                ->leftJoinSub($resumenEspecialidad, 'esp', function ($join) {
                    $join->on('esp.materias_id', '=', 'm.id');
                })
                ->leftJoin('planteldocentes as d_esp', 'esp.docente_id', '=', 'd_esp.id')
                ->leftJoinSub($resumenPracticaConjuntos, 'pc', function ($join) {
                    $join->on('pc.materias_id', '=', 'm.id');
                })
                ->leftJoin('planteldocentes as d_pc', 'pc.docente_id', '=', 'd_pc.id')
                ->leftJoinSub($resumenComplementario, 'ot', function ($join) {
                    $join->on('ot.materias_id', '=', 'm.id');
                })
                ->leftJoin('planteldocentes as d_ot', 'ot.docente_id', '=', 'd_ot.id')
                ->leftJoin('anios as a', 'p.anio_id', '=', 'a.id')
                ->select([
                    'm.id as materia_id',
                    'm.Paralelo as materia_paralelo',
                    'm.Turno as materia_turno',
                    'm.ModoAsistencia as materia_modo_asistencia',
                    'm.EstadoHabilitacion as materia_estado_habilitacion',
                    'm.EstadoEnvio as materia_estado_envio',
                    'p.LvlCurso as lvl_curso',
                    'p.NombreMateria as nombre_materia',
                    'p.SiglaMateria as sigla_materia',
                    'a.Anio as gestion',
                    'c.NombreCarrera as carrera',
                    'c.Resolucion as resolucion',
                    DB::raw("CASE WHEN p.ModoMateria = '{$modoInstrumentosEspecialidad}' AND COALESCE(esp.docentes_distintos,0) > 1 THEN 'VARIOS DOCENTES' WHEN p.ModoMateria = '{$modoInstrumentosEspecialidad}' THEN TRIM(CONCAT(COALESCE(d_esp.Nombres,''), ' ', COALESCE(d_esp.Apellidos,''))) WHEN p.ModoMateria = '{$modoPracticaConjuntos}' AND COALESCE(pc.docentes_distintos,0) > 1 THEN 'VARIOS DOCENTES' WHEN p.ModoMateria = '{$modoPracticaConjuntos}' THEN TRIM(CONCAT(COALESCE(d_pc.Nombres,''), ' ', COALESCE(d_pc.Apellidos,''))) WHEN p.ModoMateria = '{$modoInstrumentoComplementario}' AND COALESCE(ot.docentes_distintos,0) > 1 THEN 'VARIOS DOCENTES' WHEN p.ModoMateria = '{$modoInstrumentoComplementario}' THEN TRIM(CONCAT(COALESCE(d_ot.Nombres,''), ' ', COALESCE(d_ot.Apellidos,''))) ELSE TRIM(CONCAT(COALESCE(d_asig.Nombres,''), ' ', COALESCE(d_asig.Apellidos,''))) END as docente_nombre"),
                    DB::raw("CASE WHEN p.ModoMateria = '{$modoInstrumentosEspecialidad}' AND COALESCE(esp.docentes_distintos,0) = 1 THEN d_esp.Foto WHEN p.ModoMateria = '{$modoInstrumentosEspecialidad}' THEN NULL WHEN p.ModoMateria = '{$modoPracticaConjuntos}' AND COALESCE(pc.docentes_distintos,0) = 1 THEN d_pc.Foto WHEN p.ModoMateria = '{$modoPracticaConjuntos}' THEN NULL WHEN p.ModoMateria = '{$modoInstrumentoComplementario}' AND COALESCE(ot.docentes_distintos,0) = 1 THEN d_ot.Foto WHEN p.ModoMateria = '{$modoInstrumentoComplementario}' THEN NULL ELSE d_asig.Foto END as docente_foto"),
                    DB::raw("CASE WHEN p.ModoMateria = '{$modoInstrumentosEspecialidad}' AND COALESCE(esp.docentes_distintos,0) = 1 THEN d_esp.id WHEN p.ModoMateria = '{$modoInstrumentosEspecialidad}' THEN NULL WHEN p.ModoMateria = '{$modoPracticaConjuntos}' AND COALESCE(pc.docentes_distintos,0) = 1 THEN d_pc.id WHEN p.ModoMateria = '{$modoPracticaConjuntos}' THEN NULL WHEN p.ModoMateria = '{$modoInstrumentoComplementario}' AND COALESCE(ot.docentes_distintos,0) = 1 THEN d_ot.id WHEN p.ModoMateria = '{$modoInstrumentoComplementario}' THEN NULL ELSE d_asig.id END as docente_id"),
                    'i.id as instituciones_id',
                    'i.Nombre as institucion_nombre',
                    'i.Logo as institucion_logo',
                ])
                ->orderBy('i.Nombre')
                ->orderBy('a.Anio')
                ->orderBy('p.RangoLvlCurso')
                ->orderBy('p.Rango')
                ->orderBy('p.NombreMateria');

            return response()->json([
                'tipo' => 'superlcchs',
                'data' => $this->groupByInstitucion($rows->get()),
            ]);
        }

        if ($user instanceof Estudiantesifas) {
            $infoIds = DB::table('infoestudiantesifas')
                ->where('estudiantesifas_id', (int) $user->id)
                ->pluck('id')
                ->map(fn ($x) => (int) $x)
                ->values()
                ->all();

            if (count($infoIds) === 0) {
                return response()->json([
                    'tipo' => 'estudiantesifas',
                    'data' => [],
                ]);
            }

            $rows = DB::table('calificaciones as cal')
                ->join('infoestudiantesifas as info', 'cal.infoestudiantesifas_id', '=', 'info.id')
                ->join('materias as m', 'cal.materias_id', '=', 'm.id')
                ->join('plandeestudios as p', 'm.plandeestudios_id', '=', 'p.id')
                ->join('carreras as c', 'p.carreras_id', '=', 'c.id')
                ->join('instituciones as i', 'c.instituciones_id', '=', 'i.id')
                ->leftJoinSub($docentePorMateria, 'pdm1', function ($join) {
                    $join->on('pdm1.materias_id', '=', 'm.id');
                })
                ->leftJoin('planteldocentes as d_asig', 'pdm1.docente_id', '=', 'd_asig.id')
                ->leftJoin('planteldocentes as d_esp', 'info.planteldocadmins_id', '=', 'd_esp.id')
                ->leftJoin('planteldocentes as d_pc', 'info.planteldocadmins_idPC', '=', 'd_pc.id')
                ->leftJoin('planteldocentes as d_ot', 'info.planteldocadmins_idOtros', '=', 'd_ot.id')
                ->leftJoin('anios as a', 'p.anio_id', '=', 'a.id')
                ->whereIn('cal.infoestudiantesifas_id', $infoIds)
                ->select([
                    'cal.id as calificacion_id',
                    'cal.infoestudiantesifas_id',
                    'cal.Teorico1', 'cal.Teorico2', 'cal.Teorico3', 'cal.Teorico4',
                    'cal.Practico1', 'cal.Practico2', 'cal.Practico3', 'cal.Practico4',
                    'cal.PromTeorico', 'cal.PromPractico', 'cal.Promedio',
                    'cal.PruebaRecuperacion',
                    'cal.EstadoRegistroMateria',
                    'm.id as materia_id',
                    'm.Paralelo as materia_paralelo',
                    'm.Turno as materia_turno',
                    'p.LvlCurso as lvl_curso',
                    'p.NombreMateria as nombre_materia',
                    'p.SiglaMateria as sigla_materia',
                    'a.Anio as gestion',
                    'c.NombreCarrera as carrera',
                    'c.Resolucion as resolucion',
                    DB::raw("CASE WHEN p.ModoMateria = '{$modoInstrumentosEspecialidad}' THEN TRIM(CONCAT(COALESCE(d_esp.Nombres,''), ' ', COALESCE(d_esp.Apellidos,''))) WHEN p.ModoMateria = '{$modoPracticaConjuntos}' THEN TRIM(CONCAT(COALESCE(d_pc.Nombres,''), ' ', COALESCE(d_pc.Apellidos,''))) WHEN p.ModoMateria = '{$modoInstrumentoComplementario}' THEN TRIM(CONCAT(COALESCE(d_ot.Nombres,''), ' ', COALESCE(d_ot.Apellidos,''))) ELSE TRIM(CONCAT(COALESCE(d_asig.Nombres,''), ' ', COALESCE(d_asig.Apellidos,''))) END as docente_nombre"),
                    DB::raw("CASE WHEN p.ModoMateria = '{$modoInstrumentosEspecialidad}' THEN d_esp.Foto WHEN p.ModoMateria = '{$modoPracticaConjuntos}' THEN d_pc.Foto WHEN p.ModoMateria = '{$modoInstrumentoComplementario}' THEN d_ot.Foto ELSE d_asig.Foto END as docente_foto"),
                    DB::raw("CASE WHEN p.ModoMateria = '{$modoInstrumentosEspecialidad}' THEN d_esp.id WHEN p.ModoMateria = '{$modoPracticaConjuntos}' THEN d_pc.id WHEN p.ModoMateria = '{$modoInstrumentoComplementario}' THEN d_ot.id ELSE d_asig.id END as docente_id"),
                    'i.id as instituciones_id',
                    'i.Nombre as institucion_nombre',
                    'i.Logo as institucion_logo',
                ])
                ->orderBy('i.Nombre')
                ->orderBy('a.Anio')
                ->orderBy('p.RangoLvlCurso')
                ->orderBy('p.Rango')
                ->orderBy('p.NombreMateria');

            return response()->json([
                'tipo' => 'estudiantesifas',
                'data' => $this->groupByInstitucion($rows->get()),
            ]);
        }

        return response()->json(['message' => 'Tipo de usuario no soportado'], 403);
    }

    public function updateMateriaDocente(Request $request, int $materiaId)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        if (!$user instanceof Planteldocentes) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $materiaId = (int) $materiaId;
        if ($materiaId <= 0) {
            return response()->json(['message' => 'Materia inválida'], 422);
        }

        $asignada = DB::table('planteldocentesmaterias')
            ->where('planteldocentes_id', (int) $user->id)
            ->where('materias_id', $materiaId)
            ->exists();

        if (!$asignada) {
            return response()->json(['message' => 'Materia no asignada a este docente'], 403);
        }

        $institucionMateriaId = DB::table('materias')
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->where('materias.id', $materiaId)
            ->value('carreras.instituciones_id');

        if ((int) $institucionMateriaId !== (int) $user->instituciones_id) {
            return response()->json(['message' => 'Materia fuera de su institución'], 403);
        }

        $validated = $request->validate([
            'Paralelo' => ['nullable', 'string', 'max:10'],
            'Turno' => ['nullable', 'string', 'max:50'],
            'ModoAsistencia' => ['nullable', 'string', 'max:50'],
            'EstadoHabilitacion' => ['nullable', 'string', 'max:50'],
            'EstadoEnvio' => ['nullable', 'string', 'max:250'],
        ]);

        $update = [];
        foreach (['Paralelo', 'Turno', 'ModoAsistencia', 'EstadoHabilitacion', 'EstadoEnvio'] as $k) {
            if (array_key_exists($k, $validated)) {
                $update[$k] = $validated[$k];
            }
        }

        if (count($update) === 0) {
            return response()->json(['message' => 'Nada para actualizar'], 422);
        }

        DB::table('materias')->where('id', $materiaId)->update($update);

        $row = DB::table('materias as m')
            ->join('plandeestudios as p', 'm.plandeestudios_id', '=', 'p.id')
            ->join('carreras as c', 'p.carreras_id', '=', 'c.id')
            ->join('instituciones as i', 'c.instituciones_id', '=', 'i.id')
                ->leftJoin('anios as a', 'p.anio_id', '=', 'a.id')
            ->where('m.id', $materiaId)
            ->select([
                'm.id as materia_id',
                'm.Paralelo as materia_paralelo',
                'm.Turno as materia_turno',
                'm.ModoAsistencia as materia_modo_asistencia',
                'm.EstadoHabilitacion as materia_estado_habilitacion',
                'm.EstadoEnvio as materia_estado_envio',
                'p.LvlCurso as lvl_curso',
                'p.NombreMateria as nombre_materia',
                'p.SiglaMateria as sigla_materia',
                'a.Anio as gestion',
                'i.id as instituciones_id',
                'i.Nombre as institucion_nombre',
                'i.Logo as institucion_logo',
            ])
            ->first();

        return response()->json(['data' => $row]);
    }

    public function deudas(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        if (!$user instanceof Estudiantesifas) {
            return response()->json(['message' => 'Solo estudiantes pueden ver deudas aquí'], 403);
        }

        $gestion = trim((string) ($request->query('gestion') ?? ''));
        if ($gestion === '') {
            $gestion = (string) date('Y');
        }

        $infos = DB::table('infoestudiantesifas')
            ->where('estudiantesifas_id', (int) $user->id)
            ->select(['id', 'instituciones_id'])
            ->get();

        if ($infos->count() === 0) {
            return response()->json(['data' => []]);
        }

        $anioSubquery = "COALESCE((
            SELECT MAX(a.Anio)
            FROM calificaciones c
            INNER JOIN materias m ON m.id = c.materias_id
            INNER JOIN plandeestudios p ON p.id = m.plandeestudios_id
            INNER JOIN anios a ON a.id = p.anio_id
            WHERE c.infoestudiantesifas_id = infoestudiantesifas.id
        ), 'SIN ASIGNAR')";

        $out = [];

        foreach ($infos as $info) {
            $infoId = (int) $info->id;
            $instId = (int) $info->instituciones_id;

            $inst = DB::table('instituciones')
                ->where('id', $instId)
                ->select(['id', 'Nombre', 'Logo'])
                ->first();

            $anioAsignacion = DB::table('infoestudiantesifas')
                ->where('id', $infoId)
                ->selectRaw($anioSubquery . ' as Anio')
                ->value('Anio');
            $anioAsignacion = (string) ($anioAsignacion ?? 'SIN ASIGNAR');

            $companerosQuery = DB::table('pagoslcch')
                ->join('infoestudiantesifas', 'pagoslcch.infoestudiantesifas_id', '=', 'infoestudiantesifas.id')
                ->where('pagoslcch.gestion', $gestion)
                ->where('infoestudiantesifas.id', '!=', $infoId)
                ->where('infoestudiantesifas.instituciones_id', $instId)
                ->whereRaw($anioSubquery . ' = ?', [$anioAsignacion])
                ->where('pagoslcch.estadopago', '=', 'PAGADO')
                ->whereBetween('pagoslcch.mes', [1, 12]);

            $minMesCompaneros = (int) (($companerosQuery->clone())->min('pagoslcch.mes') ?? 0);
            $maxMesCompaneros = (int) (($companerosQuery->clone())->max('pagoslcch.mes') ?? 0);

            $mesesPagados = DB::table('pagoslcch')
                ->where('infoestudiantesifas_id', $infoId)
                ->where('gestion', $gestion)
                ->where('estadopago', '=', 'PAGADO')
                ->whereBetween('mes', [1, 12])
                ->select('mes')
                ->distinct()
                ->pluck('mes')
                ->map(fn ($x) => (int) $x)
                ->values()
                ->all();

            $mesesPagadosSet = array_fill_keys($mesesPagados, true);

            $mesesDeuda = [];
            if ($minMesCompaneros > 0 && $maxMesCompaneros > 0 && $minMesCompaneros <= $maxMesCompaneros) {
                for ($m = $minMesCompaneros; $m <= $maxMesCompaneros; $m++) {
                    if (!isset($mesesPagadosSet[$m])) {
                        $mesesDeuda[] = $m;
                    }
                }
            }

            $out[] = (object) [
                'instituciones_id' => $inst?->id,
                'institucion_nombre' => $inst?->Nombre,
                'institucion_logo' => $inst?->Logo,
                'infoestudiantesifas_id' => $infoId,
                'gestion' => $gestion,
                'anio_asignacion' => $anioAsignacion,
                'min_mes_companeros' => $minMesCompaneros,
                'max_mes_companeros' => $maxMesCompaneros,
                'meses_pagados' => $mesesPagados,
                'meses_deuda' => $mesesDeuda,
            ];
        }

        return response()->json([
            'data' => $this->groupByInstitucion(collect($out)),
        ]);
    }

    private function groupByInstitucion($rows)
    {
        $map = [];

        foreach ($rows as $row) {
            $instId = (int) ($row->instituciones_id ?? 0);
            if (!isset($map[$instId])) {
                $map[$instId] = [
                    'instituciones_id' => $row->instituciones_id ?? null,
                    'institucion_nombre' => $row->institucion_nombre ?? null,
                    'institucion_logo' => $row->institucion_logo ?? null,
                    'items' => [],
                ];
            }

            $map[$instId]['items'][] = $row;
        }

        return array_values($map);
    }
}
