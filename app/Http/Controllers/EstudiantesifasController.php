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
        $searchMode = strtolower(trim((string) $request->query('search_mode', 'all')));
        if (!in_array($searchMode, ['any', 'all'], true)) {
            $searchMode = 'all';
        }
        $sortBy = (string) $request->query('sort_by', 'id');
        $sortDir = strtolower((string) $request->query('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        $allowedSort = [
            'id',
            'Ap_Paterno',
            'Ap_Materno',
            'Nombre',
            'CI',
            'Expedido',
            'Sexo',
            'Edad',
            'Estado',
        ];
        if (!in_array($sortBy, $allowedSort, true)) {
            $sortBy = 'id';
        }

        $query = estudiantesifas::query()
            ->when($search !== '', function ($q) use ($search, $searchMode) {
                $tokens = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $tokens = array_values(array_filter(array_unique(array_map('trim', $tokens))));
                if (count($tokens) === 0) return;

                $applyToken = function ($qq, string $like) {
                    $qq->where('Ap_Paterno', 'like', $like)
                        ->orWhere('Ap_Materno', 'like', $like)
                        ->orWhere('Nombre', 'like', $like)
                        ->orWhere('CI', 'like', $like)
                        ->orWhere('Expedido', 'like', $like)
                        ->orWhere('Sexo', 'like', $like)
                        ->orWhere('Estado', 'like', $like)
                        ->orWhere('Celular', 'like', $like)
                        ->orWhere('Direccion', 'like', $like)
                        ->orWhere('Correo', 'like', $like)
                        ->orWhere('Nombre_Padre', 'like', $like)
                        ->orWhere('Nombre_Madre', 'like', $like)
                        ->orWhere('OcupacionP', 'like', $like)
                        ->orWhere('OcupacionM', 'like', $like)
                        ->orWhere('NumCelP', 'like', $like)
                        ->orWhere('NumCelM', 'like', $like)
                        ->orWhere('NColegio', 'like', $like)
                        ->orWhere('TipoColegio', 'like', $like)
                        ->orWhere('CGrado', 'like', $like)
                        ->orWhere('CNivel', 'like', $like)
                        ->orWhere('Usuario', 'like', $like)
                        ->orWhere('InformacionCompartidaIFAS', 'like', $like);
                };

                if ($searchMode === 'any') {
                    // OR por tokens: si coincide cualquier token en cualquier campo, entra.
                    $q->where(function ($outer) use ($tokens, $applyToken) {
                        foreach ($tokens as $tok) {
                            if ($tok === '') continue;
                            $like = '%' . $tok . '%';
                            $outer->orWhere(function ($qq) use ($like, $applyToken) {
                                $applyToken($qq, $like);
                            });
                        }
                    });
                } else {
                    // AND por tokens: cada token debe aparecer en algún campo.
                    foreach ($tokens as $tok) {
                        if ($tok === '') continue;
                        $like = '%' . $tok . '%';
                        $q->where(function ($qq) use ($like, $applyToken) {
                            $applyToken($qq, $like);
                        });
                    }
                }
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
