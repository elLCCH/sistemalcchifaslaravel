<?php

namespace App\Http\Controllers;

use App\Models\controles;
use Illuminate\Http\Request;

use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;
class ControlesController extends Controller
{
    public function __construct() {
        $this->middleware(UpdateTokenExpiration::class);
    }
    //controllerPHPlcch controles, $
    //#region Inicio Controller de Crud PHP de controles
    public function index()
    {
        $controles = controles::all();
        return response()->json(['data' => $controles]);
    }
    
    
    public function store(Request $request)
    {
        $controles = $request->all();
        controles::insert($controles);
        return response()->json(['data' => $controles]);
    }
    
    public function show($id)
    {
        $controles = controles::where('id','=',$id)->firstOrFail();
        return response()->json(['data' => $controles]);
    }
    
    
    public function update(Request $request)
    {
        $controles = $request->all();
        controles::where('id','=',$request->id)->update($controles);
        return response()->json(['data' => $controles]);
    }
    
    public function destroy($id)
    {
        controles::destroy($id);
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
    //#endregion Fin Controller de Crud PHP de controles
}
