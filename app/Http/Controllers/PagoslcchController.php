<?php

namespace App\Http\Controllers;

use App\Http\Middleware\UpdateTokenExpiration;
use App\Models\Pagoslcch;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class PagoslcchController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }

    public function index(Request $request)
    {
        $user = $request->user();

        $infoId = $request->query('infoestudiantesifas_id');
        $gestion = $request->query('gestion');
        $mes = $request->query('mes');

        $query = Pagoslcch::query()
            ->join('infoestudiantesifas', 'pagoslcch.infoestudiantesifas_id', '=', 'infoestudiantesifas.id')
            ->join('estudiantesifas', 'infoestudiantesifas.estudiantesifas_id', '=', 'estudiantesifas.id')
            ->leftJoin('instituciones', 'infoestudiantesifas.instituciones_id', '=', 'instituciones.id')
            ->addSelect(
                'pagoslcch.*',
                'instituciones.Nombre as NombreInstitucion',
                'estudiantesifas.Ap_Paterno',
                'estudiantesifas.Ap_Materno',
                'estudiantesifas.Nombre'
            )
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('infoestudiantesifas.instituciones_id', $user->instituciones_id);
            })
            ->when($infoId !== null && $infoId !== '' && (int) $infoId > 0, function ($q) use ($infoId) {
                $q->where('pagoslcch.infoestudiantesifas_id', (int) $infoId);
            })
            ->when($gestion !== null && $gestion !== '', function ($q) use ($gestion) {
                $q->where('pagoslcch.gestion', (string) $gestion);
            })
            ->when($mes !== null && $mes !== '' && (int) $mes > 0, function ($q) use ($mes) {
                $q->where('pagoslcch.mes', (int) $mes);
            })
            ->orderByDesc('pagoslcch.gestion')
            ->orderBy('pagoslcch.mes', 'asc')
            ->orderByDesc('pagoslcch.id');

        $items = $query->get();

        return response()->json(['data' => $items]);
    }

    public function gestionesAsignaciones(Request $request)
    {
        // Lista de gestiones basada en tabla anios (campo Anio) + categoría SIN ASIGNAR
        // Nota: anios no está amarrado a institución en el esquema.
        $rows = DB::table('anios')
            ->select('Anio')
            ->whereNotNull('Anio')
            ->orderByDesc('Anio')
            ->get();

        $anios = $rows
            ->pluck('Anio')
            ->map(fn ($x) => trim((string) $x))
            ->filter(fn ($x) => $x !== '')
            ->unique()
            ->values()
            ->all();

        array_unshift($anios, 'SIN ASIGNAR');

        return response()->json(['data' => $anios]);
    }

    public function byInfo(int $infoId, Request $request)
    {
        $user = $request->user();

        $query = Pagoslcch::query()
            ->join('infoestudiantesifas', 'pagoslcch.infoestudiantesifas_id', '=', 'infoestudiantesifas.id')
            ->join('estudiantesifas', 'infoestudiantesifas.estudiantesifas_id', '=', 'estudiantesifas.id')
            ->leftJoin('instituciones', 'infoestudiantesifas.instituciones_id', '=', 'instituciones.id')
            ->addSelect(
                'pagoslcch.*',
                'instituciones.Nombre as NombreInstitucion',
                'estudiantesifas.Ap_Paterno',
                'estudiantesifas.Ap_Materno',
                'estudiantesifas.Nombre'
            )
            ->where('pagoslcch.infoestudiantesifas_id', $infoId)
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('infoestudiantesifas.instituciones_id', $user->instituciones_id);
            })
            ->orderByDesc('pagoslcch.gestion')
            ->orderBy('pagoslcch.mes', 'asc')
            ->orderByDesc('pagoslcch.id');

        return response()->json(['data' => $query->get()]);
    }

    public function deudaByInfo(int $infoId, Request $request)
    {
        $user = $request->user();
        $gestion = (string) ($request->query('gestion') ?? '');
        $gestion = trim($gestion);
        if ($gestion === '') {
            $gestion = (string) date('Y');
        }

        $info = DB::table('infoestudiantesifas')
            ->where('id', $infoId)
            ->first();

        if (!$info) {
            return response()->json(['error' => 'Inscripción no encontrada'], 404);
        }

        if (!empty($user?->instituciones_id) && (int) $info->instituciones_id !== (int) $user->instituciones_id) {
            return response()->json(['error' => 'Inscripción fuera de su institución'], 403);
        }

        // Mes máximo pagado por "compañeros" de la MISMA institución (excluyendo al estudiante actual) para esa gestión.
        // Solo cuenta pagos marcados como PAGADO.
        $maxMesCompaneros = DB::table('pagoslcch')
            ->join('infoestudiantesifas', 'pagoslcch.infoestudiantesifas_id', '=', 'infoestudiantesifas.id')
            ->where('pagoslcch.gestion', $gestion)
            ->where('infoestudiantesifas.id', '!=', $infoId)
            ->where('infoestudiantesifas.instituciones_id', (int) $info->instituciones_id)
            ->where('pagoslcch.estadopago', '=', 'PAGADO')
            ->max('pagoslcch.mes');

        $maxMesCompaneros = (int) ($maxMesCompaneros ?? 0);

        // Meses pagados por el estudiante actual en esa gestión.
        $mesesPagados = DB::table('pagoslcch')
            ->where('infoestudiantesifas_id', $infoId)
            ->where('gestion', $gestion)
            ->where('estadopago', '=', 'PAGADO')
            ->select('mes')
            ->distinct()
            ->pluck('mes')
            ->map(fn ($x) => (int) $x)
            ->values()
            ->all();

        $mesesPagadosSet = array_fill_keys($mesesPagados, true);

        $mesesDeuda = [];
        if ($maxMesCompaneros > 0) {
            for ($m = 1; $m <= $maxMesCompaneros; $m++) {
                if (!isset($mesesPagadosSet[$m])) {
                    $mesesDeuda[] = $m;
                }
            }
        }

        return response()->json([
            'data' => [
                'infoestudiantesifas_id' => $infoId,
                'gestion' => $gestion,
                'max_mes_companeros' => $maxMesCompaneros,
                'meses_pagados' => $mesesPagados,
                'meses_deuda' => $mesesDeuda,
            ],
        ]);
    }

    public function deudores(Request $request)
    {
        $user = $request->user();
        if (empty($user?->instituciones_id)) {
            return response()->json(['error' => 'Usuario sin institución'], 403);
        }

        $institucionId = (int) $user->instituciones_id;

        // Filtro de gestión (por asignaciones) y también para pagos.
        // Si se pide SIN ASIGNAR, se filtra por asignaciones SIN ASIGNAR y se usa gestion_pago (o año actual) para comparar pagos.
        $gestion = trim((string) $request->query('gestion', ''));
        if ($gestion === '') {
            $gestion = (string) date('Y');
        }

        $gestionPago = trim((string) $request->query('gestion_pago', ''));
        if ($gestionPago === '') {
            $gestionPago = $gestion === 'SIN ASIGNAR' ? (string) date('Y') : $gestion;
        }

        $search = trim((string) $request->query('search', ''));
        $perPage = (int) $request->query('per_page', 10);
        if ($perPage < 1) {
            $perPage = 10;
        }
        if ($perPage > 50) {
            $perPage = 50;
        }

        // Subquery para obtener gestión por asignaciones (calificaciones -> materias -> plandeestudios -> anios)
        $anioSubquery = "COALESCE((
            SELECT MAX(a.Anio)
            FROM calificaciones c
            INNER JOIN materias m ON m.id = c.materias_id
            INNER JOIN plandeestudios p ON p.id = m.plandeestudios_id
            INNER JOIN anios a ON a.id = p.anio_id
            WHERE c.infoestudiantesifas_id = infoestudiantesifas.id
        ), 'SIN ASIGNAR')";

        // Meses "referencia" pagados por algún estudiante de la institución (para esa gestión de pago)
        $expectedMonths = DB::table('pagoslcch')
            ->join('infoestudiantesifas', 'pagoslcch.infoestudiantesifas_id', '=', 'infoestudiantesifas.id')
            ->where('infoestudiantesifas.instituciones_id', $institucionId)
            ->where('pagoslcch.gestion', $gestionPago)
            ->where('pagoslcch.estadopago', '=', 'PAGADO')
            ->select('pagoslcch.mes')
            ->distinct()
            ->orderBy('pagoslcch.mes', 'asc')
            ->pluck('pagoslcch.mes')
            ->map(fn ($x) => (int) $x)
            ->filter(fn ($x) => $x >= 1 && $x <= 12)
            ->values()
            ->all();

        $expectedCount = count($expectedMonths);
        if ($expectedCount === 0) {
            // Si nadie pagó aún para esa gestión, no hay referencia para detectar deuda.
            return response()->json([
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'per_page' => $perPage,
                    'total' => 0,
                    'last_page' => 1,
                    'from' => null,
                    'to' => null,
                ],
                'expected_months' => [],
                'gestion' => $gestion,
                'gestion_pago' => $gestionPago,
            ]);
        }

        $idsQuery = DB::table('infoestudiantesifas')
            ->leftJoin('instituciones', 'infoestudiantesifas.instituciones_id', '=', 'instituciones.id')
            ->leftJoin('estudiantesifas', 'infoestudiantesifas.estudiantesifas_id', '=', 'estudiantesifas.id')
            ->leftJoin('pagoslcch as p', function ($join) use ($gestionPago, $expectedMonths) {
                $join->on('p.infoestudiantesifas_id', '=', 'infoestudiantesifas.id')
                    ->where('p.gestion', '=', $gestionPago)
                    ->where('p.estadopago', '=', 'PAGADO')
                    ->whereIn('p.mes', $expectedMonths);
            })
            ->where('infoestudiantesifas.instituciones_id', $institucionId)
            ->when($gestion !== '', function ($q) use ($gestion, $anioSubquery) {
                // filtro por gestión basada en asignaciones
                $q->whereRaw($anioSubquery . ' = ?', [$gestion]);
            })
            ->when($search !== '', function ($q) use ($search) {
                $like = '%' . $search . '%';
                $q->where(function ($qq) use ($like) {
                    $qq->where('estudiantesifas.Ap_Paterno', 'like', $like)
                        ->orWhere('estudiantesifas.Ap_Materno', 'like', $like)
                        ->orWhere('estudiantesifas.Nombre', 'like', $like)
                        ->orWhere('instituciones.Nombre', 'like', $like)
                        ->orWhere('estudiantesifas.CI', 'like', $like)
                        ->orWhere('estudiantesifas.Matricula', 'like', $like);
                });
            })
            ->groupBy('infoestudiantesifas.id')
            ->select([
                'infoestudiantesifas.id',
                DB::raw('COUNT(DISTINCT p.mes) as PagadosCount'),
            ])
            ->havingRaw('PagadosCount < ?', [$expectedCount])
            ->orderBy('estudiantesifas.Ap_Paterno', 'asc')
            ->orderBy('estudiantesifas.Ap_Materno', 'asc')
            ->orderBy('estudiantesifas.Nombre', 'asc')
            ->orderByDesc('infoestudiantesifas.id');

        $paginator = $idsQuery->paginate($perPage);
        $ids = collect($paginator->items())->pluck('id')->map(fn ($x) => (int) $x)->values()->all();

        if (count($ids) === 0) {
            return response()->json([
                'data' => [],
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                ],
                'expected_months' => $expectedMonths,
                'gestion' => $gestion,
                'gestion_pago' => $gestionPago,
            ]);
        }

        $items = DB::table('infoestudiantesifas')
            ->leftJoin('instituciones', 'infoestudiantesifas.instituciones_id', '=', 'instituciones.id')
            ->leftJoin('estudiantesifas', 'infoestudiantesifas.estudiantesifas_id', '=', 'estudiantesifas.id')
            ->select([
                'infoestudiantesifas.*',
                'instituciones.Nombre as NombreInstitucion',
                'estudiantesifas.Ap_Paterno',
                'estudiantesifas.Ap_Materno',
                'estudiantesifas.Nombre',
                'estudiantesifas.CI',
                'estudiantesifas.Matricula',
                DB::raw($anioSubquery . ' as Anio'),
            ])
            ->whereIn('infoestudiantesifas.id', $ids)
            ->orderBy('estudiantesifas.Ap_Paterno', 'asc')
            ->orderBy('estudiantesifas.Ap_Materno', 'asc')
            ->orderBy('estudiantesifas.Nombre', 'asc')
            ->orderByDesc('infoestudiantesifas.id')
            ->get();

        $paidRows = DB::table('pagoslcch')
            ->whereIn('infoestudiantesifas_id', $ids)
            ->where('gestion', $gestionPago)
            ->where('estadopago', '=', 'PAGADO')
            ->whereIn('mes', $expectedMonths)
            ->select('infoestudiantesifas_id', 'mes')
            ->distinct()
            ->get();

        $paidMap = [];
        foreach ($paidRows as $r) {
            $iid = (int) ($r->infoestudiantesifas_id ?? 0);
            $m = (int) ($r->mes ?? 0);
            if ($iid > 0 && $m >= 1 && $m <= 12) {
                if (!isset($paidMap[$iid])) {
                    $paidMap[$iid] = [];
                }
                $paidMap[$iid][$m] = true;
            }
        }

        $out = [];
        foreach ($items as $row) {
            $iid = (int) ($row->id ?? 0);
            $paidSet = $paidMap[$iid] ?? [];
            $deuda = [];
            foreach ($expectedMonths as $m) {
                if (!isset($paidSet[$m])) {
                    $deuda[] = $m;
                }
            }

            if (count($deuda) === 0) {
                continue;
            }

            $rowArr = (array) $row;
            $rowArr['meses_deuda'] = $deuda;
            $rowArr['meses_pagados_ref'] = array_keys($paidSet);
            $out[] = $rowArr;
        }

        return response()->json([
            'data' => $out,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'expected_months' => $expectedMonths,
            'gestion' => $gestion,
            'gestion_pago' => $gestionPago,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'infoestudiantesifas_id' => ['required', 'integer'],
            'mes' => ['required', 'integer', 'min:1', 'max:12'],
            'gestion' => ['required', 'string', 'max:10'],
            'monto' => ['nullable', 'integer'],
            'fechapago' => ['nullable', 'date'],
            'horapago' => ['nullable'],
            'file' => ['nullable', 'string'],
            'observacion' => ['nullable', 'string'],
            'estadopago' => ['nullable', 'string', 'max:50'],
        ]);

        $user = $request->user();
        if (!empty($user?->instituciones_id)) {
            $ok = DB::table('infoestudiantesifas')
                ->where('id', $validated['infoestudiantesifas_id'])
                ->where('instituciones_id', $user->instituciones_id)
                ->exists();
            if (!$ok) {
                return response()->json(['error' => 'Inscripción fuera de su institución'], 403);
            }
        }

        $pago = Pagoslcch::create($validated);
        return response()->json(['data' => $pago]);
    }

    public function show($id)
    {
        $pago = Pagoslcch::where('id', '=', $id)->firstOrFail();
        return response()->json(['data' => $pago]);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'infoestudiantesifas_id' => ['required', 'integer'],
            'mes' => ['required', 'integer', 'min:1', 'max:12'],
            'gestion' => ['required', 'string', 'max:10'],
            'monto' => ['nullable', 'integer'],
            'fechapago' => ['nullable', 'date'],
            'horapago' => ['nullable'],
            'file' => ['nullable', 'string'],
            'observacion' => ['nullable', 'string'],
            'estadopago' => ['nullable', 'string', 'max:50'],
        ]);

        $user = $request->user();
        if (!empty($user?->instituciones_id)) {
            $ok = DB::table('infoestudiantesifas')
                ->where('id', $validated['infoestudiantesifas_id'])
                ->where('instituciones_id', $user->instituciones_id)
                ->exists();
            if (!$ok) {
                return response()->json(['error' => 'Inscripción fuera de su institución'], 403);
            }
        }

        Pagoslcch::where('id', '=', $id)->update($validated);
        return response()->json(['data' => $validated]);
    }

    public function destroy($id)
    {
        Pagoslcch::destroy($id);
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
}
