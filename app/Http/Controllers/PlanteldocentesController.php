<?php

namespace App\Http\Controllers;

use App\Models\planteldocentes;
use Illuminate\Http\Request;

use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;
class PlanteldocentesController extends Controller
{
    public function __construct() {
        $this->middleware(UpdateTokenExpiration::class);
    }
    //controllerPHPlcch planteldocentes, $
    //#region Inicio Controller de Crud PHP de planteldocentes
    public function index()
    {
        $planteldocentes = planteldocentes::all();
        return response()->json(['data' => $planteldocentes]);
    }
    
    
    public function store(Request $request)
    {
        $planteldocentes = $request->all();
        planteldocentes::insert($planteldocentes);
        return response()->json(['data' => $planteldocentes]);
    }
    
    public function show($id)
    {
        $planteldocentes = planteldocentes::where('id','=',$id)->firstOrFail();
        return response()->json(['data' => $planteldocentes]);
    }
    
    
    public function update(Request $request)
    {
        $planteldocentes = $request->all();
        planteldocentes::where('id','=',$request->id)->update($planteldocentes);
        return response()->json(['data' => $planteldocentes]);
    }
    
    public function destroy($id)
    {
        planteldocentes::destroy($id);
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
    //#endregion Fin Controller de Crud PHP de planteldocentes
}
