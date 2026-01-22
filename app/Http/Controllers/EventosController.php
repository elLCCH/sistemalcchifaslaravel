<?php

namespace App\Http\Controllers;

use App\Http\Middleware\UpdateTokenExpiration;
use App\Models\Eventos;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;

class EventosController extends BaseController
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
        $anio = $request->query('anio');
        $search = trim((string) $request->query('search', ''));

        $query = Eventos::query();

        if (!empty($user?->instituciones_id)) {
            $query->where('eventos.instituciones_id', (int) $user->instituciones_id);
        }

        if ($isSuperAdmin) {
            $query
                ->leftJoin('instituciones', 'eventos.instituciones_id', '=', 'instituciones.id')
                ->addSelect('eventos.*', 'instituciones.Nombre as NombreInstitucion');
        } else {
            $query->addSelect('eventos.*', DB::raw('NULL as NombreInstitucion'));
        }

        if ($activos === '1' || $activos === 1 || $activos === true || $activos === 'true') {
            $query->where('eventos.Activo', 1);
        }

        if ($anio !== null && $anio !== '' && (int) $anio > 0) {
            $query->where('eventos.Anio', (int) $anio);
        }

        if ($search !== '') {
            $q = '%' . $search . '%';
            $query->where(function ($sub) use ($q) {
                $sub
                    ->where('eventos.NombreEvento', 'like', $q)
                    ->orWhere('eventos.ModoInscripcion', 'like', $q)
                    ->orWhere('eventos.Lugar', 'like', $q);
            });
        }

        $items = $query->orderByDesc('eventos.Anio')->orderByDesc('eventos.id')->get();
        return response()->json(['data' => $items]);
    }

    public function create() {}

    public function store(Request $request)
    {
        $user = $request->user();
        $data = $request->all();

        if (!empty($user?->instituciones_id)) {
            $data['instituciones_id'] = (int) $user->instituciones_id;
        }

        // Normalizaciones
        if (!isset($data['PublicoWeb']) || $data['PublicoWeb'] === '') $data['PublicoWeb'] = 0;
        if (!isset($data['Activo']) || $data['Activo'] === '') $data['Activo'] = 1;
        if (!isset($data['TienePago']) || $data['TienePago'] === '') $data['TienePago'] = 0;
        if (($data['TienePago'] ?? 0) == 0) {
            $data['Monto'] = null;
        }

        if (array_key_exists('diseniocertificadopdfs_id', $data) && ($data['diseniocertificadopdfs_id'] === '' || $data['diseniocertificadopdfs_id'] === 0 || $data['diseniocertificadopdfs_id'] === '0')) {
            $data['diseniocertificadopdfs_id'] = null;
        }
        if (!isset($data['CertificadoConfig']) || $data['CertificadoConfig'] === null) {
            $data['CertificadoConfig'] = '';
        }

        $row = Eventos::create($data);
        if (!empty($user?->instituciones_id)) {
            $row->NombreInstitucion = null;
        }
        return response()->json(['data' => $row]);
    }

    public function show($id, Request $request)
    {
        $user = $request->user();

        $row = Eventos::query()
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

    public function edit(Eventos $eventos) {}

    public function update(Request $request)
    {
        $user = $request->user();

        $row = Eventos::query()
            ->where('id', '=', (int) $request->id)
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('instituciones_id', (int) $user->instituciones_id);
            })
            ->firstOrFail();

        $data = $request->all();

        if (!empty($user?->instituciones_id)) {
            $data['instituciones_id'] = (int) $user->instituciones_id;
        }

        if (array_key_exists('TienePago', $data) && ($data['TienePago'] == 0 || $data['TienePago'] === '0' || $data['TienePago'] === false)) {
            $data['Monto'] = null;
        }

        if (array_key_exists('diseniocertificadopdfs_id', $data) && ($data['diseniocertificadopdfs_id'] === '' || $data['diseniocertificadopdfs_id'] === 0 || $data['diseniocertificadopdfs_id'] === '0')) {
            $data['diseniocertificadopdfs_id'] = null;
        }
        if (array_key_exists('CertificadoConfig', $data) && $data['CertificadoConfig'] === null) {
            $data['CertificadoConfig'] = '';
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

        $row = Eventos::query()
            ->where('id', '=', (int) $id)
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('instituciones_id', (int) $user->instituciones_id);
            })
            ->firstOrFail();

        $row->delete();
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
}
