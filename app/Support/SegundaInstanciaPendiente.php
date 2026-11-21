<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

class SegundaInstanciaPendiente
{
    public static function aplicar(Builder $query, Collection $carreras): void
    {
        $query->whereNull('cal.PruebaRecuperacion')->whereNotNull('cal.Promedio')
            ->whereRaw('cal.Promedio >= COALESCE(ca.NotaMinRevalida, 0)')
            ->whereRaw('cal.Promedio < CASE WHEN ca.NotaAprobacion > 0 THEN ca.NotaAprobacion ELSE 61 END');

        foreach ([2, 3, 4] as $numero) {
            $carrerasMenores = $carreras->filter(fn ($carrera) => self::cantidadParaRetiro($carrera->CantidadEvaluaciones) < $numero)->pluck('id')->all();
            $query->where(function ($retiro) use ($numero, $carrerasMenores) {
                $retiro->whereIn('ca.id', $carrerasMenores)->orWhereNull("cal.Teorico$numero")->orWhereNull("cal.Practico$numero")
                    ->orWhere("cal.Teorico$numero", '<>', 0)->orWhere("cal.Practico$numero", '<>', 0);
            });
        }

        $query->whereNotExists(function ($pendientes) {
            $pendientes->selectRaw('1')->from('calificaciones as otras')
                ->join('materias as om', 'om.id', '=', 'otras.materias_id')
                ->join('plandeestudios as op', 'op.id', '=', 'om.plandeestudios_id')
                ->join('carreras as oc', 'oc.id', '=', 'op.carreras_id')
                ->whereColumn('otras.infoestudiantesifas_id', 'cal.infoestudiantesifas_id')
                ->whereColumn('op.anio_id', 'p.anio_id')->whereColumn('oc.instituciones_id', 'ca.instituciones_id')
                ->whereNull('otras.Promedio');
        })->whereRaw("(SELECT COUNT(*) FROM calificaciones otras
            JOIN materias om ON om.id = otras.materias_id
            JOIN plandeestudios op ON op.id = om.plandeestudios_id
            JOIN carreras oc ON oc.id = op.carreras_id
            WHERE otras.infoestudiantesifas_id = cal.infoestudiantesifas_id
            AND op.anio_id = p.anio_id AND oc.instituciones_id = ca.instituciones_id
            AND COALESCE(otras.PruebaRecuperacion, otras.Promedio) < CASE WHEN ca.NotaAprobacion > 0 THEN ca.NotaAprobacion ELSE 61 END) < 4");
    }

    private static function cantidadParaRetiro($valor): int
    {
        $texto = strtoupper(trim((string) ($valor ?? '')));
        if ($texto === '' || str_contains($texto, 'CERO')) return 0;
        if (preg_match('/\d+/', $texto, $match)) {
            $cantidad = (int) $match[0];
            return $cantidad <= 4 ? $cantidad : 4;
        }
        return 4;
    }
}
