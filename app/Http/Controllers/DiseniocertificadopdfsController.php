<?php

namespace App\Http\Controllers;

use App\Http\Middleware\UpdateTokenExpiration;
use App\Models\Diseniocertificadopdfs;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;

class DiseniocertificadopdfsController extends BaseController
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $isSuperAdmin = empty($user?->instituciones_id);

        $activos = $request->query('activos');
        $search = trim((string) $request->query('search', ''));

        $query = Diseniocertificadopdfs::query();

        if (!empty($user?->instituciones_id)) {
            $query->where('diseniocertificadopdfs.instituciones_id', (int) $user->instituciones_id);
        }

        if ($isSuperAdmin) {
            $query
                ->leftJoin('instituciones', 'diseniocertificadopdfs.instituciones_id', '=', 'instituciones.id')
                ->addSelect('diseniocertificadopdfs.*', 'instituciones.Nombre as NombreInstitucion');
        } else {
            $query->addSelect('diseniocertificadopdfs.*', DB::raw('NULL as NombreInstitucion'));
        }

        if ($activos === '1' || $activos === 1 || $activos === true || $activos === 'true') {
            $query->where('diseniocertificadopdfs.Activo', 1);
        }

        if ($search !== '') {
            $q = '%' . $search . '%';
            $query->where(function ($sub) use ($q) {
                $sub
                    ->where('diseniocertificadopdfs.Nombre', 'like', $q)
                    ->orWhere('diseniocertificadopdfs.Observacion', 'like', $q);
            });
        }

        $items = $query->orderByDesc('diseniocertificadopdfs.id')->get();
        return response()->json(['data' => $items]);
    }

    public function show($id, Request $request)
    {
        $user = $request->user();

        $row = Diseniocertificadopdfs::query()
            ->where('id', '=', (int) $id)
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('instituciones_id', (int) $user->instituciones_id);
            })
            ->firstOrFail();

        if (!empty($user?->instituciones_id)) {
            $row->NombreInstitucion = null;
        }

        return response()->json(['data' => $row]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $data = $request->all();

        if (!empty($user?->instituciones_id)) {
            $data['instituciones_id'] = (int) $user->instituciones_id;
        }

        if (!isset($data['Activo']) || $data['Activo'] === '') $data['Activo'] = 1;
        if (!isset($data['Parametros']) || $data['Parametros'] === null) $data['Parametros'] = '';

        $row = Diseniocertificadopdfs::create($data);
        if (!empty($user?->instituciones_id)) {
            $row->NombreInstitucion = null;
        }

        return response()->json(['data' => $row]);
    }

    public function update(Request $request)
    {
        $user = $request->user();

        $row = Diseniocertificadopdfs::query()
            ->where('id', '=', (int) $request->id)
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('instituciones_id', (int) $user->instituciones_id);
            })
            ->firstOrFail();

        $data = $request->all();

        if (!empty($user?->instituciones_id)) {
            $data['instituciones_id'] = (int) $user->instituciones_id;
        }

        $row->update($data);
        if (!empty($user?->instituciones_id)) {
            $row->NombreInstitucion = null;
        }

        return response()->json(['data' => $row]);
    }

    public function destroy($id, Request $request)
    {
        $user = $request->user();

        $row = Diseniocertificadopdfs::query()
            ->where('id', '=', (int) $id)
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('instituciones_id', (int) $user->instituciones_id);
            })
            ->firstOrFail();

        $row->delete();
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
}
