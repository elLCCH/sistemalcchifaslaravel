<?php

namespace App\Services\AccionesGrupales;

use App\Models\Infoestudiantesifas;
use Illuminate\Support\Facades\DB;

class ModificacionGrupalInscripcionesService
{
    private function norm($value): string
    {
        return trim((string) ($value ?? ''));
    }

    private function buildVerificacion(array $cambios, bool $quitoAsignaciones): string
    {
        $cambios = array_values(array_unique(array_filter(array_map(function ($x) {
            return strtoupper(trim((string) $x));
        }, $cambios), function ($x) {
            return $x !== '';
        })));

        $textoCambios = '';
        if (count($cambios) === 1) {
            $textoCambios = $cambios[0];
        } elseif (count($cambios) === 2) {
            $textoCambios = $cambios[0] . ' Y ' . $cambios[1];
        } elseif (count($cambios) >= 3) {
            $last = array_pop($cambios);
            $textoCambios = implode(', ', $cambios) . ' Y ' . $last;
        }

        if ($quitoAsignaciones && $textoCambios === '') {
            return 'MODIFICACIÓN GRUPAL: SE QUITÓ ASIGNACIONES';
        }

        if (!$quitoAsignaciones && $textoCambios !== '') {
            return 'MODIFICACIÓN GRUPAL: ' . $textoCambios;
        }

        if ($quitoAsignaciones && $textoCambios !== '') {
            // Debe incluir la frase exacta de asignaciones.
            return 'MODIFICACIÓN GRUPAL: ' . $textoCambios . '; SE QUITÓ ASIGNACIONES';
        }

        return 'MODIFICACIÓN GRUPAL';
    }

    public function ejecutar(array $payload, $user): array
    {
        $ids = array_values(array_unique(array_map('intval', $payload['ids'] ?? [])));
        $ids = array_values(array_filter($ids, fn ($x) => $x > 0));

        if (count($ids) === 0) {
            return ['ok' => false, 'message' => 'No hay inscripciones seleccionadas'];
        }

        $cambiarCurso = !empty($payload['cambiar_curso']);
        $cambiarParalelo = !empty($payload['cambiar_paralelo']);
        $cambiarTurno = !empty($payload['cambiar_turno']);

        $nuevoCurso = $this->norm($payload['nuevo_curso'] ?? null);
        $nuevoParalelo = (string) ($payload['nuevo_paralelo'] ?? ''); // permite "" como valor válido
        $nuevoParalelo = trim($nuevoParalelo);
        $nuevoTurno = $this->norm($payload['nuevo_turno'] ?? null);

        $quitarAsignaciones = !empty($payload['quitar_asignaciones']);
        $anioAsignacionesId = (int) ($payload['anio_id_asignaciones'] ?? 0);
        $resolucion = $this->norm($payload['resolucion'] ?? null);

        if (!$cambiarCurso && !$cambiarParalelo && !$cambiarTurno && !$quitarAsignaciones) {
            return ['ok' => false, 'message' => 'Seleccione al menos una acción (cambio o quitar asignaciones).'];
        }

        if ($quitarAsignaciones) {
            if ($anioAsignacionesId <= 0) {
                return ['ok' => false, 'message' => 'anio_id_asignaciones es requerido para quitar asignaciones'];
            }
            if ($resolucion === '') {
                return ['ok' => false, 'message' => 'resolucion es requerido para quitar asignaciones'];
            }
        }

        $institucionId = !empty($user?->instituciones_id) ? (int) $user->instituciones_id : null;

        $stats = [
            'total_ids' => count($ids),
            'total_encontradas' => 0,
            'total_actualizadas' => 0,
            'total_sin_cambios' => 0,
            'deleted_calificaciones' => 0,
        ];

        return DB::transaction(function () use (
            $ids,
            $institucionId,
            $cambiarCurso,
            $cambiarParalelo,
            $cambiarTurno,
            $nuevoCurso,
            $nuevoParalelo,
            $nuevoTurno,
            $quitarAsignaciones,
            $anioAsignacionesId,
            $resolucion,
            $stats
        ) {
            $infosQ = Infoestudiantesifas::query()->whereIn('id', $ids);
            if (!empty($institucionId)) {
                $infosQ->where('instituciones_id', $institucionId);
            }

            $infos = $infosQ->get();
            $stats['total_encontradas'] = $infos->count();

            if ($infos->count() === 0) {
                return ['ok' => false, 'message' => 'No se encontraron inscripciones para modificar (verifique institución/permisos).'];
            }

            $foundIds = $infos->pluck('id')->map(fn ($x) => (int) $x)->all();

            // 1) Quitar asignaciones (calificaciones) por anio_id + resolucion
            if ($quitarAsignaciones) {
                $deleted = DB::table('calificaciones as c')
                    ->join('materias as m', 'm.id', '=', 'c.materias_id')
                    ->join('plandeestudios as p', 'p.id', '=', 'm.plandeestudios_id')
                    ->join('carreras as ca', 'ca.id', '=', 'p.carreras_id')
                    ->whereIn('c.infoestudiantesifas_id', $foundIds)
                    ->where('p.anio_id', $anioAsignacionesId)
                    ->where('ca.Resolucion', $resolucion)
                    ->delete();

                $stats['deleted_calificaciones'] = (int) $deleted;

                // Recalcular CantidadMateriasAsignadas
                $counts = DB::table('calificaciones')
                    ->selectRaw('infoestudiantesifas_id, COUNT(*) as cnt')
                    ->whereIn('infoestudiantesifas_id', $foundIds)
                    ->groupBy('infoestudiantesifas_id')
                    ->pluck('cnt', 'infoestudiantesifas_id');

                foreach ($foundIds as $infoId) {
                    $cnt = (int) ($counts[$infoId] ?? 0);
                    Infoestudiantesifas::query()->where('id', (int) $infoId)->update(['CantidadMateriasAsignadas' => $cnt]);
                }
            }

            // 2) Cambios de campos + Verificacion
            foreach ($infos as $info) {
                $cambios = [];
                $dirty = false;

                if ($cambiarCurso) {
                    $actual = $this->norm($info->Curso_Solicitado);
                    if ($nuevoCurso !== '' && $nuevoCurso !== $actual) {
                        $info->Curso_Solicitado = $nuevoCurso;
                        $cambios[] = 'CURSO';
                        $dirty = true;
                    }
                }

                if ($cambiarParalelo) {
                    $actual = trim((string) ($info->Paralelo_Solicitado ?? ''));
                    if ($nuevoParalelo !== $actual) {
                        // permite '' (SIN DETERMINAR)
                        $info->Paralelo_Solicitado = $nuevoParalelo;
                        $cambios[] = 'PARALELO';
                        $dirty = true;
                    }
                }

                if ($cambiarTurno) {
                    $actual = $this->norm($info->Turno);
                    if ($nuevoTurno !== '' && $nuevoTurno !== $actual) {
                        $info->Turno = $nuevoTurno;
                        $cambios[] = 'TURNO';
                        $dirty = true;
                    }
                }

                $debeSetearVerif = (count($cambios) > 0) || $quitarAsignaciones;

                if ($debeSetearVerif) {
                    $info->Verificacion = $this->buildVerificacion($cambios, $quitarAsignaciones);
                    $dirty = true;
                }

                if ($dirty) {
                    $info->save();
                    $stats['total_actualizadas']++;
                } else {
                    $stats['total_sin_cambios']++;
                }
            }

            return [
                'ok' => true,
                'stats' => $stats,
            ];
        });
    }
}
