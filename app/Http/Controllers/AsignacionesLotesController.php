<?php

namespace App\Http\Controllers;

use App\Http\Middleware\UpdateTokenExpiration;
use App\Models\Asignaciones_lotes;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AsignacionesLotesController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Asignaciones_lotes $asignaciones_lotes)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Asignaciones_lotes $asignaciones_lotes)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Asignaciones_lotes $asignaciones_lotes)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Asignaciones_lotes $asignaciones_lotes)
    {
        //
    }
}
