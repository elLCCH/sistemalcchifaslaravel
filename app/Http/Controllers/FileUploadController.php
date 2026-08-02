<?php

namespace App\Http\Controllers;

use App\Http\Middleware\UpdateTokenExpiration;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\File;

class FileUploadController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }

    public function uploadFile(Request $request)
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

        $path = '';
        // $fileName = time() . '_' . $file->getClientOriginalName();
        $fileName = time() . '' . $file->getClientOriginalName();

        $base = 'archivos/institucion' . $institucionId;

        switch ($request->input('type')) {
            case 'Foto':
                $path = 'archivos/compartidosifas/FotosPerfiles';
                break;
            case 'Logo':
                $path = 'archivos/compartidosifas/logo';
                break;
            case 'BannerInicial':
                $path = 'archivos/compartidosifas/BannerInicial';
                break;
            case 'ImagenVision':
                $path = 'archivos/compartidosifas/ImagenVision';
                break;
            case 'ImagenMision':
                $path = 'archivos/compartidosifas/ImagenMision';
                break;
            case 'inicioscarreras':
                $path = 'archivos/compartidosifas/inicios/carreras';
                break;
            case 'inicioscarouseles':
                $path = 'archivos/compartidosifas/inicios/carouseles';
                break;
            case 'iniciospublicaciones':
                $path = 'archivos/compartidosifas/inicios/publicaciones';
                break;
            case 'FotoParticipantes':
                $path = 'lafotogracioneventos/fotosparticipantes';
                break;
            case 'ComprobantePago':
                $path = 'archivos/compartidosifas/comprobantespago';
                break;
            case 'inicioshorarios':
                // Horarios públicos por institución (PDF o imagen)
                $ext = strtolower((string) $file->getClientOriginalExtension());
                $allowed = ['pdf', 'png', 'jpg', 'jpeg', 'webp'];
                if (!in_array($ext, $allowed, true)) {
                    return response()->json(['error' => 'Solo se permite PDF o imagen (png/jpg/jpeg/webp)'], 422);
                }
                $path = 'archivos/compartidosifas/inicios/horarios';
                break;

            case 'plantillasexcel':
                // Plantillas Excel (por institución): xlsx/xlsm/xltx
                // Se usa para generar registros en la tabla controles (CRUD independiente)
                $ext = strtolower((string) $file->getClientOriginalExtension());
                $allowed = ['xlsx', 'xlsm', 'xltx'];
                if (!in_array($ext, $allowed, true)) {
                    return response()->json(['error' => 'Solo se permite Excel (xlsx/xlsm/xltx)'], 422);
                }
                $path = $base . '/plantillasexcel';
                break;
                
            case 'pagosAnualesUnicos':
                $path = $base . '/pagoslcch/pagosunicosgestiones';
                break;
            case 'pagoslcchcomprobantes':
                $path = $base . '/pagoslcch/comprobantes';
                break;

            case 'bibliotecaPdf':
                // PDFs para biblioteca digital (por institución)
                $ext = strtolower((string) $file->getClientOriginalExtension());
                if ($ext !== 'pdf') {
                    return response()->json(['error' => 'Solo se permite PDF'], 422);
                }
                $path = $base . '/biblioteca/pdf';
                break;
            case 'certificadoPdf':
                // PDF base para diseños de certificados (por institución)
                $ext = strtolower((string) $file->getClientOriginalExtension());
                if ($ext !== 'pdf') {
                    return response()->json(['error' => 'Solo se permite PDF'], 422);
                }
                $path = $base . '/eventos/certificados/disenos';
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

