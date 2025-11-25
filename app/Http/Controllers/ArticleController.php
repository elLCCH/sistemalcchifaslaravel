<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Section;
use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ArticleController extends Controller
{
    /**
     * Obtener artículos por sección
     */
    public function bySection($sectionId)
    {
        try {
            $section = Section::findOrFail($sectionId);
            $articles = $section->articles()->orderBy('order')->get();

            return response()->json([
                'success' => true,
                'data' => $articles,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Sección no encontrada',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener artículos: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Obtener un artículo por ID
     */
    public function show($id)
    {
        try {
            $article = Article::with('attachments')->findOrFail($id);
            return response()->json([
                'success' => true,
                'data' => $article,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Artículo no encontrado',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener artículo: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Crear un nuevo artículo
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'section_id' => 'required|exists:sections,id',
                'title' => 'required|string|max:255',
                'content' => 'nullable|string',
                'order' => 'nullable|integer|min:0',
            ]);

            // Si no se especifica orden, usar la siguiente
            if (!isset($validated['order'])) {
                $maxOrder = Article::where('section_id', $validated['section_id'])->max('order') ?? -1;
                $validated['order'] = $maxOrder + 1;
            }

            $article = Article::create($validated);

            return response()->json([
                'success' => true,
                'data' => $article,
                'message' => 'Artículo creado exitosamente',
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
                'message' => 'Error al crear artículo: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Actualizar un artículo
     */
    public function update(Request $request, $id)
    {
        try {
            $article = Article::findOrFail($id);

            $validated = $request->validate([
                'title' => 'sometimes|required|string|max:255',
                'content' => 'nullable|string',
                'order' => 'sometimes|integer|min:0',
            ]);

            $article->update($validated);

            return response()->json([
                'success' => true,
                'data' => $article,
                'message' => 'Artículo actualizado exitosamente',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Artículo no encontrado',
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
                'message' => 'Error al actualizar artículo: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Eliminar un artículo
     */
    public function destroy($id)
    {
        try {
            $article = Article::findOrFail($id);

            // Eliminar archivos asociados del directorio public/attachments
            $article->attachments()->each(function ($attachment) {
                $filePath = public_path('attachments/' . $attachment->stored_name);
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
                $attachment->delete();
            });

            $article->delete();

            return response()->json([
                'success' => true,
                'message' => 'Artículo eliminado exitosamente',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Artículo no encontrado',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar artículo: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Obtener archivos adjuntos de un artículo
     */
    public function getAttachments($articleId)
    {
        try {
            $article = Article::findOrFail($articleId);
            $attachments = $article->attachments;

            return response()->json([
                'success' => true,
                'data' => $attachments,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Artículo no encontrado',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener archivos: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Descargar un archivo adjunto
     */
    public function downloadAttachment($articleId, $attachmentId)
    {
        try {
            $article = Article::findOrFail($articleId);
            $attachment = $article->attachments()->findOrFail($attachmentId);

            $filePath = public_path('attachments/' . $attachment->stored_name);

            if (!file_exists($filePath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Archivo no encontrado',
                ], 404);
            }

            return response()->download($filePath, $attachment->file_name);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'No encontrado',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al descargar archivo: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Subir un archivo adjunto
     */
    public function upload(Request $request, $articleId)
{
    try {
        $article = Article::findOrFail($articleId);

        if (!$request->hasFile('file')) {
            return response()->json(['success' => false, 'message' => 'No file'], 400);
        }

        $file = $request->file('file');
        
        // Validación simple
        $request->validate([
            'file' => 'required|file|max:50000',
        ]);

        // Usar métodos alternativos si getSize() falla
        $fileSize = $file->getSize();
        
        // Si getSize() falla, intentar con filesize()
        if ($fileSize === false) {
            $filePath = $file->getPathname();
            $fileSize = filesize($filePath);
        }

        $originalName = $file->getClientOriginalName();
        $mimeType = $file->getMimeType();
        $fileName = uniqid() . '_' . $originalName;
        $path = 'attachments';

        // Crear directorio
        if (!file_exists(public_path($path))) {
            mkdir(public_path($path), 0755, true);
        }

        // Mover archivo
        $file->move(public_path($path), $fileName);

        $attachment = $article->attachments()->create([
            'file_name' => $originalName,
            'stored_name' => $fileName,
            'size' => $fileSize,
            'content_type' => $mimeType,
        ]);

        return response()->json([
            'success' => true,
            'data' => $attachment->id,
            'message' => 'Archivo subido exitosamente',
        ], 201);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage(),
        ], 500);
    }
}

    /**
     * Reordenar artículos
     */
    public function reorder(Request $request)
    {
        try {
            $validated = $request->validate([
                '*.id' => 'required|exists:articles,id',
                '*.order' => 'required|integer|min:0',
            ]);

            foreach ($validated as $item) {
                Article::where('id', $item['id'])->update(['order' => $item['order']]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Artículos reordenados exitosamente',
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
                'message' => 'Error al reordenar artículos: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Eliminar un archivo adjunto
     */
    public function deleteAttachment($articleId, $attachmentId)
    {
        try {
            $article = Article::findOrFail($articleId);
            $attachment = $article->attachments()->findOrFail($attachmentId);

            // Eliminar archivo del storage
            if (Storage::exists('attachments/' . $attachment->stored_name)) {
                Storage::delete('attachments/' . $attachment->stored_name);
            }

            $attachment->delete();

            return response()->json([
                'success' => true,
                'message' => 'Archivo eliminado exitosamente',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'No encontrado',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar archivo: ' . $e->getMessage(),
            ], 500);
        }
    }
}
