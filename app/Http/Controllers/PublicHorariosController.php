<?php

namespace App\Http\Controllers;

use App\Models\Anios;
use App\Models\Calificaciones;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PublicHorariosController extends Controller
{
    public function anios()
    {
        return Anios::query()
            ->select(['id', 'Anio', 'Estado', 'Visibilidad', 'Predeterminado'])
            ->orderBy('id', 'desc')
            ->get();
    }

    /**
     * Cursos para la gestión (anio_id) agrupados por (LvlCurso, Paralelo, Turno)
     * Incluye materias individuales con nombre/sigla desde plandeestudios.
     */
    public function cursos(Request $request)
    {
        $anioId = $request->input('Anio_id') ?? $request->input('anio_id');
        $institucionId = $request->input('instituciones_id') ?? $request->input('Instituciones_id') ?? $request->input('institucion_id');
        if (!$anioId) {
            return response()->json(['message' => 'Anio_id es requerido'], 422);
        }

        $query = DB::table('materias')
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->join('instituciones', 'carreras.instituciones_id', '=', 'instituciones.id')
            ->where('plandeestudios.anio_id', '=', $anioId)
            ->select([
                'materias.id as materia_id',
                'materias.Paralelo as Paralelo',
                'materias.Turno as Turno',
                'plandeestudios.LvlCurso as LvlCurso',
                'plandeestudios.NombreMateria as NombreMateria',
                'plandeestudios.SiglaMateria as SiglaMateria',
                'plandeestudios.RangoLvlCurso as RangoLvlCurso',
                'plandeestudios.Rango as Rango',
                'instituciones.id as instituciones_id',
                'instituciones.Nombre as institucion_nombre',
                'instituciones.Logo as institucion_logo',
            ])
            ->orderBy('plandeestudios.RangoLvlCurso')
            ->orderBy('plandeestudios.Rango')
            ->orderBy('plandeestudios.NombreMateria')
            ->orderBy('instituciones.Nombre');

        if ($institucionId) {
            $query->where('instituciones.id', '=', $institucionId);
        }

        $rows = $query->get();

        $cursos = [];
        foreach ($rows as $row) {
            $key = ($row->instituciones_id ?? '') . '|' . ($row->LvlCurso ?? '') . '|' . ($row->Paralelo ?? '') . '|' . ($row->Turno ?? '');

            if (!isset($cursos[$key])) {
                $nivelCurso = trim(($row->LvlCurso ?? '') . ' ' . ($row->Paralelo ?? ''));
                $cursos[$key] = [
                    'key' => $key,
                    'Anio_id' => (string) $anioId,
                    'LvlCurso' => $row->LvlCurso,
                    'Paralelo' => $row->Paralelo,
                    'Turno' => $row->Turno,
                    'instituciones_id' => (int) $row->instituciones_id,
                    'institucion_nombre' => $row->institucion_nombre,
                    'institucion_logo' => $row->institucion_logo,
                    // Compatibilidad con UI legacy
                    'NivelCurso' => $nivelCurso,
                    'materias' => [],
                ];
            }

            $cursos[$key]['materias'][] = [
                'materia_id' => (int) $row->materia_id,
                'NombreMateria' => $row->NombreMateria,
                'SiglaMateria' => $row->SiglaMateria,
            ];
        }

        return array_values($cursos);
    }

    /**
     * Lista estudiantes únicos asignados a un curso (LvlCurso+Paralelo+Turno) en una gestión.
     */
    public function estudiantesCurso(Request $request)
    {
        $anioId = $request->input('Anio_id') ?? $request->input('anio_id');
        $lvlCurso = $request->input('LvlCurso') ?? $request->input('lvlCurso');
        $paralelo = $request->input('Paralelo') ?? $request->input('paralelo');
        $turno = $request->input('Turno') ?? $request->input('turno');
        $institucionId = $request->input('instituciones_id') ?? $request->input('Instituciones_id') ?? $request->input('institucion_id');

        if (!$anioId || !$lvlCurso || !$paralelo || !$turno) {
            return response()->json(['message' => 'Anio_id, LvlCurso, Paralelo y Turno son requeridos'], 422);
        }

        $q = $this->buildEstudiantesQuery()
            ->where('plandeestudios.anio_id', '=', $anioId)
            ->where('plandeestudios.LvlCurso', '=', $lvlCurso)
            ->where('materias.Paralelo', '=', $paralelo)
            ->where('materias.Turno', '=', $turno)
            ->orderBy('estudiantesifas.Ap_Paterno')
            ->orderBy('estudiantesifas.Ap_Materno')
            ->orderBy('estudiantesifas.Nombre');

        if ($institucionId) {
            $q->where('carreras.instituciones_id', '=', $institucionId);
        }

        return $q->get();
    }

    /**
     * Lista estudiantes únicos de toda la gestión (mezcla de todos los cursos).
     */
    public function estudiantesGestion(Request $request)
    {
        $anioId = $request->input('Anio_id') ?? $request->input('anio_id');
        $institucionId = $request->input('instituciones_id') ?? $request->input('Instituciones_id') ?? $request->input('institucion_id');
        if (!$anioId) {
            return response()->json(['message' => 'Anio_id es requerido'], 422);
        }

        $q = $this->buildEstudiantesQuery()
            ->where('plandeestudios.anio_id', '=', $anioId)
            ->orderBy('estudiantesifas.Ap_Paterno')
            ->orderBy('estudiantesifas.Ap_Materno')
            ->orderBy('estudiantesifas.Nombre');

        if ($institucionId) {
            $q->where('carreras.instituciones_id', '=', $institucionId);
        }

        return $q->get();
    }

    private function buildEstudiantesQuery()
    {
        // Se usa groupBy para evitar repetidos por múltiples materias en calificaciones.
        return Calificaciones::query()
            ->join('materias', 'calificaciones.materias_id', '=', 'materias.id')
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->join('infoestudiantesifas', 'calificaciones.infoestudiantesifas_id', '=', 'infoestudiantesifas.id')
            ->join('estudiantesifas', 'infoestudiantesifas.estudiantesifas_id', '=', 'estudiantesifas.id')
            ->leftJoin('planteldocentes as docE', 'infoestudiantesifas.planteldocadmins_id', '=', 'docE.id')
            ->leftJoin('planteldocentes as docPC', 'infoestudiantesifas.planteldocadmins_idPC', '=', 'docPC.id')
            ->select([
                'infoestudiantesifas.id as id',
                'estudiantesifas.Ap_Paterno',
                'estudiantesifas.Ap_Materno',
                'estudiantesifas.Nombre',
                'estudiantesifas.CI',
                'estudiantesifas.Sexo',
                'estudiantesifas.Celular',
                'estudiantesifas.FechaNac as FechNac',
                'infoestudiantesifas.Turno',
                'infoestudiantesifas.Categoria',
                'infoestudiantesifas.Observacion',
                'infoestudiantesifas.planteldocadmins_id as Admin_id',
                'infoestudiantesifas.planteldocadmins_idPC as Admin_idPC',
                'infoestudiantesifas.InstrumentoMusical as Especialidad',
                'docE.Nombre as NombreAdmin',
                'docE.Apellidos as Ap_PAdmin',
                DB::raw("'' as Ap_MAdmin"),
                'docE.CelularTrabajo as CelularTrabajo',
                'docPC.Nombre as NombreAdminPC',
                'docPC.Apellidos as Ap_PAdminPC',
                DB::raw("'' as Ap_MAdminPC"),
                'docPC.CelularTrabajo as CelularTrabajoPC',
                DB::raw("'REGULAR' as Arrastre"),
                DB::raw("TIMESTAMPDIFF(YEAR, estudiantesifas.FechaNac, CURDATE()) as Edad"),
            ])
            ->groupBy([
                'infoestudiantesifas.id',
                'estudiantesifas.Ap_Paterno',
                'estudiantesifas.Ap_Materno',
                'estudiantesifas.Nombre',
                'estudiantesifas.CI',
                'estudiantesifas.Sexo',
                'estudiantesifas.Celular',
                'estudiantesifas.FechaNac',
                'infoestudiantesifas.Turno',
                'infoestudiantesifas.Categoria',
                'infoestudiantesifas.Observacion',
                'infoestudiantesifas.planteldocadmins_id',
                'infoestudiantesifas.planteldocadmins_idPC',
                'infoestudiantesifas.InstrumentoMusical',
                'docE.Nombre',
                'docE.Apellidos',
                'docE.CelularTrabajo',
                'docPC.Nombre',
                'docPC.Apellidos',
                'docPC.CelularTrabajo',
            ]);
    }
}
