<?php

namespace App\Http\Controllers;

use App\Models\Instituciones;
use App\Models\Planteladministrativos;
use App\Models\Usuarioslcchs;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;

class InstitucionesController extends Controller
{
    private function datosInstitucion(Request $request): array
    {
        $validated = $request->validate([
            'LogoBoletin2026' => ['sometimes', 'nullable', 'array:ancho,alto,x,y'],
            'LogoCentralizador2026' => ['sometimes', 'nullable', 'array:ancho,alto,x,y'],
            'LogoBoletin2026.*' => ['nullable', 'numeric', 'between:-10000,10000'],
            'LogoCentralizador2026.*' => ['nullable', 'numeric', 'between:-10000,10000'],
        ]);

        $data = $request->all();
        foreach (['LogoBoletin2026', 'LogoCentralizador2026'] as $field) {
            if (array_key_exists($field, $validated)) {
                $data[$field] = $validated[$field] === null ? null : json_encode($validated[$field], JSON_THROW_ON_ERROR);
            }
        }
        return $data;
    }

    public function __construct()
    {
        // $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class])->except(['index', 'show']);
    }
    //#region Inicio Controller de Crud PHP de Instituciones
    public function index()
    {
        $Instituciones = Instituciones::all();
        return response()->json(['data' => $Instituciones]);
    }
    
    
    public function store(Request $request)
    {
        $Instituciones = $this->datosInstitucion($request);
        Instituciones::insert($Instituciones);
        return response()->json(['data' => $Instituciones]);
    }
    
    public function show($id)
    {
        $Instituciones = Instituciones::where('id','=',$id)->firstOrFail();
        return response()->json(['data' => $Instituciones]);
    }
    
    
    public function update(Request $request, $id)
    {
        $user = $request->user();
        $esCreador = $user instanceof Usuarioslcchs && $user->Tipo === 'CREADOR';
        if (!$esCreador) {
            abort_unless(
                $user instanceof Planteladministrativos
                && in_array($user->Tipo, ['RECTOR(A)', 'DIRECTOR(A)_ACADÉMICO(A)', 'SECRETARIO(A)', 'ADMINISTRADOR(A)'], true)
                && $user->instituciones_id !== null
                && (string) $user->instituciones_id === (string) $id,
                403,
                'Solo puede modificar su propia institución.'
            );
            abort_if(
                array_key_exists('Funciones', $request->all()) || array_key_exists('Caractisticas', $request->all()),
                403,
                'No tiene permiso para modificar Funciones o Caractisticas.'
            );
        }

        Instituciones::findOrFail($id);
        $Instituciones = $this->datosInstitucion($request);
        unset($Instituciones['id']);
        if (!$esCreador) {
            $Instituciones = array_intersect_key($Instituciones, array_flip((new Instituciones())->getFillable()));
        }
        Instituciones::where('id','=',$id)->update($Instituciones);
        return response()->json(['data' => $Instituciones]);
    }
    
    public function destroy($id)
    {
        Instituciones::destroy($id);
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
    //#endregion Fin Controller de Crud PHP de Instituciones
}
