<?php

namespace App\Http\Controllers;

use App\Http\Middleware\UpdateTokenExpiration;
use App\Models\Talleristas;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;

class TalleristasController extends BaseController
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $isSuperAdmin = empty($user?->instituciones_id);

        $query = Talleristas::query();

        if (!empty($user?->instituciones_id)) {
            $query->where('talleristas.instituciones_id', (int) $user->instituciones_id);
        }

        if ($isSuperAdmin) {
            $query
                ->leftJoin('instituciones', 'talleristas.instituciones_id', '=', 'instituciones.id')
                ->addSelect('talleristas.*', 'instituciones.Nombre as NombreInstitucion');
        } else {
            $query->addSelect('talleristas.*', DB::raw('NULL as NombreInstitucion'));
        }

        $items = $query->orderByDesc('talleristas.id')->get();
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

        $row = Talleristas::create($data);
        if (!empty($user?->instituciones_id)) {
            $row->NombreInstitucion = null;
        }
        return response()->json(['data' => $row]);
    }

    /**
     * Display the specified resource.
     */
    public function show($id, Request $request)
    {
        $user = $request->user();
        $row = Talleristas::query()
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

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Talleristas $talleristas) {}

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request)
    {
        $user = $request->user();

        $row = Talleristas::query()
            ->where('id', '=', (int) $request->id)
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('instituciones_id', (int) $user->instituciones_id);
            })
            ->firstOrFail();

        $data = $request->all();
        if (!empty($user?->instituciones_id)) {
            // Evitar que cambien la institución desde frontend
            $data['instituciones_id'] = (int) $user->instituciones_id;
        }

        $row->update($data);
        if (!empty($user?->instituciones_id)) {
            $row->NombreInstitucion = null;
        }
        return response()->json(['data' => $row]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id, Request $request)
    {
        $user = $request->user();

        $row = Talleristas::query()
            ->where('id', '=', (int) $id)
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('instituciones_id', (int) $user->instituciones_id);
            })
            ->firstOrFail();

        $row->delete();
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
}
