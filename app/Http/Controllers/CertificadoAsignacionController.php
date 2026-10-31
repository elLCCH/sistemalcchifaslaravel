<?php

namespace App\Http\Controllers;

use App\Models\CertificadoAsignacion;
use App\Models\Diseniocertificadopdfs;
use App\Models\Eventos;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;

class CertificadoAsignacionController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }

    public function index(Request $request)
    {
        $user = $request->user();

        $query = CertificadoAsignacion::with(['evento:id,NombreEvento,Anio', 'diseno:id,Nombre,ArchivoPdf,ArchivoPdfFirmas'])
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->whereHas('evento', fn ($e) => $e->where('instituciones_id', (int) $user->instituciones_id));
            });

        if ($request->filled('evento_id')) {
            $query->where('evento_id', (int) $request->input('evento_id'));
        }
        if ($request->filled('diseniocertificadopdfs_id')) {
            $query->where('diseniocertificadopdfs_id', (int) $request->input('diseniocertificadopdfs_id'));
        }

        return response()->json(['data' => $query->orderBy('evento_id')->orderBy('especialidad')->orderBy('categoria')->get()]);
    }

    // especialidad/categoria vacías significan "todas".
    public function store(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'evento_id' => 'required|exists:eventos,id',
            'especialidad' => 'nullable|string|max:100',
            'categoria' => 'nullable|string|max:100',
            'diseniocertificadopdfs_id' => 'required|exists:diseniocertificadopdfs,id',
        ]);
        $validated['especialidad'] = trim((string) ($validated['especialidad'] ?? ''));
        $validated['categoria'] = trim((string) ($validated['categoria'] ?? ''));

        if (!empty($user?->instituciones_id)) {
            $inst = (int) $user->instituciones_id;
            $okEvento = Eventos::where('id', $validated['evento_id'])->where('instituciones_id', $inst)->exists();
            $okDiseno = Diseniocertificadopdfs::where('id', $validated['diseniocertificadopdfs_id'])->where('instituciones_id', $inst)->exists();
            if (!$okEvento || !$okDiseno) {
                return response()->json(['data' => 'Evento o diseño no válido'], 422);
            }
        }

        $row = CertificadoAsignacion::updateOrCreate(
            [
                'evento_id' => $validated['evento_id'],
                'especialidad' => $validated['especialidad'],
                'categoria' => $validated['categoria'],
                'diseniocertificadopdfs_id' => $validated['diseniocertificadopdfs_id'],
            ],
            [],
        );
        $row->load(['evento:id,NombreEvento,Anio', 'diseno:id,Nombre,ArchivoPdf,ArchivoPdfFirmas']);

        return response()->json(['data' => $row], 201);
    }

    public function destroy($id, Request $request)
    {
        $user = $request->user();
        $row = CertificadoAsignacion::where('id', (int) $id)
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->whereHas('evento', fn ($e) => $e->where('instituciones_id', (int) $user->instituciones_id));
            })
            ->firstOrFail();
        $row->delete();

        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
}
