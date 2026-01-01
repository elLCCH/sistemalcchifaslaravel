<?php

namespace App\Http\Controllers;

use App\Models\PermisoAsistencia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PermisoAsistenciaController extends Controller
{
    public function index(Request $request)
    {
        $q = PermisoAsistencia::query();

        $institucionId = $request->query('instituciones_id') ?? ($request->user()->instituciones_id ?? null);
        if ($institucionId) {
            $q->where('instituciones_id', $institucionId);
        }

        if ($request->query('infoestudiantesifas_id')) {
            $q->where('infoestudiantesifas_id', (int) $request->query('infoestudiantesifas_id'));
        }

        return response()->json([
            'ok' => true,
            'permisos' => $q->orderByDesc('id')->limit(500)->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'instituciones_id' => 'required|integer',
            'infoestudiantesifas_id' => 'required|integer',
            'fecha_inicio' => 'required|date',
            'fecha_fin' => 'required|date',
            'aulas_virtuales_id' => 'nullable|integer',
            'motivo' => 'nullable|string|max:255',
            'registrado_por' => 'nullable|string|max:80',
        ]);

        if ($validator->fails()) {
            return response()->json(['ok' => false, 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        $permiso = PermisoAsistencia::create([
            'instituciones_id' => $data['instituciones_id'],
            'infoestudiantesifas_id' => $data['infoestudiantesifas_id'],
            'fecha_inicio' => $data['fecha_inicio'],
            'fecha_fin' => $data['fecha_fin'],
            'aulas_virtuales_id' => $data['aulas_virtuales_id'] ?? null,
            'motivo' => $data['motivo'] ?? null,
            'registrado_por' => $data['registrado_por'] ?? null,
            'estado' => 'ACTIVO',
            'visibilidad' => 'VISIBLE',
        ]);

        return response()->json(['ok' => true, 'permiso' => $permiso]);
    }

    public function destroy(Request $request, $id)
    {
        $permiso = PermisoAsistencia::find($id);

        if (!$permiso) {
            return response()->json(['ok' => false, 'message' => 'Permiso no encontrado'], 404);
        }

        $permiso->delete();

        return response()->json(['ok' => true]);
    }
}
