<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class FileUploadController extends Controller
{
    public function uploadFile(Request $request)
    {
        $user = $request->user();
        $institucionId = $user ? ($user->instituciones_id ?? null) : null;
        if (!$institucionId) {
            return response()->json(['error' => 'Usuario sin institución'], 422);
        }

        $file = $request->file('file');
        if (!$file) {
            return response()->json(['error' => 'No se envió ningún archivo'], 400);
        }

        $path = '';
        // $fileName = time() . '_' . $file->getClientOriginalName();
        $fileName = time() . '' . $file->getClientOriginalName();

        $base = 'archivos/institucion' . $institucionId;

        switch ($request->input('type')) {
            case 'Foto':
                $path = $base . '/FotosPerfiles';
                break;
            case 'inicioscarreras':
                $path = $base . '/inicios/carreras';
                break;
            case 'inicioscarouseles':
                $path = $base . '/inicios/carouseles';
                break;
            default:
                return response()->json(['error' => 'Tipo de archivo no válido'], 400);
        }

        // Asegurar que exista el directorio destino
        if (!File::exists(public_path($path))) {
            File::makeDirectory(public_path($path), 0755, true, true);
        }

        $file->move(public_path($path), $fileName);

        return response()->json(['filePath' => "$path/$fileName"], 200);
    }
    public function deleteFile(Request $request)
    {
        $filePath = $request->input('filePath');

        if (!$filePath || !is_string($filePath)) {
            return response()->json(['success' => false, 'message' => 'filePath inválido'], 400);
        }

        // Normalizar separadores y prevenir path traversal
        $filePath = str_replace('\\', '/', $filePath);
        if (str_contains($filePath, '..') || str_starts_with($filePath, '/') || str_starts_with($filePath, '\\')) {
            return response()->json(['success' => false, 'message' => 'Ruta no permitida'], 400);
        }

        // Allowlist: solo permitir borrados dentro de carpetas esperadas
        $allowedPrefixes = [
            'archivos/',
            // compatibilidad con rutas antiguas (si existen registros previos)
            'inicios/',
            'FotosPerfiles',
        ];

        $allowed = false;
        foreach ($allowedPrefixes as $prefix) {
            if (str_starts_with($filePath, $prefix)) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            return response()->json(['success' => false, 'message' => 'Ruta fuera de las carpetas permitidas'], 400);
        }

        if (File::exists(public_path($filePath))) {
            File::delete(public_path($filePath));
            return response()->json(['success' => true, 'message' => 'File deleted successfully']);
        } else {
            return response()->json(['success' => false, 'message' => 'File not found']);
        }
    }
}

