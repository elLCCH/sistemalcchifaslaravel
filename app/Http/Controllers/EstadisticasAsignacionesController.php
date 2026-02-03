<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;

class EstadisticasAsignacionesController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }
    public function porMaterias(Request $request)
    {
        $validated = $request->validate([
            'anio_id' => ['required', 'integer', 'min:1'],
            'instituciones_id' => ['nullable', 'integer', 'min:1'],
            'malla' => ['nullable', 'string'],
            'resolucion' => ['nullable', 'string'],
            'nivel_curso' => ['nullable', 'string'],
            'lvl_curso' => ['nullable', 'string'],
            'simplificado' => ['nullable'],
            'modo' => ['nullable', 'string'],
        ]);

        $user = $request->user();
        $isSuperAdmin = empty($user?->instituciones_id);

        $anioId = (int) $validated['anio_id'];
        $institucionId = $isSuperAdmin
            ? (int) ($validated['instituciones_id'] ?? 0)
            : (int) ($user->instituciones_id ?? 0);

        if ($isSuperAdmin && $institucionId <= 0) {
            return response()->json(['message' => 'instituciones_id es requerido para superadmin'], 422);
        }

        $malla = trim((string) ($validated['malla'] ?? '')); // compat (NombreCarrera)
        $resolucion = trim((string) ($validated['resolucion'] ?? ''));

        $nivelCurso = trim((string) ($validated['nivel_curso'] ?? '')); // compat (LvlCurso)
        $lvlCurso = trim((string) ($validated['lvl_curso'] ?? ''));

        $modoRaw = strtoupper(trim((string) ($validated['modo'] ?? $request->query('modo', ''))));
        if ($modoRaw === '') {
            // compat anterior
            $simplificadoRaw = (string) ($validated['simplificado'] ?? $request->query('simplificado', '0'));
            $simplificado = in_array(strtolower(trim($simplificadoRaw)), ['1', 'true', 'si', 'sí', 'yes'], true);
            $modoRaw = $simplificado ? 'SIMPLIFICADO' : 'NORMAL';
        }

        $modo = in_array($modoRaw, ['NORMAL', 'SIMPLIFICADO', 'SIMPLE DIFERENCIADO', 'SIMPLE_DIFERENCIADO'], true)
            ? str_replace(' ', '_', $modoRaw)
            : 'NORMAL';

        if ($modo === 'SIMPLE_DIFERENCIADO') {
            // 1) Por cada estudiante, construir el conjunto de materias que lleva en ese curso/paralelo
            $sub = DB::table('calificaciones as c')
                ->join('materias as m', 'c.materias_id', '=', 'm.id')
                ->join('plandeestudios as p', 'm.plandeestudios_id', '=', 'p.id')
                ->join('anios as a', 'p.anio_id', '=', 'a.id')
                ->join('carreras as ca', 'p.carreras_id', '=', 'ca.id')
                ->join('infoestudiantesifas as ie', 'ie.id', '=', 'c.infoestudiantesifas_id')
                ->join('estudiantesifas as e', 'ie.estudiantesifas_id', '=', 'e.id')
                ->where('p.anio_id', $anioId);

            if ($institucionId > 0) {
                $sub->where('ca.instituciones_id', $institucionId)
                    ->where('ie.instituciones_id', $institucionId);
            }

            if ($malla !== '') {
                $sub->whereRaw('TRIM(COALESCE(ca.NombreCarrera, \'\')) = ?', [$malla]);
            }

            if ($resolucion !== '') {
                $sub->whereRaw('TRIM(COALESCE(ca.Resolucion, \'\')) = ?', [$resolucion]);
            }

            $lvlCursoFiltro = $lvlCurso !== '' ? $lvlCurso : $nivelCurso;
            if ($lvlCursoFiltro !== '') {
                $sub->whereRaw('TRIM(COALESCE(p.LvlCurso, \'\')) = ?', [$lvlCursoFiltro]);
            }

            $sub->selectRaw('a.Anio as Anio')
                ->selectRaw('TRIM(COALESCE(ca.Resolucion, \'SIN RESOLUCION\')) as Resolucion')
                ->selectRaw('TRIM(COALESCE(p.LvlCurso, \'SIN NIVEL\')) as LvlCurso')
                ->selectRaw('COALESCE(p.RangoLvlCurso, 0) as RangoLvlCurso')
                ->selectRaw('TRIM(COALESCE(m.Paralelo, \'\')) as Paralelo')
                ->selectRaw('TRIM(COALESCE(m.Turno, \'\')) as Turno')
                ->selectRaw('ie.id as info_id')
                ->selectRaw("UPPER(TRIM(COALESCE(e.Sexo, ''))) as Sexo")
                ->selectRaw("UPPER(TRIM(COALESCE(ie.Categoria, ''))) as Categoria")
                // Lista de materias que este estudiante lleva (en este curso/paralelo)
                ->selectRaw("GROUP_CONCAT(DISTINCT TRIM(COALESCE(p.NombreMateria, '')) ORDER BY COALESCE(p.Rango, 0) SEPARATOR ' / ') as Materias")
                ->groupBy([
                    DB::raw('a.Anio'),
                    DB::raw('TRIM(COALESCE(ca.Resolucion, \'SIN RESOLUCION\'))'),
                    DB::raw('TRIM(COALESCE(p.LvlCurso, \'SIN NIVEL\'))'),
                    DB::raw('COALESCE(p.RangoLvlCurso, 0)'),
                    DB::raw('TRIM(COALESCE(m.Paralelo, \'\'))'),
                    DB::raw('TRIM(COALESCE(m.Turno, \'\'))'),
                    DB::raw('ie.id'),
                    DB::raw("UPPER(TRIM(COALESCE(e.Sexo, '')))") ,
                    DB::raw("UPPER(TRIM(COALESCE(ie.Categoria, '')))") ,
                ]);

            // 2) Agrupar estudiantes por el conjunto de materias
            $outer = DB::query()
                ->fromSub($sub, 's')
                ->selectRaw('Anio')
                ->selectRaw('Resolucion')
                ->selectRaw('LvlCurso')
                ->selectRaw('RangoLvlCurso')
                ->selectRaw('Paralelo')
                ->selectRaw('Turno')
                ->selectRaw('Materias')

                ->selectRaw("SUM(CASE WHEN Categoria='NUEVO' AND Sexo='MASCULINO' THEN 1 ELSE 0 END) as Nuevos_M")
                ->selectRaw("SUM(CASE WHEN Categoria='NUEVO' AND Sexo='FEMENINO' THEN 1 ELSE 0 END) as Nuevos_F")
                ->selectRaw("SUM(CASE WHEN Categoria='NUEVO' THEN 1 ELSE 0 END) as Total_Nuevos")

                ->selectRaw("SUM(CASE WHEN Categoria='ANTIGUO' AND Sexo='MASCULINO' THEN 1 ELSE 0 END) as Antiguos_M")
                ->selectRaw("SUM(CASE WHEN Categoria='ANTIGUO' AND Sexo='FEMENINO' THEN 1 ELSE 0 END) as Antiguos_F")
                ->selectRaw("SUM(CASE WHEN Categoria='ANTIGUO' THEN 1 ELSE 0 END) as Total_Antiguos")

                ->selectRaw("SUM(CASE WHEN Sexo='MASCULINO' THEN 1 ELSE 0 END) as Total_M")
                ->selectRaw("SUM(CASE WHEN Sexo='FEMENINO' THEN 1 ELSE 0 END) as Total_F")
                ->selectRaw('COUNT(*) as Total_Gral')

                ->groupBy([
                    DB::raw('Anio'),
                    DB::raw('Resolucion'),
                    DB::raw('LvlCurso'),
                    DB::raw('RangoLvlCurso'),
                    DB::raw('Paralelo'),
                    DB::raw('Turno'),
                    DB::raw('Materias'),
                ])
                ->orderBy('Resolucion')
                ->orderBy('RangoLvlCurso')
                ->orderBy('LvlCurso')
                ->orderBy('Paralelo')
                ->orderBy('Turno')
                // primero los que llevan más materias (para ver el bloque "completo" arriba)
                ->orderByRaw('CHAR_LENGTH(Materias) DESC')
                ->orderBy('Materias');

            return response()->json(['data' => $outer->get()]);
        }

        $sexoExpr = "UPPER(TRIM(COALESCE(e.Sexo, '')))";
        $catExpr = "UPPER(TRIM(COALESCE(ie.Categoria, '')))";

        // Base: TODAS las materias creadas (aunque tengan 0 asignados)
        $q = DB::table('materias as m')
            ->join('plandeestudios as p', 'm.plandeestudios_id', '=', 'p.id')
            ->join('anios as a', 'p.anio_id', '=', 'a.id')
            ->join('carreras as ca', 'p.carreras_id', '=', 'ca.id')
            ->leftJoin('calificaciones as c', 'c.materias_id', '=', 'm.id')
            ->leftJoin('infoestudiantesifas as ie', function ($join) use ($institucionId) {
                $join->on('ie.id', '=', 'c.infoestudiantesifas_id');
                // Importante: condición dentro del JOIN para no convertirlo en INNER JOIN
                if ($institucionId > 0) {
                    $join->where('ie.instituciones_id', '=', $institucionId);
                }
            })
            ->leftJoin('estudiantesifas as e', 'ie.estudiantesifas_id', '=', 'e.id')
            ->where('p.anio_id', $anioId);

        if ($institucionId > 0) {
            // Filtrar materias por institución (pero mantener LEFT JOIN para conteos)
            $q->where('ca.instituciones_id', $institucionId);
        }

        if ($malla !== '') {
            $q->whereRaw('TRIM(COALESCE(ca.NombreCarrera, \'\')) = ?', [$malla]);
        }

        if ($resolucion !== '') {
            $q->whereRaw('TRIM(COALESCE(ca.Resolucion, \'\')) = ?', [$resolucion]);
        }

        $lvlCursoFiltro = $lvlCurso !== '' ? $lvlCurso : $nivelCurso;
        if ($lvlCursoFiltro !== '') {
            $q->whereRaw('TRIM(COALESCE(p.LvlCurso, \'\')) = ?', [$lvlCursoFiltro]);
        }

        $q->selectRaw('a.Anio as Anio')
            ->selectRaw('TRIM(COALESCE(ca.Resolucion, \'SIN RESOLUCION\')) as Resolucion')
            ->selectRaw('TRIM(COALESCE(p.LvlCurso, \'SIN NIVEL\')) as LvlCurso')
            ->selectRaw('COALESCE(p.RangoLvlCurso, 0) as RangoLvlCurso')
            ->selectRaw('TRIM(COALESCE(m.Paralelo, \'\')) as Paralelo')
            ->selectRaw('TRIM(COALESCE(m.Turno, \'\')) as Turno')
            ->when($modo === 'NORMAL', function ($qq) {
                $qq->selectRaw('COALESCE(p.Rango, 0) as Rango')
                    ->selectRaw('TRIM(COALESCE(p.NombreMateria, \'SIN MATERIA\')) as NombreMateria');
            })

            // Nuevos
            ->selectRaw("COUNT(DISTINCT CASE WHEN {$catExpr}='NUEVO' AND {$sexoExpr}='MASCULINO' THEN ie.id END) as Nuevos_M")
            ->selectRaw("COUNT(DISTINCT CASE WHEN {$catExpr}='NUEVO' AND {$sexoExpr}='FEMENINO' THEN ie.id END) as Nuevos_F")
            ->selectRaw("COUNT(DISTINCT CASE WHEN {$catExpr}='NUEVO' THEN ie.id END) as Total_Nuevos")

            // Antiguos
            ->selectRaw("COUNT(DISTINCT CASE WHEN {$catExpr}='ANTIGUO' AND {$sexoExpr}='MASCULINO' THEN ie.id END) as Antiguos_M")
            ->selectRaw("COUNT(DISTINCT CASE WHEN {$catExpr}='ANTIGUO' AND {$sexoExpr}='FEMENINO' THEN ie.id END) as Antiguos_F")
            ->selectRaw("COUNT(DISTINCT CASE WHEN {$catExpr}='ANTIGUO' THEN ie.id END) as Total_Antiguos")

            // Totales por sexo
            ->selectRaw("COUNT(DISTINCT CASE WHEN {$sexoExpr}='MASCULINO' THEN ie.id END) as Total_M")
            ->selectRaw("COUNT(DISTINCT CASE WHEN {$sexoExpr}='FEMENINO' THEN ie.id END) as Total_F")
            ->selectRaw('COUNT(DISTINCT ie.id) as Total_Gral')

            ->groupBy([
                DB::raw('a.Anio'),
                DB::raw('TRIM(COALESCE(ca.Resolucion, \'SIN RESOLUCION\'))'),
                DB::raw('TRIM(COALESCE(p.LvlCurso, \'SIN NIVEL\'))'),
                DB::raw('COALESCE(p.RangoLvlCurso, 0)'),
                DB::raw('TRIM(COALESCE(m.Paralelo, \'\'))'),
                DB::raw('TRIM(COALESCE(m.Turno, \'\'))'),
            ])
            ->when($modo === 'NORMAL', function ($qq) {
                $qq->groupBy([
                    DB::raw('COALESCE(p.Rango, 0)'),
                    DB::raw('TRIM(COALESCE(p.NombreMateria, \'SIN MATERIA\'))'),
                ]);
            })
            // Orden correcto: curso+paralelo, luego materias (para que no se intercala)
            ->orderBy('Resolucion')
            ->orderBy('RangoLvlCurso')
            ->orderBy('LvlCurso')
            ->orderBy('Paralelo')
            ->orderBy('Turno')
            ->when($modo === 'NORMAL', function ($qq) {
                $qq->orderBy('Rango')
                    ->orderBy('NombreMateria');
            });

        return response()->json(['data' => $q->get()]);
    }

    public function estudiantesAsignados(Request $request)
    {
        $validated = $request->validate([
            'anio_id' => ['required', 'integer', 'min:1'],
            'instituciones_id' => ['nullable', 'integer', 'min:1'],

            'modo' => ['nullable', 'string'],

            'resolucion' => ['required', 'string'],
            'lvl_curso' => ['required', 'string'],
            'paralelo' => ['required', 'string'],
            'turno' => ['required', 'string'],

            // NORMAL
            'nombre_materia' => ['nullable', 'string'],
            'rango' => ['nullable', 'integer'],

            // SIMPLE_DIFERENCIADO
            'materias' => ['nullable', 'string'],

            // Filtros por columna
            'categoria' => ['nullable', 'string'],
            'sexo' => ['nullable', 'string'],

            'limit' => ['nullable', 'integer', 'min:1', 'max:2000'],
        ]);

        $user = $request->user();
        $isSuperAdmin = empty($user?->instituciones_id);

        $anioId = (int) $validated['anio_id'];
        $institucionId = $isSuperAdmin
            ? (int) ($validated['instituciones_id'] ?? 0)
            : (int) ($user->instituciones_id ?? 0);

        if ($isSuperAdmin && $institucionId <= 0) {
            return response()->json(['message' => 'instituciones_id es requerido para superadmin'], 422);
        }

        $modoRaw = strtoupper(trim((string) ($validated['modo'] ?? $request->query('modo', 'NORMAL'))));
        $modo = in_array($modoRaw, ['NORMAL', 'SIMPLIFICADO', 'SIMPLE_DIFERENCIADO'], true) ? $modoRaw : 'NORMAL';

        $resolucion = trim((string) $validated['resolucion']);
        $lvlCurso = trim((string) $validated['lvl_curso']);
        $paralelo = trim((string) $validated['paralelo']);
        $turno = trim((string) $validated['turno']);

        $nombreMateria = trim((string) ($validated['nombre_materia'] ?? ''));
        $rango = isset($validated['rango']) ? (int) $validated['rango'] : null;
        $materias = trim((string) ($validated['materias'] ?? ''));

        $categoria = strtoupper(trim((string) ($validated['categoria'] ?? '')));
        $sexo = strtoupper(trim((string) ($validated['sexo'] ?? '')));
        $limit = (int) ($validated['limit'] ?? 500);

        $categoria = in_array($categoria, ['NUEVO', 'ANTIGUO'], true) ? $categoria : '';
        $sexo = in_array($sexo, ['MASCULINO', 'FEMENINO'], true) ? $sexo : '';

        $nombreExpr = "TRIM(CONCAT_WS(' ', COALESCE(e.Ap_Paterno,''), COALESCE(e.Ap_Materno,''), COALESCE(e.Nombre,'')))";
        $edadExpr = "COALESCE(e.Edad, CASE WHEN e.FechaNac IS NULL OR e.FechaNac='' THEN NULL ELSE TIMESTAMPDIFF(YEAR, e.FechaNac, CURDATE()) END)";
        $sexoExpr = "UPPER(TRIM(COALESCE(e.Sexo, '')))";
        $catExpr = "UPPER(TRIM(COALESCE(ie.Categoria, '')))";

        if ($modo === 'SIMPLE_DIFERENCIADO') {
            if ($materias === '') {
                return response()->json(['message' => 'materias es requerido para modo SIMPLE_DIFERENCIADO'], 422);
            }

            $sub = DB::table('calificaciones as c')
                ->join('materias as m', 'c.materias_id', '=', 'm.id')
                ->join('plandeestudios as p', 'm.plandeestudios_id', '=', 'p.id')
                ->join('carreras as ca', 'p.carreras_id', '=', 'ca.id')
                ->join('infoestudiantesifas as ie', 'ie.id', '=', 'c.infoestudiantesifas_id')
                ->join('estudiantesifas as e', 'ie.estudiantesifas_id', '=', 'e.id')
                ->where('p.anio_id', $anioId);

            if ($institucionId > 0) {
                $sub->where('ca.instituciones_id', $institucionId)
                    ->where('ie.instituciones_id', $institucionId);
            }

            $sub->whereRaw('TRIM(COALESCE(ca.Resolucion, \'\')) = ?', [$resolucion])
                ->whereRaw('TRIM(COALESCE(p.LvlCurso, \'\')) = ?', [$lvlCurso])
                ->whereRaw('TRIM(COALESCE(m.Paralelo, \'\')) = ?', [$paralelo])
                ->whereRaw('TRIM(COALESCE(m.Turno, \'\')) = ?', [$turno]);

            if ($categoria !== '') {
                $sub->whereRaw("{$catExpr} = ?", [$categoria]);
            }
            if ($sexo !== '') {
                $sub->whereRaw("{$sexoExpr} = ?", [$sexo]);
            }

            // Nota: con ONLY_FULL_GROUP_BY activo, es más robusto agrupar solo por estudiante
            // y usar agregaciones determinísticas (MAX) para los campos que son constantes por ie.id.
            $sub->selectRaw('ie.id as InfoId')
                ->selectRaw("MAX({$nombreExpr}) as Nombre")
                ->selectRaw("MAX(TRIM(COALESCE(e.CI, ''))) as CI")
                ->selectRaw("MAX({$edadExpr}) as Edad")
                ->selectRaw("MAX(TRIM(COALESCE(e.Sexo, ''))) as Sexo")
                ->selectRaw("MAX({$catExpr}) as Categoria")
                ->selectRaw("GROUP_CONCAT(DISTINCT TRIM(COALESCE(p.NombreMateria, '')) ORDER BY COALESCE(p.Rango, 0) SEPARATOR ' / ') as Materias")
                ->groupBy([
                    DB::raw('ie.id'),
                ]);

            $q = DB::query()
                ->fromSub($sub, 's')
                ->where('Materias', $materias)
                ->select(['InfoId', 'Nombre', 'CI', 'Edad', 'Sexo'])
                ->orderBy('Nombre')
                ->limit($limit);

            return response()->json(['data' => $q->get()]);
        }

        // NORMAL o SIMPLIFICADO: listamos estudiantes asignados (calificaciones) con DISTINCT
        $q = DB::table('calificaciones as c')
            ->join('materias as m', 'c.materias_id', '=', 'm.id')
            ->join('plandeestudios as p', 'm.plandeestudios_id', '=', 'p.id')
            ->join('carreras as ca', 'p.carreras_id', '=', 'ca.id')
            ->join('infoestudiantesifas as ie', 'ie.id', '=', 'c.infoestudiantesifas_id')
            ->join('estudiantesifas as e', 'ie.estudiantesifas_id', '=', 'e.id')
            ->where('p.anio_id', $anioId);

        if ($institucionId > 0) {
            $q->where('ca.instituciones_id', $institucionId)
              ->where('ie.instituciones_id', $institucionId);
        }

        $q->whereRaw('TRIM(COALESCE(ca.Resolucion, \'\')) = ?', [$resolucion])
            ->whereRaw('TRIM(COALESCE(p.LvlCurso, \'\')) = ?', [$lvlCurso])
            ->whereRaw('TRIM(COALESCE(m.Paralelo, \'\')) = ?', [$paralelo])
            ->whereRaw('TRIM(COALESCE(m.Turno, \'\')) = ?', [$turno]);

        if ($modo === 'NORMAL') {
            if ($nombreMateria !== '') {
                $q->whereRaw('TRIM(COALESCE(p.NombreMateria, \'\')) = ?', [$nombreMateria]);
            }
            if ($rango !== null) {
                $q->where('p.Rango', $rango);
            }
        }

        if ($categoria !== '') {
            $q->whereRaw("{$catExpr} = ?", [$categoria]);
        }
        if ($sexo !== '') {
            $q->whereRaw("{$sexoExpr} = ?", [$sexo]);
        }

        $q->distinct()
            ->selectRaw('ie.id as InfoId')
            ->selectRaw("{$nombreExpr} as Nombre")
            ->selectRaw('TRIM(COALESCE(e.CI, \'\')) as CI')
            ->selectRaw("{$edadExpr} as Edad")
            ->selectRaw('TRIM(COALESCE(e.Sexo, \'\')) as Sexo')
            ->orderBy('Nombre')
            ->limit($limit);

        return response()->json(['data' => $q->get()]);
    }
}
