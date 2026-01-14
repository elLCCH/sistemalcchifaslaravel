<?php

namespace App\Http\Controllers;

use App\Models\Califhistorias;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;

class CalifhistoriasController extends Controller
{
    //controllerPHPlcch Califhistorias, $
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }

    //#region Inicio Controller de Crud PHP de Califhistorias
    public function index()
    {
        $Califhistorias = Califhistorias::all();
        return response()->json(['data' => $Califhistorias]);
    }
    
    
    public function store(Request $request)
    {
        $Califhistorias = $request->all();
        Califhistorias::insert($Califhistorias);
        return response()->json(['data' => $Califhistorias]);
    }
    
    public function show($id)
    {
        $Califhistorias = Califhistorias::where('id','=',$id)->firstOrFail();
        return response()->json(['data' => $Califhistorias]);
    }
    
    
    public function update(Request $request)
    {
        $Califhistorias = $request->all();
        Califhistorias::where('id','=',$request->id)->update($Califhistorias);
        return response()->json(['data' => $Califhistorias]);
    }
    
    public function destroy($id)
    {
        Califhistorias::destroy($id);
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
    //#endregion Fin Controller de Crud PHP de Califhistorias
}
