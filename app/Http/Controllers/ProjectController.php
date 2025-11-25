<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    /**
     * Obtener todos los proyectos
     */
    public function index()
    {
        try {
            $projects = Project::all();
            return response()->json([
                'success' => true,
                'data' => $projects,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener proyectos: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Obtener un proyecto por ID
     */
    public function show($id)
    {
        try {
            $project = Project::findOrFail($id);
            return response()->json([
                'success' => true,
                'data' => $project,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Proyecto no encontrado',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener proyecto: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Crear un nuevo proyecto
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
            ]);

            $project = Project::create($validated);

            return response()->json([
                'success' => true,
                'data' => $project,
                'message' => 'Proyecto creado exitosamente',
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validación fallida',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear proyecto: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Actualizar un proyecto
     */
    public function update(Request $request, $id)
    {
        try {
            $project = Project::findOrFail($id);

            $validated = $request->validate([
                'name' => 'sometimes|required|string|max:255',
                'description' => 'nullable|string',
            ]);

            $project->update($validated);

            return response()->json([
                'success' => true,
                'data' => $project,
                'message' => 'Proyecto actualizado exitosamente',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Proyecto no encontrado',
            ], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validación fallida',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar proyecto: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Eliminar un proyecto
     */
    public function destroy($id)
    {
        try {
            $project = Project::findOrFail($id);
            
            // Eliminar todas las secciones y artículos asociados
            $project->sections()->each(function ($section) {
                $section->articles()->each(function ($article) {
                    $article->attachments()->delete();
                    $article->delete();
                });
                $section->delete();
            });

            $project->delete();

            return response()->json([
                'success' => true,
                'message' => 'Proyecto eliminado exitosamente',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Proyecto no encontrado',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar proyecto: ' . $e->getMessage(),
            ], 500);
        }
    }
}
