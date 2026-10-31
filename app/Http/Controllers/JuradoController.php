<?php

namespace App\Http\Controllers;

use App\Models\Jurado;
use App\Models\JuradoAsignacion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;

class JuradoController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }

    // Un usuario de una institución solo ve y gestiona los jurados de su institución.
    private function institucionId(Request $request): ?int
    {
        $id = $request->user()?->instituciones_id;
        return empty($id) ? null : (int) $id;
    }

    private function validarInstitucion(Request $request, Jurado $jurado): void
    {
        $instId = $this->institucionId($request);
        abort_if($instId && (int) $jurado->instituciones_id !== $instId, 404);
    }

    public function index(Request $request)
    {
        $query = Jurado::query();
        $instId = $this->institucionId($request);
        if ($instId) $query->where('instituciones_id', $instId);

        if ($request->has('activos')) {
            $query->where('activo', (int) $request->input('activos'));
        }

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('nombres', 'LIKE', "%{$search}%")
                    ->orWhere('apellidos', 'LIKE', "%{$search}%")
                    ->orWhere('usuario', 'LIKE', "%{$search}%")
                    ->orWhere('carnet', 'LIKE', "%{$search}%");
            });
        }

        $jurados = $query->get();
        return response()->json(['data' => $jurados]);
    }

    public function create()
    {
        //
    }

    public function store(Request $request)
    {
        $requestData = $request->all();

        if (!empty($request->input('password'))) {
            $raw = (string) $request->input('password');
            if (trim($raw) !== '' && !\Illuminate\Support\Str::startsWith($raw, ['$2y$', '$2a$', '$argon2', '$bcrypt$'])) {
                $requestData['password'] = Hash::make($raw);
            }
        }

        $requestData['activo'] = $requestData['activo'] ?? 1;
        $instId = $this->institucionId($request);
        if ($instId) $requestData['instituciones_id'] = $instId;

        $jurado = Jurado::create($requestData);
        return response()->json(['data' => $jurado], 201);
    }

    public function show(Request $request, Jurado $jurado)
    {
        $this->validarInstitucion($request, $jurado);
        $jurado->load(['asignaciones', 'calificaciones']);
        return response()->json(['data' => $jurado]);
    }

    public function edit(Jurado $jurado)
    {
        //
    }

    public function update(Request $request, Jurado $jurado)
    {
        $this->validarInstitucion($request, $jurado);
        $requestData = $request->all();
        $instId = $this->institucionId($request);
        if ($instId) $requestData['instituciones_id'] = $instId;

        if ($request->has('password')) {
            $raw = (string) $request->input('password');
            if (trim($raw) !== '' && !\Illuminate\Support\Str::startsWith($raw, ['$2y$', '$2a$', '$argon2', '$bcrypt$'])) {
                $requestData['password'] = Hash::make($raw);
            } else {
                unset($requestData['password']);
            }
        }

        $jurado->update($requestData);
        return response()->json(['data' => $jurado]);
    }

    // Certificado del jurado generado al vuelo (Destinadoa = JURADOS), con o sin firmas.
    public function generarCertificado($id, Request $request)
    {
        $jurado = Jurado::findOrFail((int) $id);
        $this->validarInstitucion($request, $jurado);

        $request->validate(['evento_id' => 'required|integer|exists:eventos,id']);
        $user = $request->user();
        $evento = \App\Models\Eventos::query()
            ->where('id', (int) $request->input('evento_id'))
            ->when(!empty($user?->instituciones_id), fn ($q) => $q->where('instituciones_id', (int) $user->instituciones_id))
            ->firstOrFail();

        $asignaciones = JuradoAsignacion::where('jurado_id', $jurado->id)->where('evento_id', $evento->id)->get();
        if ($asignaciones->isEmpty()) {
            return response()->json(['error' => 'El jurado no está asignado a este evento'], 422);
        }
        $esp = trim((string) $request->input('especialidad', ''));
        $cat = trim((string) $request->input('categoria', ''));
        $asig = $asignaciones->first(fn ($a) => $a->especialidad === $esp && $a->categoria === $cat) ?? $asignaciones->first();

        $persona = new \App\Models\Estudianteseventos();
        $persona->Nombres = $jurado->nombres;
        $persona->Ap_Paterno = $jurado->apellidos;
        $persona->Ap_Materno = '';
        $persona->Carnet = $jurado->carnet;
        $persona->Foto = $jurado->foto;
        $persona->Especialidad = $asig->especialidad;
        $persona->Categoria = $asig->categoria;
        $persona->DatosEspeciales = json_encode([
            'PARAMETRO1' => $jurado->parametro1,
            'PARAMETRO2' => $jurado->parametro2,
            'PARAMETRO3' => $jurado->parametro3,
            'PARAMETRO4' => $jurado->parametro4,
            'PARAMETRO5' => $jurado->parametro5,
            'BIOGRAFIA' => $jurado->biografia,
        ]);

        $conFirmas = filter_var($request->input('conFirmas', false), FILTER_VALIDATE_BOOLEAN);
        return app(EstudianteseventosController::class)->emitirCertificadoPdf($persona, $evento, $conFirmas, $user, 'JURADOS');
    }

    public function destroy(Request $request, Jurado $jurado)
    {
        $this->validarInstitucion($request, $jurado);
        $jurado->delete();
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
}

