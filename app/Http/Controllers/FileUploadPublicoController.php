<?php

namespace App\Http\Controllers;

use App\Http\Middleware\UpdateTokenExpiration;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\File;

class FileUploadPublicoController extends Controller
{
    public function subicionpublico(Request $request)
    {
        $user = $request->user();
        $institucionId = $user ? ($user->instituciones_id ?? null) : null;
        // if (!$institucionId) {
        //     return response()->json(['error' => 'Usuario sin institución'], 422);
        // }

        $file = $request->file('file');
        if (!$file) {
            return response()->json(['error' => 'No se envió ningún archivo'], 400);
        }

        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'pdf'];
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, $allowedExtensions, true)) {
            return response()->json(['error' => 'Tipo de archivo no permitido'], 422);
        }

        $path = '';
        // $fileName = time() . '_' . $file->getClientOriginalName();
        $fileName = time() . '' . $file->getClientOriginalName();

        $base = 'archivos/institucion' . $institucionId;

        switch ($request->input('type')) {
            
            case 'FotoParticipantes':
                $path = 'lafotogracioneventos/fotosparticipantes';
                break;
            case 'ComprobantePago':
                $path = 'lafotogracioneventos/comprobantespago';
                break;
            case 'CertificadoNacimiento':
                $path = 'lafotogracioneventos/certificadosnacimiento';
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
    public function eliminacionpublico(Request $request)
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
            
            'lafotogracioneventos',
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

