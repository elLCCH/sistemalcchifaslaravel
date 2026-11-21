<?php

namespace App\Services;

use App\Support\EstadoEvaluaciones;
use App\Support\SegundaInstanciaPendiente;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class AvisosCalificacionesMateria
{
    private const MODOS = [
        'MODO INSTRUMENTOS DE ESPECIALIDAD' => 'planteldocadmins_id',
        'MODO PRÁCTICA DE CONJUNTOS' => 'planteldocadmins_idPC',
        'MODO INSTRUMENTO COMPLEMENTARIO' => 'planteldocadmins_idOtros',
    ];

    public function consultar(array $materiasIds, int $institucionId, ?int $docenteId): array
    {
        if (!$materiasIds) return [];

        $materias = DB::table('materias as m')
            ->join('plandeestudios as p', 'p.id', '=', 'm.plandeestudios_id')
            ->join('carreras as ca', 'ca.id', '=', 'p.carreras_id')
            ->whereIn('m.id', $materiasIds)->where('ca.instituciones_id', $institucionId)
            ->select('m.id', 'm.EstadoHabilitacion', 'ca.id as carrera_id', 'ca.CantidadEvaluaciones')
            ->get();

        $avisos = [];
        $ordinarias = [];
        $segunda = [];
        foreach ($materias as $materia) {
            $estado = strtoupper(trim((string) $materia->EstadoHabilitacion));
            $cantidad = EstadoEvaluaciones::cantidad($materia->CantidadEvaluaciones);
            $habilitadas = str_contains($estado, 'NINGUNA') ? [] : EstadoEvaluaciones::habilitadas($estado, $cantidad ?? 4);
            $habilitadas = array_values(array_filter($habilitadas, fn ($numero) => $cantidad === null || $numero <= $cantidad));
            $avisos[$materia->id] = [
                'evaluaciones' => [],
                'segunda_instancia' => [],
                'configuracion_desconocida' => $cantidad === null && count($habilitadas) > 0,
            ];
            if ($cantidad !== null && $habilitadas) $ordinarias[$materia->id] = $habilitadas;
            if (!str_contains($estado, 'DESHABIL') && !str_contains($estado, 'NINGUNA')
                && (str_contains($estado, 'SEGUNDA INSTANCIA') || str_contains($estado, 'EVALUACIÓN FINAL') || str_contains($estado, 'EVALUACION FINAL'))) {
                $segunda[] = $materia->id;
            }
        }

        if ($ordinarias) {
            $query = $this->calificaciones(array_keys($ordinarias), $institucionId, $docenteId)
                ->join('estudiantesifas as e', 'e.id', '=', 'info.estudiantesifas_id')
                ->where(function ($pendientes) use ($ordinarias) {
                    foreach ([1, 2, 3, 4] as $numero) {
                        $ids = array_keys(array_filter($ordinarias, fn ($habilitadas) => in_array($numero, $habilitadas, true)));
                        if (!$ids) continue;
                        $pendientes->orWhere(fn ($evaluacion) => $evaluacion->whereIn('cal.materias_id', $ids)
                            ->where(fn ($vacios) => $vacios->whereNull("cal.Teorico$numero")->orWhereNull("cal.Practico$numero")));
                    }
                })
                ->select('cal.materias_id', 'cal.infoestudiantesifas_id', 'e.Ap_Paterno', 'e.Ap_Materno', 'e.Nombre');
            foreach ([1, 2, 3, 4] as $numero) {
                $query->selectRaw("CASE WHEN cal.Teorico$numero IS NULL OR cal.Practico$numero IS NULL THEN 1 ELSE 0 END as vacios$numero");
            }
            $pendientes = $query->orderBy('e.Ap_Paterno')->orderBy('e.Ap_Materno')->orderBy('e.Nombre')->get();
            foreach ($pendientes->groupBy('materias_id') as $materiaId => $estudiantes) {
                foreach ($ordinarias[$materiaId] as $numero) {
                    $nombres = $estudiantes->filter(fn ($estudiante) => (int) $estudiante->{"vacios$numero"} === 1)
                        ->unique('infoestudiantesifas_id')->map(fn ($estudiante) => $this->nombreEstudiante($estudiante))->values()->all();
                    if ($nombres) $avisos[$materiaId]['evaluaciones'][] = ['numero' => $numero, 'estudiantes' => count($nombres), 'nombres' => $nombres];
                }
            }
        }

        if ($segunda) {
            $carreras = $materias->map(fn ($materia) => (object) [
                'id' => $materia->carrera_id,
                'CantidadEvaluaciones' => $materia->CantidadEvaluaciones,
            ])->unique('id')->values();
            $query = $this->calificaciones($segunda, $institucionId, $docenteId)
                ->join('estudiantesifas as e', 'e.id', '=', 'info.estudiantesifas_id');
            SegundaInstanciaPendiente::aplicar($query, $carreras);
            $pendientes = $query->select('cal.materias_id', 'cal.infoestudiantesifas_id', 'e.Ap_Paterno', 'e.Ap_Materno', 'e.Nombre')
                ->orderBy('e.Ap_Paterno')->orderBy('e.Ap_Materno')->orderBy('e.Nombre')->get();
            foreach ($pendientes->groupBy('materias_id') as $materiaId => $estudiantes) {
                $avisos[$materiaId]['segunda_instancia'] = $estudiantes->unique('infoestudiantesifas_id')->map(
                    fn ($estudiante) => $this->nombreEstudiante($estudiante)
                )->values()->all();
            }
        }

        return $avisos;
    }

    private function nombreEstudiante(\stdClass $estudiante): string
    {
        return trim(implode(' ', array_filter([$estudiante->Ap_Paterno, $estudiante->Ap_Materno, $estudiante->Nombre])));
    }

    private function calificaciones(array $materiasIds, int $institucionId, ?int $docenteId): Builder
    {
        $query = DB::table('calificaciones as cal')
            ->join('infoestudiantesifas as info', 'info.id', '=', 'cal.infoestudiantesifas_id')
            ->join('materias as m', 'm.id', '=', 'cal.materias_id')
            ->join('plandeestudios as p', 'p.id', '=', 'm.plandeestudios_id')
            ->join('carreras as ca', 'ca.id', '=', 'p.carreras_id')
            ->whereIn('cal.materias_id', $materiasIds)
            ->where('ca.instituciones_id', $institucionId)->where('info.instituciones_id', $institucionId);

        if ($docenteId !== null) {
            $query->where(function ($scope) use ($docenteId) {
                $scope->where(fn ($normal) => $normal->whereNotIn('p.ModoMateria', array_keys(self::MODOS))->orWhereNull('p.ModoMateria'));
                foreach (self::MODOS as $modo => $campo) {
                    $scope->orWhere(fn ($especial) => $especial->where('p.ModoMateria', $modo)->where("info.$campo", $docenteId));
                }
            });
        }

        return $query;
    }
}
