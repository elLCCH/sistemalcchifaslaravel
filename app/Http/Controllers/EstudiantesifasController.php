<?php

namespace App\Http\Controllers;

use App\Models\estudiantesifas;
use Illuminate\Http\Request;

use Illuminate\Support\Facades\Hash;
use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;
class EstudiantesifasController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }
    //controllerPHPlcch estudiantesifas, $
    //#region Inicio Controller de Crud PHP de estudiantesifas
    public function index(Request $request)
    {
        $perPage = (int) $request->query('per_page', 10);
        if ($perPage < 1) {
            $perPage = 10;
        }
        if ($perPage > 200) {
            $perPage = 200;
        }

        $search = trim((string) $request->query('search', ''));
        $sortBy = (string) $request->query('sort_by', 'id');
        $sortDir = strtolower((string) $request->query('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        $allowedSort = [
            'id',
            'Ap_Paterno',
            'Ap_Materno',
            'Nombre',
            'CI',
            'Expedido',
            'Matricula',
            'Sexo',
            'Edad',
            'Estado',
        ];
        if (!in_array($sortBy, $allowedSort, true)) {
            $sortBy = 'id';
        }

        $query = estudiantesifas::query()
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($qq) use ($search) {
                    $like = '%' . $search . '%';
                    $qq->where('Ap_Paterno', 'like', $like)
                        ->orWhere('Ap_Materno', 'like', $like)
                        ->orWhere('Nombre', 'like', $like)
                        ->orWhere('CI', 'like', $like)
                        ->orWhere('Matricula', 'like', $like);
                });
            })
            ->orderBy($sortBy, $sortDir);

        if ($sortBy !== 'id') {
            $query->orderByDesc('id');
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }
    
    
    public function store(Request $request)
    {
        $estudiantesifas = $request->all();
        $usuarioslcchs['Contrasenia'] = Hash::make($request->input('Contrasenia'));
        estudiantesifas::insert($estudiantesifas);
        return response()->json(['data' => $estudiantesifas]);
    }
    
    public function show($id)
    {
        $estudiantesifas = estudiantesifas::where('id','=',$id)->firstOrFail();
        return response()->json(['data' => $estudiantesifas]);
    }
    
    
    public function update(Request $request)
    {
        // $estudiantesifas = $request->all();
        // estudiantesifas::where('id','=',$request->id)->update($estudiantesifas);
        // return response()->json(['data' => $estudiantesifas]);

        $estudiantesifas = estudiantesifas::findOrFail($request->id);
        $requestData = $request->all();

        if ($request->has('Contrasenia')) {
            // Si se envió la contraseña
            if (Hash::needsRehash($request->Contrasenia)) {
            $requestData['Contrasenia'] = Hash::make($request->Contrasenia);
            } else {
            $requestData['Contrasenia'] = $request->Contrasenia;
            }
        } else {
            // No se envió la contraseña, mantener la actual
            $requestData['Contrasenia'] = $estudiantesifas->Contrasenia;
        }

        $estudiantesifas->update($requestData);
        return response()->json(['data' => $estudiantesifas]);
    }
    
    public function destroy($id)
    {
        estudiantesifas::destroy($id);
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
    //#endregion Fin Controller de Crud PHP de estudiantesifas
}
