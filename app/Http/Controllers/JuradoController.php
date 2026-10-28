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

    public function index(Request $request)
    {
        $query = Jurado::query();

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

        $jurado = Jurado::create($requestData);
        return response()->json(['data' => $jurado], 201);
    }

    public function show(Jurado $jurado)
    {
        $jurado->load(['asignaciones', 'calificaciones']);
        return response()->json(['data' => $jurado]);
    }

    public function edit(Jurado $jurado)
    {
        //
    }

    public function update(Request $request, Jurado $jurado)
    {
        $requestData = $request->all();

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

    public function destroy(Jurado $jurado)
    {
        $jurado->delete();
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
}

