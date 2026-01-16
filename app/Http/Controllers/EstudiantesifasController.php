<?php

namespace App\Http\Controllers;

use App\Models\Estudiantesifas;
use Illuminate\Http\Request;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;
class EstudiantesifasController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }

    private function normalizedCiExpr(): string
    {
        // Normaliza CI para comparar duplicados ignorando separadores comunes.
        return "UPPER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(CI,''), '-', ''), ' ', ''), '.', ''), '_', ''), '/', ''))";
    }

    private function normalizeCi(?string $ci): string
    {
        $ci = strtoupper(trim((string) ($ci ?? '')));
        // Misma lógica de separadores que en SQL (evitar REGEXP_REPLACE por compatibilidad)
        $ci = str_replace(['-', ' ', '.', '_', '/'], '', $ci);
        return $ci;
    }

    private function validarCiUnico(string $ci, ?int $ignoreId = null)
    {
        $norm = $this->normalizeCi($ci);
        if ($norm === '') return null;

        $q = Estudiantesifas::query()
            ->whereRaw($this->normalizedCiExpr() . ' = ?', [$norm]);
        if ($ignoreId) {
            $q->where('id', '<>', $ignoreId);
        }

        $row = $q->select(['id', 'CI', 'Ap_Paterno', 'Ap_Materno', 'Nombre'])->first();
        return $row;
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

        $query = Estudiantesifas::query()
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
        $data = $request->validate([
            'Foto' => ['nullable', 'string', 'max:250'],
            'Ap_Paterno' => ['nullable', 'string', 'max:50'],
            'Ap_Materno' => ['nullable', 'string', 'max:50'],
            'Nombre' => ['required', 'string', 'max:60'],
            'Sexo' => ['nullable', 'string', 'max:10'],
            'FechaNac' => ['nullable', 'date'],
            'Edad' => ['nullable', 'integer', 'min:0'],
            'CI' => ['required', 'string', 'max:20'],
            'Expedido' => ['nullable', 'string', 'max:20'],
            'Celular' => ['nullable', 'string', 'max:15'],
            'Direccion' => ['nullable', 'string', 'max:150'],
            'Correo' => ['nullable', 'string', 'max:100'],
            'Nombre_Padre' => ['nullable', 'string', 'max:50'],
            'Nombre_Madre' => ['nullable', 'string', 'max:50'],
            'OcupacionP' => ['nullable', 'string', 'max:20'],
            'OcupacionM' => ['nullable', 'string', 'max:20'],
            'NumCelP' => ['nullable', 'string', 'max:15'],
            'NumCelM' => ['nullable', 'string', 'max:15'],
            'NColegio' => ['nullable', 'string', 'max:100'],
            'TipoColegio' => ['nullable', 'string', 'max:50'],
            'CGrado' => ['nullable', 'string', 'max:50'],
            'CNivel' => ['nullable', 'string', 'max:50'],
            'Usuario' => ['nullable', 'string', 'max:50'],
            'Contrasenia' => ['nullable', 'string', 'max:500'],
            'Estado' => ['nullable', 'string', 'max:10'],
            'InformacionCompartidaIFAS' => ['nullable', 'string'],
        ]);

        $dup = $this->validarCiUnico((string) $data['CI']);
        if ($dup) {
            return response()->json([
                'message' => 'Ya existe un estudiante registrado con ese CI.',
                'duplicate' => $dup,
            ], 409);
        }

        if (!empty($data['Contrasenia'])) {
            $data['Contrasenia'] = Hash::make($data['Contrasenia']);
        }
        if (empty($data['Estado'])) {
            $data['Estado'] = 'ACTIVO';
        }

        $row = Estudiantesifas::create($data);
        return response()->json(['data' => $row], 201);
    }
    
    public function show($id)
    {
        $estudiantesifas = Estudiantesifas::where('id','=',$id)->firstOrFail();
        return response()->json(['data' => $estudiantesifas]);
    }
    
    
    public function update(Request $request, $id)
    {
        // $estudiantesifas = $request->all();
        // estudiantesifas::where('id','=',$request->id)->update($estudiantesifas);
        // return response()->json(['data' => $estudiantesifas]);
        $row = Estudiantesifas::findOrFail((int) $id);

        $data = $request->validate([
            'Foto' => ['nullable', 'string', 'max:250'],
            'Ap_Paterno' => ['nullable', 'string', 'max:50'],
            'Ap_Materno' => ['nullable', 'string', 'max:50'],
            'Nombre' => ['sometimes', 'required', 'string', 'max:60'],
            'Sexo' => ['nullable', 'string', 'max:10'],
            'FechaNac' => ['nullable', 'date'],
            'Edad' => ['nullable', 'integer', 'min:0'],
            'CI' => ['sometimes', 'required', 'string', 'max:20'],
            'Expedido' => ['nullable', 'string', 'max:20'],
            'Celular' => ['nullable', 'string', 'max:15'],
            'Direccion' => ['nullable', 'string', 'max:150'],
            'Correo' => ['nullable', 'string', 'max:100'],
            'Nombre_Padre' => ['nullable', 'string', 'max:50'],
            'Nombre_Madre' => ['nullable', 'string', 'max:50'],
            'OcupacionP' => ['nullable', 'string', 'max:20'],
            'OcupacionM' => ['nullable', 'string', 'max:20'],
            'NumCelP' => ['nullable', 'string', 'max:15'],
            'NumCelM' => ['nullable', 'string', 'max:15'],
            'NColegio' => ['nullable', 'string', 'max:100'],
            'TipoColegio' => ['nullable', 'string', 'max:50'],
            'CGrado' => ['nullable', 'string', 'max:50'],
            'CNivel' => ['nullable', 'string', 'max:50'],
            'Usuario' => ['nullable', 'string', 'max:50'],
            'Contrasenia' => ['nullable', 'string', 'max:500'],
            'Estado' => ['nullable', 'string', 'max:10'],
            'InformacionCompartidaIFAS' => ['nullable', 'string'],
        ]);

        if (array_key_exists('CI', $data)) {
            $dup = $this->validarCiUnico((string) $data['CI'], (int) $row->id);
            if ($dup) {
                return response()->json([
                    'message' => 'Ya existe un estudiante registrado con ese CI.',
                    'duplicate' => $dup,
                ], 409);
            }
        }

        if (array_key_exists('Contrasenia', $data)) {
            if (!empty($data['Contrasenia'])) {
                $data['Contrasenia'] = Hash::make($data['Contrasenia']);
            } else {
                unset($data['Contrasenia']);
            }
        }

        $row->update($data);
        return response()->json(['data' => $row]);
    }
    
    public function destroy($id)
    {
        Estudiantesifas::destroy($id);
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
    //#endregion Fin Controller de Crud PHP de estudiantesifas
}
