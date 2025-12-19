<?php

namespace App\Http\Controllers;

use App\Models\planteladministrativos;
use Illuminate\Http\Request;

use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;
class PlanteladministrativosController extends Controller
{
    public function __construct() {
        $this->middleware(UpdateTokenExpiration::class);
    }
    //controllerPHPlcch planteladministrativos, $
    //#region Inicio Controller de Crud PHP de planteladministrativos
    public function index()
    {
        $planteladministrativos = planteladministrativos::all();
        return response()->json(['data' => $planteladministrativos]);
    }
    
    
    public function store(Request $request)
    {
        $planteladministrativos = $request->all();
        planteladministrativos::insert($planteladministrativos);
        return response()->json(['data' => $planteladministrativos]);
    }
    
    public function show($id)
    {
        $planteladministrativos = planteladministrativos::where('id','=',$id)->firstOrFail();
        return response()->json(['data' => $planteladministrativos]);
    }
    
    
    public function update(Request $request)
    {
        $planteladministrativos = $request->all();
        planteladministrativos::where('id','=',$request->id)->update($planteladministrativos);
        return response()->json(['data' => $planteladministrativos]);
    }
    
    public function destroy($id)
    {
        planteladministrativos::destroy($id);
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }
    //#endregion Fin Controller de Crud PHP de planteladministrativos
}
