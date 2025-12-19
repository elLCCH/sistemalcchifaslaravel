<?php

namespace App\Http\Controllers;

use App\Models\plandeestudios;
use Illuminate\Http\Request;

use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;
class PlandeestudiosController extends Controller
{
    public function __construct() {
        $this->middleware(UpdateTokenExpiration::class);
    }
    //controllerPHPlcch plandeestudios, $
    //#region Inicio Controller de Crud PHP de plandeestudios
    public function index()
    {
        $plandeestudios = plandeestudios::all();
        return response()->json(['data' => $plandeestudios]);
    }
    
    
    public function store(Request $request)
    {
        $plandeestudios = $request->all();
        plandeestudios::insert($plandeestudios);
        return response()->json(['data' => $plandeestudios]);
    }
    
    public function show($id)
    {
        $plandeestudios = plandeestudios::where('id','=',$id)->firstOrFail();
        return response()->json(['data' => $plandeestudios]);
    }
    
    
    public function update(Request $request)
    {
        $plandeestudios = $request->all();
        plandeestudios::where('id','=',$request->id)->update($plandeestudios);
        return response()->json(['data' => $plandeestudios]);
    }
    
    public function destroy($id)
    {
        plandeestudios::destroy($id);
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
    //#endregion Fin Controller de Crud PHP de plandeestudios
}
