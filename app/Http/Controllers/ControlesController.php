<?php

namespace App\Http\Controllers;

use App\Models\controles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;
class ControlesController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }

    private function upperTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (function_exists('mb_strtoupper')) {
            return mb_strtoupper($value, 'UTF-8');
        }

        return strtoupper($value);
    }
    //controllerPHPlcch controles, $
    //#region Inicio Controller de Crud PHP de controles
    public function index(Request $request)
    {
        $user = request()->user();
        $categoria = $this->upperTrim((string) $request->query('categoria', ''));

        $query = controles::query();

        // Por defecto: devolver controles globales (instituciones_id NULL)
        // + los específicos de la institución del usuario (si aplica)
        if (!empty($user?->instituciones_id)) {
            $query->where(function ($q) use ($user) {
                $q->whereNull('instituciones_id')
                    ->orWhere('instituciones_id', $user->instituciones_id);
            });
        } else {
            $query->whereNull('instituciones_id');
        }

        if ($categoria !== '') {
            $query->where('Categoria', $categoria);
        }

        $controles = $query->orderBy('Categoria')->orderBy('ParaI')->get();

        return response()->json(['data' => $controles]);
    }
    
    
    public function store(Request $request)
    {
        $payload = $request->all();
        $user = request()->user();

        // Normalizar strings para evitar typos: ESTADO / VISIBILIDAD, etc.
        foreach (['Categoria', 'ParaI', 'Edades', 'Estado', 'Visibilidad'] as $key) {
            if (array_key_exists($key, $payload) && $payload[$key] !== null) {
                $payload[$key] = $this->upperTrim((string) $payload[$key]);
            }
        }

        // Normalizar instituciones_id
        if (array_key_exists('instituciones_id', $payload)) {
            if ($payload['instituciones_id'] === '' || $payload['instituciones_id'] === null) {
                $payload['instituciones_id'] = null;
            }
        } else {
            // Si no se envía, asumir la institución del usuario (si tiene)
            if (!empty($user?->instituciones_id)) {
                $payload['instituciones_id'] = $user->instituciones_id;
            }
        }

        $id = controles::insertGetId($payload);
        $row = controles::where('id', $id)->first();
        return response()->json(['data' => $row]);
    }
    
    public function show($id)
    {
        $controles = controles::where('id','=',$id)->firstOrFail();
        return response()->json(['data' => $controles]);
    }
    
    
    public function update(Request $request)
    {
        $payload = $request->all();

        // Normalizar strings para evitar typos: ESTADO / VISIBILIDAD, etc.
        foreach (['Categoria', 'ParaI', 'Edades', 'Estado', 'Visibilidad'] as $key) {
            if (array_key_exists($key, $payload) && $payload[$key] !== null) {
                $payload[$key] = $this->upperTrim((string) $payload[$key]);
            }
        }

        if (array_key_exists('instituciones_id', $payload) && ($payload['instituciones_id'] === '' || $payload['instituciones_id'] === null)) {
            $payload['instituciones_id'] = null;
        }

        controles::where('id', '=', $request->id)->update($payload);
        $row = controles::where('id', '=', $request->id)->first();
        return response()->json(['data' => $row]);
    }
    
    public function destroy($id)
    {
        controles::destroy($id);
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
    //#endregion Fin Controller de Crud PHP de controles
}
