<?php

namespace App\Http\Controllers;

use App\Models\Infoauditoria;
use Illuminate\Http\Request;

class InfoauditoriaController extends Controller
{
    public function index(Request $request)
    {
        $perPage = (int) ($request->query('per_page', 30));
        if ($perPage < 1) $perPage = 30;
        if ($perPage > 200) $perPage = 200;

        $query = Infoauditoria::query();

        if ($request->filled('accion')) {
            $query->where('accion', $request->query('accion'));
        }

        if ($request->filled('recurso')) {
            $query->where('recurso', $request->query('recurso'));
        }

        if ($request->filled('actor_id')) {
            $query->where('actor_id', $request->query('actor_id'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->query('date_to'));
        }

        if ($request->filled('q')) {
            $q = $request->query('q');
            $query->where(function ($sub) use ($q) {
                $sub
                    ->where('actor_nombrecompleto', 'like', "%{$q}%")
                    ->orWhere('recurso', 'like', "%{$q}%")
                    ->orWhere('url', 'like', "%{$q}%")
                    ->orWhere('mensaje', 'like', "%{$q}%");
            });
        }

        return response()->json(
            $query->orderByDesc('id')->paginate($perPage)
        );
    }

    public function show(int $id)
    {
        return response()->json(Infoauditoria::findOrFail($id));
    }
}
