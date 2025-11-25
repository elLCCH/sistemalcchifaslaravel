<?php

namespace App\Http\Controllers;

use App\Models\Section;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;

class SectionController extends Controller
{
    public function __construct() {
        $this->middleware(UpdateTokenExpiration::class);
    }
    /**
     * Obtener secciones por proyecto
     */
    public function byProject($projectId)
    {
        try {
            $project = Project::findOrFail($projectId);
            $sections = $project->sections()->orderBy('order')->get();

            return response()->json([
                'success' => true,
                'data' => $sections,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Proyecto no encontrado',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener secciones: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Obtener una sección por ID
     */
    public function show($id)
    {
        try {
            $section = Section::findOrFail($id);
            return response()->json([
                'success' => true,
                'data' => $section,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Sección no encontrada',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener sección: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Crear una nueva sección
     */
    public function store(Request $request)
    {
        // $validated = $request->validate([
        //         'project_id' => 'required|exists:projects,id',
        //         'title' => 'required|string|max:255',
        //         'order' => 'nullable|integer|min:0',
        //     ]);
        // $section = Section::create($validated);

        //     return response()->json([
        //         'success' => true,
        //         'data' => $section,
        //         'message' => 'Sección creada exitosamente',
        //     ], 201);
        try {
            $validated = $request->validate([
                'project_id' => 'required|exists:projects,id',
                'title' => 'required|string|max:255',
                'order' => 'nullable|integer|min:0',
            ]);

            // Si no se especifica orden, usar la siguiente
            if (!isset($validated['order'])) {
                $maxOrder = Section::where('project_id', $validated['project_id'])->max('order') ?? -1;
                $validated['order'] = $maxOrder + 1;
            }

            $section = Section::create($validated);

            return response()->json([
                'success' => true,
                'data' => $section,
                'message' => 'Sección creada exitosamente',
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
                'message' => 'Error al crear sección: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Actualizar una sección
     */
    public function update(Request $request, $id)
    {
        try {
            $section = Section::findOrFail($id);

            $validated = $request->validate([
                'title' => 'sometimes|required|string|max:255',
                'order' => 'sometimes|integer|min:0',
            ]);

            $section->update($validated);

            return response()->json([
                'success' => true,
                'data' => $section,
                'message' => 'Sección actualizada exitosamente',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Sección no encontrada',
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
                'message' => 'Error al actualizar sección: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Eliminar una sección
     */
    public function destroy($id)
    {
        try {
            $section = Section::findOrFail($id);

            // Eliminar todos los artículos y sus archivos
            $section->articles()->each(function ($article) {
                $article->attachments()->delete();
                $article->delete();
            });

            $section->delete();

            return response()->json([
                'success' => true,
                'message' => 'Sección eliminada exitosamente',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Sección no encontrada',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar sección: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Reordenar secciones
     */
    public function reorder(Request $request)
    {
        try {
            $validated = $request->validate([
                '*.id' => 'required|exists:sections,id',
                '*.order' => 'required|integer|min:0',
            ]);

            foreach ($validated as $item) {
                Section::where('id', $item['id'])->update(['order' => $item['order']]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Secciones reordenadas exitosamente',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validación fallida',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al reordenar secciones: ' . $e->getMessage(),
            ], 500);
        }
    }
}
