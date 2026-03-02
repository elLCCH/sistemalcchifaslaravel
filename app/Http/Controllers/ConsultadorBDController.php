<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;

class ConsultadorBDController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }

    //CAMBIAR A USUARIO GENERAL
    // UPDATE estudiantesifas
    // SET Usuario = UPPER(CONCAT(
    //     IF(Ap_Paterno IS NULL OR Ap_Paterno <= '.', '', SUBSTRING(Ap_Paterno, 1, 1)),
    //     IF(Ap_Materno IS NULL OR Ap_Materno <= '.', '', SUBSTRING(Ap_Materno, 1, 1)),
    //     IF(Nombre IS NULL OR Nombre <= '.', '', SUBSTRING(Nombre, 1, 1)),
    //     CI, 
    //     'EST'
    // ));

    //CAMBIAR A CONTRASEÑA PLANA
    // UPDATE estudiantesifas
    // SET Contrasenia = LOWER(CONCAT(
    //     IF(Ap_Paterno IS NULL OR Ap_Paterno <= '.', '', SUBSTRING(Ap_Paterno, 1, 1)),
    //     IF(Ap_Materno IS NULL OR Ap_Materno <= '.', '', SUBSTRING(Ap_Materno, 1, 1)),
    //     IF(Nombre IS NULL OR Nombre <= '.', '', SUBSTRING(Nombre, 1, 1)),
    //     DATE_FORMAT(FechaNac, '%d%m%Y')
    // ));


    /**
     * Ejecuta una consulta SQL (solo SELECT y UPDATE permitidos).
     * Si la consulta contiene "-- encriptar", encripta las contraseñas no encriptadas.
     *
     * USAR EJEMPLO: select id, Nombre, Ap_Paterno, Ap_Materno, FechaNac, Contrasenia from estudiantesifas WHERE id BETWEEN 1 AND 100 -- encriptar
     * 
     */
    public function ConsultarApi(Request $request)
    {
        $sql = trim($request->consultasql ?? '');

        if (empty($sql)) {
            return response()->json(['error' => 'La consulta SQL está vacía.'], 400);
        }

        // Detectar tipo de consulta (solo permitir SELECT y UPDATE)
        $sqlLimpio = preg_replace('/--.*$/m', '', $sql); // quitar comentarios de línea
        $sqlLimpio = trim($sqlLimpio);
        $primeraPalabra = strtoupper(strtok($sqlLimpio, " \t\r\n"));

        if (!in_array($primeraPalabra, ['SELECT', 'UPDATE'])) {
            return response()->json([
                'error' => 'Solo se permiten consultas SELECT y UPDATE. Operación "' . $primeraPalabra . '" no permitida.'
            ], 403);
        }

        try {
            // Verificar si la consulta contiene "-- encriptar"
            if (str_contains($sql, '-- encriptar')) {
                // Quitar el comentario "-- encriptar" para ejecutar la consulta limpia
                $sqlSinComentario = str_replace('-- encriptar', '', $sql);
                $sqlSinComentario = trim($sqlSinComentario);

                $data = DB::select($sqlSinComentario);

                $encriptados = 0;
                $yaEncriptados = 0;

                foreach ($data as $row) {
                    // Verificar si tiene campo Contrasenia
                    if (!isset($row->Contrasenia)) {
                        continue;
                    }

                    // VALIDACIÓN: ¿Ya está encriptado? (Laravel Bcrypt: ~60 chars y empieza con $2y$)
                    $estaEncriptado = str_starts_with($row->Contrasenia, '$2y$') && strlen($row->Contrasenia) > 50;

                    if ($estaEncriptado) {
                        $yaEncriptados++;
                        continue;
                    }

                    // 1. Obtener iniciales (Paterno, Materno, Nombre)
                    $p = (isset($row->Ap_Paterno) && $row->Ap_Paterno && $row->Ap_Paterno != '.') ? substr($row->Ap_Paterno, 0, 1) : '';
                    $m = (isset($row->Ap_Materno) && $row->Ap_Materno && $row->Ap_Materno != '.') ? substr($row->Ap_Materno, 0, 1) : '';
                    $n = (isset($row->Nombre) && $row->Nombre && $row->Nombre != '.') ? substr($row->Nombre, 0, 1) : '';

                    $letras = strtolower($p . $m . $n);

                    // 2. Formatear fecha (ej: 01021999)
                    $fecha = '';
                    if (isset($row->FechaNac) && $row->FechaNac) {
                        $fecha = \Carbon\Carbon::parse($row->FechaNac)->format('dmY');
                    }

                    // 3. Unir para la contraseña base (ej: cfl01021999)
                    $passwordBase = $letras . $fecha;

                    // 4. Actualizar SOLO la contraseña encriptándola
                    if (isset($row->id)) {
                        DB::table('estudiantesifas')
                            ->where('id', $row->id)
                            ->update([
                                'Contrasenia' => Hash::make($passwordBase)
                            ]);
                        $encriptados++;
                    }
                }

                // Refrescar los datos después de encriptar
                $data = DB::select($sqlSinComentario);

                return response()->json([
                    'data' => $data,
                    'encriptacion' => [
                        'ejecutado' => true,
                        'encriptados' => $encriptados,
                        'ya_encriptados' => $yaEncriptados,
                        'total' => count($data),
                    ]
                ]);
            }

            // Consulta normal (SELECT o UPDATE)
            if ($primeraPalabra === 'SELECT') {
                $data = DB::select($sqlLimpio);
                return response()->json(['data' => $data]);
            } else {
                // UPDATE
                $affected = DB::update($sqlLimpio);
                return response()->json([
                    'data' => [],
                    'affected_rows' => $affected,
                    'message' => $affected . ' fila(s) afectada(s).'
                ]);
            }
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
