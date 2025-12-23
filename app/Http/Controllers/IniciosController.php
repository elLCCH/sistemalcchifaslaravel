<?php

namespace App\Http\Controllers;

use App\Models\inicios;
use Illuminate\Http\Request;

use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;
class IniciosController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }
    //controllerPHPlcch inicios, $
    //#region Inicio Controller de Crud PHP de inicios
    public function index()
    {
        $inicios = inicios::all();
        return response()->json(['data' => $inicios]);
    }
    
    
    public function store(Request $request)
    {
        $inicios = $request->all();
        inicios::insert($inicios);
        return response()->json(['data' => $inicios]);
    }
    
    public function show($id)
    {
        $inicios = inicios::where('id','=',$id)->firstOrFail();
        return response()->json(['data' => $inicios]);
    }
    
    
    public function update(Request $request)
    {
        $inicios = $request->all();
        inicios::where('id','=',$request->id)->update($inicios);
        return response()->json(['data' => $inicios]);
    }
    
    public function destroy($id)
    {
        inicios::destroy($id);
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
    //#endregion Fin Controller de Crud PHP de inicios
}
