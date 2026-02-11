<?php

namespace App\Services\AccionesGrupales;

use App\Models\Califhistorias;
use App\Models\Calificaciones;
use App\Models\Infoestudiantesifas;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AsignarMateriasPorHistorialService
{
    private const VERIF_FAIL = 'NO SE PUDO AUTOASIGNAR';
    private const VERIF_OK = 'AUTOASIGNADO';

    private function ciSoloNumeros($value): string
    {
        return preg_replace('/\D+/', '', (string) ($value ?? '')) ?? '';
    }

    private function intOrNull($value): ?int
    {
        if ($value === null) return null;
        if ($value === '') return null;
        if (is_int($value)) return $value;
        if (is_numeric($value)) return (int) $value;
        return null;
    }

    private function esAprobado(?int $promedio, ?int $recup): bool
    {
        $p = $promedio ?? -1;
        $r = $recup ?? -1;
        return max($p, $r) >= 61;
    }

    private function parseSiglasPrerrequisitos(?string $raw): array
    {
        $txt = trim((string) ($raw ?? ''));
        if ($txt === '') return [];

        // Separadores típicos: coma + espacios ("ARM-101, MUS-101"), punto y coma, pipes, slashes.
        $parts = preg_split('/[\s,;|\/]+/', $txt) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $s = strtoupper(trim((string) $p));
            if ($s !== '') $out[] = $s;
        }
        return array_values(array_unique($out));
    }

    private function normUpper($value): string
    {
        return strtoupper(trim((string) ($value ?? '')));
    }

    private function paraleloWhere($q, string $field, ?string $paralelo): void
    {
        $p = trim((string) ($paralelo ?? ''));
        if ($p === '') {
            $q->where(function ($w) use ($field) {
                $w->whereNull($field)->orWhere($field, '');
            });
        } else {
            $q->where($field, $p);
        }
    }

    public function ejecutar(array $payload, $user): array
    {
        $uuid = (string) Str::uuid();

        $anioId = (int) ($payload['anio_id'] ?? 0);
        $institucionId = (int) ($payload['instituciones_id'] ?? 0);
        if (!empty($user?->instituciones_id)) {
            $institucionId = (int) $user->instituciones_id;
        }

        $resolucion = trim((string) ($payload['resolucion'] ?? ''));
        $nivel = trim((string) ($payload['nivel'] ?? ''));
        $modo = strtolower(trim((string) ($payload['modo'] ?? '')));
        $cursos = is_array($payload['cursos'] ?? null) ? $payload['cursos'] : [];

        if ($modo === '') {
            return ['ok' => false, 'message' => 'Debe seleccionar un modo'];
        }
        if (!in_array($modo, ['superior', 'capacitacion'], true)) {
            return ['ok' => false, 'message' => 'Modo inválido'];
        }

        if ($anioId <= 0) {
            return ['ok' => false, 'message' => 'anio_id es requerido'];
        }
        if ($institucionId <= 0) {
            return ['ok' => false, 'message' => 'instituciones_id es requerido'];
        }
        if ($resolucion === '' || $nivel === '') {
            return ['ok' => false, 'message' => 'resolucion y nivel son requeridos'];
        }
        if (count($cursos) === 0) {
            return ['ok' => false, 'message' => 'Debe seleccionar al menos un curso'];
        }

        // Año base (para acotar inscripciones SIN ASIGNAR a ese año, igual que el listado)
        $anioValor = trim((string) (DB::table('anios')->where('id', $anioId)->value('Anio') ?? ''));
        $anioBase = 0;
        if (preg_match('/^(\d{4})/', $anioValor, $m)) {
            $anioBase = (int) $m[1];
        }

        $institucionNombre = trim((string) (
            DB::table('instituciones')->where('id', $institucionId)->value('Nombre')
            ?? DB::table('instituciones')->where('id', $institucionId)->value('NombreInstitucion')
            ?? ''
        ));
        $institucionNombreLower = mb_strtolower($institucionNombre);

        $carrerasIds = DB::table('carreras')
            ->where('instituciones_id', $institucionId)
            ->whereRaw('TRIM(COALESCE(Resolucion, \'\')) = ?', [$resolucion])
            ->whereRaw('TRIM(COALESCE(Nivel, \'\')) = ?', [$nivel])
            ->pluck('id')
            ->map(fn ($x) => (int) $x)
            ->values();

        if ($carrerasIds->count() === 0) {
            return ['ok' => false, 'message' => 'No se encontraron carreras para esa Resolución/Nivel en la institución'];
        }

        $planRows = DB::table('plandeestudios')
            ->where('anio_id', $anioId)
            ->whereIn('carreras_id', $carrerasIds)
            ->select(['id', 'RangoLvlCurso', 'LvlCurso', 'NombreMateria', 'SiglaMateria', 'SiglasPrerrequisitos'])
            ->orderBy('RangoLvlCurso')
            ->orderBy('Rango')
            ->get();

        if ($planRows->count() === 0) {
            return ['ok' => false, 'message' => 'No hay plan de estudios para ese Año/Resolución/Nivel'];
        }

        // course -> rank
        $courseRank = [];
        // rank -> [siglas]
        $rankSiglas = [];
        // course -> [planRows]
        $coursePlan = [];
        // set global de siglas válidas del plan (para validar coincidencia con historial)
        $planSiglasAll = [];

        foreach ($planRows as $r) {
            $curso = trim((string) ($r->LvlCurso ?? ''));
            $rank = (int) ($r->RangoLvlCurso ?? 0);
            $sigla = $this->normUpper($r->SiglaMateria ?? '');

            if ($sigla !== '') {
                $planSiglasAll[$sigla] = true;
            }

            if ($curso !== '' && $rank > 0) {
                if (!isset($courseRank[$curso]) || $rank < (int) $courseRank[$curso]) {
                    $courseRank[$curso] = $rank;
                }
            }

            if ($rank > 0 && $sigla !== '') {
                $rankSiglas[$rank] = $rankSiglas[$rank] ?? [];
                $rankSiglas[$rank][$sigla] = true;
            }

            if ($curso !== '') {
                $coursePlan[$curso] = $coursePlan[$curso] ?? [];
                $coursePlan[$curso][] = $r;
            }
        }

        $now = now();

        DB::beginTransaction();
        try {
            DB::table('asignaciones_lotes')->insert([
                'uuid' => $uuid,
                'instituciones_id' => $institucionId,
                'anio_id' => $anioId,
                'resolucion' => $resolucion,
                'nivel' => $nivel,
                'cursos_json' => json_encode($cursos, JSON_UNESCAPED_UNICODE),
                'actor_type' => $user ? get_class($user) : null,
                'actor_id' => $user?->id ?? null,
                'created_at' => $now,
            ]);

            $stats = [
                'total_infos' => 0,
                'total_creadas' => 0,
                'total_estudiantes_con_asignacion' => 0,
                'total_anotados' => 0,
                'total_saltados_por_ya_asignado' => 0,
            ];

            foreach ($cursos as $sel) {
                $cursoSel = trim((string) ($sel['curso'] ?? ''));
                $parSel = trim((string) ($sel['paralelo'] ?? ''));
                if ($cursoSel === '') continue;

                // Seleccionar SOLO inscripciones sin asignaciones (0 calificaciones)
                $infosQ = DB::table('infoestudiantesifas as info')
                    ->join('estudiantesifas as est', 'est.id', '=', 'info.estudiantesifas_id')
                    ->where('info.instituciones_id', $institucionId)
                    ->whereRaw('TRIM(COALESCE(info.Curso_Solicitado, \'\')) = ?', [$cursoSel]);

                $this->paraleloWhere($infosQ, 'info.Paralelo_Solicitado', $parSel);

                $infosQ->whereNotExists(function ($qq) {
                    $qq->select(DB::raw(1))
                        ->from('calificaciones as c')
                        ->whereColumn('c.infoestudiantesifas_id', 'info.id');
                });

                if ($anioBase > 0) {
                    $infosQ->whereRaw('YEAR(COALESCE(info.FechInsc, info.created_at)) = ?', [$anioBase]);
                }

                $infos = $infosQ->select([
                    'info.id as info_id',
                    'info.Curso_Solicitado',
                    'info.Paralelo_Solicitado',
                    'info.Notas as info_notas',
                    'info.Verificacion as info_verificacion',
                    'est.CI as est_ci',
                    'est.id as est_id',
                ])->get();

                foreach ($infos as $info) {
                    $stats['total_infos']++;

                    $infoId = (int) $info->info_id;
                    $cursoSolicitado = trim((string) ($info->Curso_Solicitado ?? ''));
                    $parSolicitado = trim((string) ($info->Paralelo_Solicitado ?? ''));

                    $prevNotas = $info->info_notas;
                    $prevVerif = $info->info_verificacion;

                    $requestedRank = (int) ($courseRank[$cursoSolicitado] ?? 0);
                    if ($requestedRank <= 0) {
                        $newNotas = 'NO EXISTE CURSO EN EL PLAN DE ESTUDIOS PARA LA RESOLUCIÓN SELECCIONADA';
                        $this->anotarInfo($uuid, $infoId, $prevNotas, $prevVerif, $newNotas, self::VERIF_FAIL);
                        $stats['total_anotados']++;
                        continue;
                    }

                    $ciNum = $this->ciSoloNumeros($info->est_ci ?? '');
                    $estId = (int) ($info->est_id ?? 0);

                    // Historial por CI + Institución (sistema anterior) + Historial por calificaciones (sistema nuevo)
                    // Nota: Para el sistema nuevo, se filtra por siglas del plan seleccionado para evitar falsos positivos.
                    $histRows = [];
                    if ($ciNum !== '') {
                        $ciNumSearch = (string) $ciNum;
                        if (strlen($ciNumSearch) > 7) {
                            $ciNumSearch = substr($ciNumSearch, 0, 7);
                        }

                        $fetch = function (bool $withInstitucion) use ($ciNumSearch, $institucionNombreLower) {
                            $q = Califhistorias::query()
                                ->where('CI', 'like', '%' . $ciNumSearch . '%');
                            if ($withInstitucion && $institucionNombreLower !== '') {
                                $q->whereRaw('LOWER(TRIM(COALESCE(Institucion, \'\'))) = ?', [$institucionNombreLower]);
                            }
                            return $q->limit(2000)->get();
                        };

                        $cand = $fetch(true);
                        if ($cand->count() === 0) {
                            $cand = $fetch(false);
                        }

                        foreach ($cand as $r) {
                            if ($this->ciSoloNumeros($r->CI) === $ciNum) $histRows[] = $r;
                        }
                    }

                    $nuevoHist = [];
                    if ($estId > 0) {
                        $nuevoHist = DB::table('calificaciones as c')
                            ->join('infoestudiantesifas as infoh', 'infoh.id', '=', 'c.infoestudiantesifas_id')
                            ->join('materias as mh', 'mh.id', '=', 'c.materias_id')
                            ->join('plandeestudios as ph', 'ph.id', '=', 'mh.plandeestudios_id')
                            ->where('infoh.estudiantesifas_id', $estId)
                            ->where('infoh.instituciones_id', $institucionId)
                            ->select([
                                'ph.SiglaMateria as Sigla',
                                'c.Promedio as Promedio',
                                'c.PruebaRecuperacion as PruebaRecuperacion',
                            ])
                            ->limit(5000)
                            ->get();
                    }

                    if (count($histRows) === 0 && $nuevoHist->count() === 0) {
                        if ($requestedRank > 1) {
                            $newNotas = 'ESTUDIANTE SIN HISTORIAL, POR LO TANTO NUEVO';
                            $this->anotarInfo($uuid, $infoId, $prevNotas, $prevVerif, $newNotas, self::VERIF_FAIL);
                            $stats['total_anotados']++;
                            continue;
                        }
                        // Primer nivel y sin historial: asignar todo el curso solicitado y marcar AUTOASIGNADO (con nota)
                        $result = $this->asignarCursoDesdePlan(
                            $uuid,
                            $infoId,
                            $cursoSolicitado,
                            $parSolicitado,
                            $anioId,
                            $carrerasIds->all(),
                            [],
                            true,
                            $modo,
                            false
                        );

                        // Importante: NO sobrescribir Notas aquí, porque asignarCursoDesdePlan ya anota las materias AUTOASIGNADAS.
                        // Solo si no se creó nada, dejar constancia explícita.
                        if (($result['created'] ?? 0) <= 0) {
                            $this->anotarInfo($uuid, $infoId, $prevNotas, $prevVerif, 'ESTUDIANTE SIN HISTORIAL, POR LO TANTO NUEVO', self::VERIF_FAIL);
                            $stats['total_anotados']++;
                        }

                        $stats['total_creadas'] += $result['created'];
                        if ($result['created'] > 0) $stats['total_estudiantes_con_asignacion']++;
                        if ($result['note_written']) $stats['total_anotados']++;
                        continue;
                    }

                    // Aprobados por sigla (Promedio o Recuperación >= 61)
                    $aprobadas = [];
                    $reproboAlgoPlan = false;
                    // (1) Sistema anterior: califhistorias
                    foreach ($histRows as $r) {
                        $sig = $this->normUpper($r->Sigla ?? '');
                        if ($sig === '') continue;

                        $prom = $this->intOrNull($r->Promedio);
                        $rec = $this->intOrNull($r->PruebaRecuperacion);

                        $aprob = $this->esAprobado($prom, $rec);
                        if ($aprob) {
                            $aprobadas[$sig] = true;
                        } else {
                            // En modo capacitación: si reprueba una materia del plan, repite TODO.
                            if ($modo === 'capacitacion' && isset($planSiglasAll[$sig])) {
                                $reproboAlgoPlan = true;
                            }
                        }
                    }

                    // (2) Sistema nuevo: calificaciones (por estudiante/institución)
                    foreach ($nuevoHist as $r) {
                        $sig = $this->normUpper($r->Sigla ?? '');
                        if ($sig === '') continue;

                        // Para el sistema nuevo solo se toma lo que pertenece al plan seleccionado,
                        // así no bloquea por historial de otra resolución/plan.
                        if (!isset($planSiglasAll[$sig])) {
                            continue;
                        }

                        $prom = $this->intOrNull($r->Promedio);
                        $rec = $this->intOrNull($r->PruebaRecuperacion);

                        $aprob = $this->esAprobado($prom, $rec);
                        if ($aprob) {
                            $aprobadas[$sig] = true;
                        } else {
                            if ($modo === 'capacitacion') {
                                $reproboAlgoPlan = true;
                            }
                        }
                    }

                    // Si hay materias aprobadas en el historial que NO existen en el plan seleccionado,
                    // entonces no se asigna nada y se deja anotado el detalle.
                    $siglasNoCoinciden = [];
                    foreach ($aprobadas as $sig => $_) {
                        if (!isset($planSiglasAll[$sig])) {
                            $siglasNoCoinciden[] = $sig;
                        }
                    }
                    if (count($siglasNoCoinciden) > 0) {
                        sort($siglasNoCoinciden);
                        $newNotas = 'MATERIAS DEL HISTORIAL NO COINCIDEN CON EL PLAN SELECCIONADO: ' . implode(', ', $siglasNoCoinciden);
                        $this->anotarInfo($uuid, $infoId, $prevNotas, $prevVerif, $newNotas, self::VERIF_FAIL);
                        $stats['total_anotados']++;
                        continue;
                    }

                    // Determinar el primer rank pendiente según el plan
                    $pendingRank = 0;
                    $maxRank = 0;
                    foreach ($rankSiglas as $rank => $set) {
                        $maxRank = max($maxRank, (int) $rank);
                        // si falta al menos una sigla de ese rank, es pendiente
                        foreach ($set as $sigla => $_) {
                            if (!isset($aprobadas[$sigla])) {
                                $pendingRank = (int) $rank;
                                break 2;
                            }
                        }
                    }

                    if ($pendingRank === 0) {
                        // Todo aprobado (según plan)
                        $pendingRank = $maxRank + 1;
                    }

                    // Caso especial: aprobó todo el plan pero se reinscribe a 1ro superior.
                    // No se asigna nada y se deja constancia.
                    if (
                        $pendingRank === ($maxRank + 1)
                        && $requestedRank === 1
                        && stripos($cursoSolicitado, 'SUPERIOR') !== false
                    ) {
                        $newNotas = 'HISTORIAL INDICA QUE EL ESTUDIANTE APROBÓ TODO EL PLAN DE ESTUDIOS, PERO SE REINSCRIBIÓ A 1RO SUPERIOR. NO SE AUTOASIGNÓ NINGUNA MATERIA.';
                        $this->anotarInfo($uuid, $infoId, $prevNotas, $prevVerif, $newNotas, self::VERIF_FAIL);
                        $stats['total_anotados']++;
                        continue;
                    }

                    if ($pendingRank !== $requestedRank) {
                        $cursoPendiente = null;
                        foreach ($courseRank as $c => $rnk) {
                            if ((int) $rnk === (int) $pendingRank) {
                                $cursoPendiente = (string) $c;
                                break;
                            }
                        }

                        $faltantes = [];
                        if (isset($rankSiglas[$pendingRank])) {
                            foreach ($rankSiglas[$pendingRank] as $sigla => $_) {
                                if (!isset($aprobadas[$sigla])) {
                                    $faltantes[] = $sigla;
                                }
                            }
                        }
                        sort($faltantes);
                        if (count($faltantes) > 12) {
                            $faltantes = array_slice($faltantes, 0, 12);
                            $faltantes[] = '...';
                        }

                        $newNotas = 'NO COINCIDE EL HISTORIAL CON EL CURSO SOLICITADO';
                        if (!empty($cursoPendiente)) {
                            $newNotas .= ' | CURSO PENDIENTE SEGÚN HISTORIAL: ' . $cursoPendiente;
                        }
                        $newNotas .= ' | CURSO SOLICITADO: ' . $cursoSolicitado;
                        if (count($faltantes) > 0) {
                            $newNotas .= ' | FALTANTES: ' . implode(', ', $faltantes);
                        }
                        $this->anotarInfo($uuid, $infoId, $prevNotas, $prevVerif, $newNotas, self::VERIF_FAIL);
                        $stats['total_anotados']++;
                        continue;
                    }

                    // Asignar SOLO materias del curso solicitado, que no estén aprobadas, y cumplan prerrequisitos
                    $result = $this->asignarCursoDesdePlan(
                        $uuid,
                        $infoId,
                        $cursoSolicitado,
                        $parSolicitado,
                        $anioId,
                        $carrerasIds->all(),
                        array_keys($aprobadas),
                        false,
                        $modo,
                        $reproboAlgoPlan
                    );

                    // Si se asignó algo, marcar AUTOASIGNADO
                    // Nota: asignarCursoDesdePlan ya anota las materias AUTOASIGNADAS y setea VERIF_OK.

                    $stats['total_creadas'] += $result['created'];
                    if ($result['created'] > 0) $stats['total_estudiantes_con_asignacion']++;
                    if ($result['note_written']) $stats['total_anotados']++;
                }
            }

            DB::commit();

            return [
                'ok' => true,
                'uuid' => $uuid,
                'stats' => $stats,
            ];
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function anotarInfo(string $loteUuid, int $infoId, $prevNotas, $prevVerif, string $newNotas, string $newVerif): void
    {
        Infoestudiantesifas::query()->where('id', $infoId)->update([
            'Notas' => $newNotas,
            'Verificacion' => $newVerif,
        ]);

        DB::table('asignaciones_lote_items')->insert([
            'lote_uuid' => $loteUuid,
            'infoestudiantesifas_id' => $infoId,
            'action' => 'NOTE',
            'prev_notas' => $prevNotas,
            'prev_verificacion' => $prevVerif,
            'new_notas' => $newNotas,
            'new_verificacion' => $newVerif,
            'created_at' => now(),
        ]);
    }

    private function asignarCursoDesdePlan(
        string $loteUuid,
        int $infoId,
        string $cursoSolicitado,
        string $paraleloSolicitado,
        int $anioId,
        array $carrerasIds,
        array $siglasAprobadas,
        bool $sinHistorial,
        string $modo,
        bool $reproboAlgoPlan
    ): array {
        $noteWritten = false;
        $siglasCreadas = [];

        $siglasAprobSet = [];
        foreach ($siglasAprobadas as $s) {
            $siglasAprobSet[$this->normUpper($s)] = true;
        }

        $plan = DB::table('plandeestudios')
            ->where('anio_id', $anioId)
            ->whereIn('carreras_id', $carrerasIds)
            ->whereRaw('TRIM(COALESCE(LvlCurso, \'\')) = ?', [trim($cursoSolicitado)])
            ->select(['id', 'SiglaMateria', 'SiglasPrerrequisitos'])
            ->get();

        if ($plan->count() === 0) {
            $info = Infoestudiantesifas::query()->where('id', $infoId)->first();
            $this->anotarInfo($loteUuid, $infoId, $info?->Notas, $info?->Verificacion, 'NO EXISTE CURSO EN EL PLAN DE ESTUDIOS PARA LA RESOLUCIÓN SELECCIONADA', self::VERIF_FAIL);
            return ['created' => 0, 'note_written' => true];
        }

        $planIds = $plan->pluck('id')->map(fn ($x) => (int) $x)->values();

        $matQ = DB::table('materias')
            ->whereIn('plandeestudios_id', $planIds);
        $this->paraleloWhere($matQ, 'Paralelo', $paraleloSolicitado);

        $materias = $matQ->select(['id', 'plandeestudios_id'])->get()->keyBy('plandeestudios_id');

        if ($materias->count() === 0) {
            $info = Infoestudiantesifas::query()->where('id', $infoId)->first();
            $this->anotarInfo($loteUuid, $infoId, $info?->Notas, $info?->Verificacion, 'NO EXISTE EL PARALELO PARA ASIGNAR EN ESTE CURSO', self::VERIF_FAIL);
            return ['created' => 0, 'note_written' => true];
        }

        $created = 0;

        foreach ($plan as $p) {
            $siglaMateria = $this->normUpper($p->SiglaMateria ?? '');
            if ($siglaMateria === '') continue;

            // En modo capacitación, si reprobó alguna del plan, repite TODO (no se filtra por aprobadas)
            if (!($modo === 'capacitacion' && $reproboAlgoPlan)) {
                // Si ya está aprobada, no se asigna
                if (isset($siglasAprobSet[$siglaMateria])) {
                    continue;
                }
            }

            if (!$sinHistorial && !($modo === 'capacitacion' && $reproboAlgoPlan)) {
                $pr = $this->parseSiglasPrerrequisitos((string) ($p->SiglasPrerrequisitos ?? ''));
                $ok = true;
                foreach ($pr as $reqSigla) {
                    if (!isset($siglasAprobSet[$reqSigla])) {
                        $ok = false;
                        break;
                    }
                }
                if (!$ok) continue;
            }

            $materia = $materias->get((int) $p->id);
            if (!$materia) {
                continue;
            }

            $cal = Calificaciones::query()->firstOrCreate(
                ['infoestudiantesifas_id' => $infoId, 'materias_id' => (int) $materia->id],
                ['EstadoRegistroMateria' => 'REGULAR']
            );

            // IMPORTANTE: solo registrar/contar si realmente se creó.
            // Si ya existía, NO se debe meter al lote, para no borrarlo en rollback.
            if ($cal->wasRecentlyCreated) {
                $created++;
                $siglasCreadas[] = $siglaMateria;

                DB::table('asignaciones_lote_items')->insert([
                    'lote_uuid' => $loteUuid,
                    'infoestudiantesifas_id' => $infoId,
                    'calificaciones_id' => (int) $cal->id,
                    'materias_id' => (int) $materia->id,
                    'action' => 'ASSIGN',
                    'created_at' => now(),
                ]);
            }
        }

        // Actualizar contador
        $count = Calificaciones::query()->where('infoestudiantesifas_id', $infoId)->count();
        Infoestudiantesifas::query()->where('id', $infoId)->update(['CantidadMateriasAsignadas' => $count]);

        // Anotar exactamente qué se autoasignó.
        if ($created > 0) {
            $info = Infoestudiantesifas::query()->where('id', $infoId)->first();
            $prevNotas = (string) ($info?->Notas ?? '');
            $prevTrim = trim($prevNotas);

            $siglasCreadas = array_values(array_unique($siglasCreadas));
            sort($siglasCreadas);

            $siglasMostrar = $siglasCreadas;
            if (count($siglasMostrar) > 25) {
                $siglasMostrar = array_slice($siglasMostrar, 0, 25);
                $siglasMostrar[] = '...';
            }

            $linea = 'AUTOASIGNADO | CURSO: ' . trim($cursoSolicitado) . ' | PARALELO: ' . (trim($paraleloSolicitado) !== '' ? trim($paraleloSolicitado) : 'SIN DETERMINAR') .
                ' | MODO: ' . strtoupper(trim($modo)) .
                ' | CREADAS: ' . $created .
                ' | MATERIAS: ' . implode(', ', $siglasMostrar);

            $baseHtml = '';
            if ($prevTrim !== '') {
                $looksHtml = preg_match('/<\s*\w+[^>]*>/', $prevTrim) === 1;
                $baseHtml = $looksHtml ? $prevTrim : ('<p>' . str_replace("\n", '<br>', e($prevTrim)) . '</p>');
            }

            $extra = $sinHistorial
                ? 'ESTUDIANTE SIN HISTORIAL, POR LO TANTO NUEVO'
                : 'AUTOASIGNADO SIN NOVEDAD';

            $newNotas = $baseHtml
                . '<p>' . e($linea) . '</p>'
                . '<p>' . e($extra) . '</p>';
            $this->anotarInfo($loteUuid, $infoId, $info?->Notas, $info?->Verificacion, $newNotas, self::VERIF_OK);
            $noteWritten = true;
        }

        return ['created' => $created, 'note_written' => $noteWritten];
    }
}
