<?php

namespace App\Http\Controllers;

use App\Http\Middleware\UpdateTokenExpiration;
use App\Models\Pagostalleres;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;

class PagostalleresController extends BaseController
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }

    private function isDocente($user): bool
    {
        $cargo = strtoupper(trim((string) ($user?->Cargo ?? '')));
        return $cargo === 'DOCENTE';
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $isSuperAdmin = empty($user?->instituciones_id);

        $talleristaId = $request->query('talleristas_id');
        $docenteId = $request->query('planteldocentes_id');
        $soloActivos = $request->query('activos');

        $query = Pagostalleres::query()
            ->join('talleristas', 'pagostalleres.talleristas_id', '=', 'talleristas.id')
            ->join('planteldocentes', 'pagostalleres.planteldocentes_id', '=', 'planteldocentes.id')
            ->leftJoin('instituciones', 'pagostalleres.instituciones_id', '=', 'instituciones.id')
            ->addSelect(
                'pagostalleres.*',
                'talleristas.Ap_Paterno',
                'talleristas.Ap_Materno',
                'talleristas.Nombre',
                'talleristas.Carnet',
                'talleristas.Foto as FotoTallerista',
                'planteldocentes.Nombres as DocenteNombres',
                'planteldocentes.Apellidos as DocenteApellidos',
                $isSuperAdmin ? 'instituciones.Nombre as NombreInstitucion' : DB::raw('NULL as NombreInstitucion')
            )
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('pagostalleres.instituciones_id', (int) $user->instituciones_id);
            })
            ->when($talleristaId !== null && $talleristaId !== '' && (int) $talleristaId > 0, function ($q) use ($talleristaId) {
                $q->where('pagostalleres.talleristas_id', (int) $talleristaId);
            });

        // Si es docente, por defecto solo ve lo suyo.
        if ($this->isDocente($user)) {
            $query->where('pagostalleres.planteldocentes_id', (int) $user->id);
        } elseif ($docenteId !== null && $docenteId !== '' && (int) $docenteId > 0) {
            $query->where('pagostalleres.planteldocentes_id', (int) $docenteId);
        }

        if ($soloActivos === '1' || $soloActivos === 1 || $soloActivos === true || $soloActivos === 'true') {
            $hoy = date('Y-m-d');
            $query->whereDate('pagostalleres.FechaHasta', '>=', $hoy);
        }

        $items = $query
            ->orderByDesc('pagostalleres.FechaPago')
            ->orderByDesc('pagostalleres.id')
            ->get();

        return response()->json(['data' => $items]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create() {}

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        $data = $request->all();

        if (!empty($user?->instituciones_id)) {
            $data['instituciones_id'] = (int) $user->instituciones_id;
        }

        if ($this->isDocente($user)) {
            // Un docente registra pagos solo para su propio id.
            $data['planteldocentes_id'] = (int) $user->id;
        }

        if (!isset($data['MontoPagado']) || $data['MontoPagado'] === '') {
            $data['MontoPagado'] = null;
        }

        $fechaPago = trim((string) ($data['FechaPago'] ?? ''));
        $fechaHasta = trim((string) ($data['FechaHasta'] ?? ''));
        if ($fechaPago !== '' && $fechaHasta !== '' && $fechaHasta < $fechaPago) {
            return response()->json(['error' => 'FechaHasta no puede ser menor que FechaPago'], 422);
        }

        $row = Pagostalleres::create($data);
        return response()->json(['data' => $row]);
    }

    /**
     * Display the specified resource.
     */
    public function show($id, Request $request)
    {
        $user = $request->user();

        $row = Pagostalleres::query()
            ->where('id', '=', (int) $id)
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('instituciones_id', (int) $user->instituciones_id);
            })
            ->when($this->isDocente($user), function ($q) use ($user) {
                $q->where('planteldocentes_id', (int) $user->id);
            })
            ->firstOrFail();

        return response()->json(['data' => $row]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Pagostalleres $pagostalleres) {}

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request)
    {
        $user = $request->user();

        $row = Pagostalleres::query()
            ->where('id', '=', (int) $request->id)
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('instituciones_id', (int) $user->instituciones_id);
            })
            ->when($this->isDocente($user), function ($q) use ($user) {
                $q->where('planteldocentes_id', (int) $user->id);
            })
            ->firstOrFail();

        $data = $request->all();
        if (!empty($user?->instituciones_id)) {
            $data['instituciones_id'] = (int) $user->instituciones_id;
        }
        if ($this->isDocente($user)) {
            $data['planteldocentes_id'] = (int) $user->id;
        }

        if (array_key_exists('MontoPagado', $data) && $data['MontoPagado'] === '') {
            $data['MontoPagado'] = null;
        }

        $fechaPago = trim((string) ($data['FechaPago'] ?? $row->FechaPago ?? ''));
        $fechaHasta = trim((string) ($data['FechaHasta'] ?? $row->FechaHasta ?? ''));
        if ($fechaPago !== '' && $fechaHasta !== '' && $fechaHasta < $fechaPago) {
            return response()->json(['error' => 'FechaHasta no puede ser menor que FechaPago'], 422);
        }

        $row->update($data);
        return response()->json(['data' => $row]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id, Request $request)
    {
        $user = $request->user();

        $row = Pagostalleres::query()
            ->where('id', '=', (int) $id)
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('instituciones_id', (int) $user->instituciones_id);
            })
            ->when($this->isDocente($user), function ($q) use ($user) {
                $q->where('planteldocentes_id', (int) $user->id);
            })
            ->firstOrFail();

        $row->delete();
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }

    public function byTallerista(int $talleristaId, Request $request)
    {
        $request->merge(['talleristas_id' => $talleristaId]);
        return $this->index($request);
    }
}
