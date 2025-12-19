<?php

namespace App\Http\Controllers;

use App\Models\carreras;
use Illuminate\Http\Request;

use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;
class CarrerasController extends Controller
{
    public function __construct() {
        $this->middleware(UpdateTokenExpiration::class);
    }
    //controllerPHPlcch carreras, $
    //#region Inicio Controller de Crud PHP de carreras
    public function index()
    {
        $carreras = carreras::all();
        return response()->json(['data' => $carreras]);
    }
    
    
    public function store(Request $request)
    {
        $carreras = $request->all();
        carreras::insert($carreras);
        return response()->json(['data' => $carreras]);
    }
    
    public function show($id)
    {
        $carreras = carreras::where('id','=',$id)->firstOrFail();
        return response()->json(['data' => $carreras]);
    }
    
    
    public function update(Request $request)
    {
        $carreras = $request->all();
        carreras::where('id','=',$request->id)->update($carreras);
        return response()->json(['data' => $carreras]);
    }
    
    public function destroy($id)
    {
        carreras::destroy($id);
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
    //#endregion Fin Controller de Crud PHP de carreras
}
