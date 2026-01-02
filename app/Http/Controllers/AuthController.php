<?php

namespace App\Http\Controllers;

use App\Models\estudiantesifas;
use App\Models\planteladministrativos;
use App\Models\planteldocentes;
use Illuminate\Http\Request;
use App\Models\usuarioslcchs;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    // public function login(Request $request)
    // {
    //     // validar
    //     $request->validate([
    //         "Usuario" => "required",
    //         "Contrasenia" => "required",
    //     ]);

    //     // verificar
    //     $user = $request->input('Usuario');
    //     $pass = $request->input('Contrasenia');

    //     // $usuariolcch = usuarioslcchs::where('Usuario', '=', $user)->where('Contrasenia', '=', $pass)->first();
    //     $usuariolcch = planteladministrativos::where('Usuario', '=', $user)->where('Contrasenia', '=', $pass)->first();

    //     if ($usuariolcch) {
    //         // generar token
    //         //$tokenResult = $usuariolcch->createToken("login");

    //         $NomC = 'LUIS CHOQUE';
    //         $tokenResult = $usuariolcch->createPersonalizedToken('login', ['*'], now()->addMinutes(60), ['nombrecompleto' => $NomC]);


    //         // $tokenResult = $usuariolcch->createToken('admin', ['*'], now()->addMinutes(60));
    //         $token = $tokenResult->plainTextToken;

    //         // responder
    //         return response()->json([
    //             "access_token" => $token, //ES SI SE GUARDA POR EL FUTURO INTERCEPTOR EN ANGULAR
    //             "token_type" => "Bearer", //PODEMOS GUARDAR ESTA VARIABLE EN UN localStorage
    //             "usuario" => $usuariolcch //PODEMOS GUARDAR ESTA VARIABLE EN UN localStorage
    //         ]);
    //     } else {
    //         return response()->json([
    //             "message" => "Nombre de usuario o contraseña incorrectos."
    //         ], 401);
    //     }
    // }

    public function login(Request $request)
    {
        // validar
        $request->validate([
            "Usuario" => "required",
            "Contrasenia" => "required",
        ]);

        // verificar
        $user = $request->input('Usuario');
        $pass = $request->input('Contrasenia');
        $login =false;
        $tipoSesion = 'SIN DEFINIR';
        // $admin = Cliente::where('usuario', '=', $user)->where('contrasenia', '=', $pass)->first();
        // $admin = Usuario::where('usuario', '=', $user)->where('contrasenia', '=', $pass)->first();

        //INTENTANDO INICIO DE SESION COMO SUPERADMINISTRADOR
        $sesion = usuarioslcchs::where('Usuario','=', $user)->first();
        try {
            if ($sesion->Estado == 'ACTIVO') {
                if (Hash::check($pass, $sesion->Contrasenia)) {
                    // INICIO DE SESION CORRECTO COMO ADMINISTRADOR
                    $login =true;
                    $tipoSesion = 'usuarioslcchs'; // es un super lcch
                }
                else
                {
                    $login = false;
                }
            }else
            {
                $login = false;
            }
            
        } catch (\Throwable $th) {
            $login =false;
        }


        //INTENTANDO INICIO DE SESION COMO ADMINISTRATIVO DEL PLANTEL
        if ($login==false) {
            //INTENTANDO INICIO DE SESION COMO ADMINISTRATIVO DEL PLANTEL
            $sesion = planteladministrativos::where('Usuario','=', $user)->first();
            try {
                if ($sesion->Estado == 'ACTIVO') {
                    if (Hash::check($pass, $sesion->Contrasenia)) {
                        // INICIO DE SESION CORRECTO COMO ADMINISTRATIVO
                        $login =true;
                        $tipoSesion = 'planteladministrativos'; // es un ADMINISTRATIVO DEL PLANTEL
                    }
                    else
                    {
                        $login = false;
                    }
                }else{
                    $login = false;
                }
                
            } catch (\Throwable $th) {
                $login =false;
            }
        }
        //INTENTANDO INICIO DE SESION COMO DOCENTE DEL PLANTEL
        if ($login==false) {
            //INTENTANDO INICIO DE SESION COMO ADMINISTRATIVO DEL PLANTEL
            $sesion = planteldocentes::where('Usuario','=', $user)->first();
            try {
                if ($sesion->Estado == 'ACTIVO') {
                    if (Hash::check($pass, $sesion->Contrasenia)) {
                        // INICIO DE SESION CORRECTO COMO DOCENTE
                        $login =true;
                        $tipoSesion = 'planteldocentes'; // es un DOCENTE
                    }
                    else
                    {
                        $login = false;
                    }
                }else{
                    $login = false;
                }
                
            } catch (\Throwable $th) {
                $login =false;
            }
        }
        //INTENTANDO INICIO DE SESION COMO ESTUDIANTE 
        if ($login==false) {
            //INTENTANDO INICIO DE SESION COMO ESTUDIANTE
            $sesion = estudiantesifas::where('Usuario','=', $user)->first();
            try {
                if ($sesion->Estado == 'ACTIVO') {
                    if (Hash::check($pass, $sesion->Contrasenia)) {
                        // INICIO DE SESION CORRECTO COMO ESTUDIANTE
                        $login =true;
                        $tipoSesion = 'estudiantesifas'; // es un ESTUDIANTE
                    }
                    else
                    {
                        $login = false;
                    }
                }else{
                    $login = false;
                }
                
            } catch (\Throwable $th) {
                $login =false;
            }
        }

        //SI SE LOGRÓ REALIZAR EL LOGIN ENTONCES HACER TOKENS}
        if ($login==true) {
            switch ($tipoSesion) {
                case 'usuarioslcchs':
                    // Lógica para superadministrador
                    $NomC = $sesion->Apellidos.' '.$sesion->Nombres;
                    $tiposuperadministrador = $sesion->Tipo;
                    $Permisos = $sesion->Permisos;
                    switch ($tiposuperadministrador) {
                        case 'CREADOR':
                            $tokenResult = $sesion->createPersonalizedToken('LCCH', ['CREADOR'], now()->addMinutes(120), ['nombrecompleto' => $NomC,'pertenencia' => 'lcchs', 'permisos' => $Permisos]);
                            $token = $tokenResult->plainTextToken;
                            break;
                        case 'TECNICO':
                            $tokenResult = $sesion->createPersonalizedToken('LCCH', ['TÉCNICO'], now()->addMinutes(120), ['nombrecompleto' => $NomC,'pertenencia' => 'lcchs', 'permisos' => $Permisos]);
                            $token = $tokenResult->plainTextToken;
                            break;
                        
                        default:
                            $tokenResult = $sesion->createPersonalizedToken('LCCH', ['NO_TIENE_TIPO'], now()->addMinutes(120), ['nombrecompleto' => $NomC,'pertenencia' => 'lcchs', 'permisos' => $Permisos]);
                            $token = $tokenResult->plainTextToken;
                            break;
                    }
                    return response()->json([
                        "access_token" => $token,
                        "token_type" => "Bearer",
                        "usuario" => $sesion
                    ]);
                    break;
                case 'planteladministrativos':
                    // Lógica para administrativo del plantel
                    $NomC = $sesion->Apellidos.' '.$sesion->Nombres;
                    $planteladministrativos = $sesion->Tipo;
                    $Permisos = $sesion->Permisos;
                    //PARA SACAR EL NOMBRE DE INSTITUCION
                    $institucion = \App\Models\instituciones::find($sesion->instituciones_id);
                    $nameInstitucion = $institucion ? $institucion->Nombre : null;
                    switch ($planteladministrativos) {
                        case 'RECTOR(A)':
                            $tokenResult = $sesion->createPersonalizedToken($nameInstitucion, ['RECTOR(A)'], now()->addMinutes(120), ['nombrecompleto' => $NomC,'pertenencia' => 'administrativos', 'permisos' => $Permisos]);
                            $token = $tokenResult->plainTextToken;
                            break;
                        case 'SECRETARIO(A)':
                            $tokenResult = $sesion->createPersonalizedToken($nameInstitucion, ['SECRETARIO(A)'], now()->addMinutes(120), ['nombrecompleto' => $NomC,'pertenencia' => 'administrativos', 'permisos' => $Permisos]);
                            $token = $tokenResult->plainTextToken;
                            break;
                        
                        default:
                            $tokenResult = $sesion->createPersonalizedToken($nameInstitucion, ['NO_TIENE_TIPO'], now()->addMinutes(120), ['nombrecompleto' => $NomC,'pertenencia' => 'administrativos', 'permisos' => $Permisos]);
                            $token = $tokenResult->plainTextToken;
                            break;
                    }
                    return response()->json([
                        "access_token" => $token,
                        "token_type" => "Bearer",
                        "usuario" => $sesion
                    ]);
                    break;
                case 'planteldocentes':
                    // Lógica para docente del plantel
                    $NomC = $sesion->Apellidos.' '.$sesion->Nombres;
                    $planteldocentes = $sesion->Tipo;
                    $Permisos = $sesion->Permisos;
                    //PARA SACAR EL NOMBRE DE INSTITUCION
                    $institucion = \App\Models\instituciones::find($sesion->instituciones_id);
                    $nameInstitucion = $institucion ? $institucion->Nombre : null;
                    switch ($planteldocentes) {
                        case 'DOCENTE':
                            $tokenResult = $sesion->createPersonalizedToken($nameInstitucion, ['DOCENTE'], now()->addMinutes(120), ['nombrecompleto' => $NomC,'pertenencia' => 'docentes', 'permisos' => $Permisos]);
                            $token = $tokenResult->plainTextToken;
                            break;
                        case 'DOCENTECOLABORADOR':
                            $tokenResult = $sesion->createPersonalizedToken($nameInstitucion, ['DOCENTECOLABORADOR'], now()->addMinutes(120), ['nombrecompleto' => $NomC,'pertenencia' => 'docentes', 'permisos' => $Permisos]);
                            $token = $tokenResult->plainTextToken;
                            break;
                        
                        default:
                            $tokenResult = $sesion->createPersonalizedToken($nameInstitucion, ['NO_TIENE_TIPO'], now()->addMinutes(120), ['nombrecompleto' => $NomC,'pertenencia' => 'docentes', 'permisos' => $Permisos]);
                            $token = $tokenResult->plainTextToken;
                            break;
                    }
                    return response()->json([
                        "access_token" => $token,
                        "token_type" => "Bearer",
                        "usuario" => $sesion
                    ]);
                    break;
                case 'estudiantesifas':
                    // Lógica para estudiante
                    $NomC = $sesion->Apellidos.' '.$sesion->Nombres;
                    $estudiantesifas = $sesion->Tipo;
                    switch ($estudiantesifas) {
                        case 'DOCENTE':
                            $tokenResult = $sesion->createPersonalizedToken('ESTUDIANTE', ['ESTUDIANTE'], now()->addMinutes(120), ['nombrecompleto' => $NomC,'pertenencia' => 'estudiantes']);
                            $token = $tokenResult->plainTextToken;
                            break;
                        case 'DOCENTECOLABORADOR':
                            $tokenResult = $sesion->createPersonalizedToken('ESTUDIANTE', ['ESTUDIANTE'], now()->addMinutes(120), ['nombrecompleto' => $NomC,'pertenencia' => 'estudiantes']);
                            $token = $tokenResult->plainTextToken;
                            break;
                        
                        default:
                            $tokenResult = $sesion->createPersonalizedToken('ESTUDIANTE', ['NO_TIENE_TIPO'], now()->addMinutes(120), ['nombrecompleto' => $NomC,'pertenencia' => 'estudiantes']);
                            $token = $tokenResult->plainTextToken;
                            break;
                    }
                    return response()->json([
                        "access_token" => $token,
                        "token_type" => "Bearer",
                        "usuario" => $sesion
                    ]);
                    break;
                default:
                    // Lógica por defecto si no coincide ningún caso
                    return response()->json([
                        "message" => "Nombre de usuario o contraseña incorrectos."
                    ], 401);
                    break;
            }
        }
        
    }
    
    public function logout(Request $request)
    {
        // Intentar obtener el token desde input('token') o desde el header Authorization
        $token = $request->input('token') ?: $request->bearerToken();

        if (!$token) {
            return response()->json(['message' => 'TOKEN NO PROPORCIONADO'], 400);
        }

        // Limpiar prefijo Bearer si existe y espacios
        $token = trim(preg_replace('/^Bearer\s+/i', '', $token));

        $tokenParts = explode('|', $token);
        $tokenId = $tokenParts[0] ?? null;

        if ($tokenId) {
            // Usar el query builder para eliminar de forma segura
            $deleted = DB::table('personal_access_tokens')->where('id', $tokenId)->delete();

            if ($deleted) {
                return response()->json(['message' => 'Token DB ELIMINADO'], 200);
            } else {
                return response()->json(['message' => 'Token no encontrado o no eliminado'], 404);
            }
        }

        return response()->json(['message' => 'TOKEN DB NO ENCONTRADO'], 404);
    }

    // Esta función obtiene el usuario autenticado, ya sea Usuario o Cliente
    public function getUser(Request $request)
    {
        $user = $request->user();
        $token = $request->bearerToken();

        // Obtener abilities del token
        $abilities = [];
        if ($token) {
            $tokenParts = explode('|', $token);
            $tokenId = $tokenParts[0] ?? null;
            if ($tokenId) {
            $tokenRecord = DB::table('personal_access_tokens')->where('id', $tokenId)->first();
            if ($tokenRecord && isset($tokenRecord->abilities)) {
                $abilities = json_decode($tokenRecord->abilities, true) ?? [];
            }
            }
        }

        // Verifica si el usuario autenticado es del modelo Usuario o Cliente
        if ($user instanceof \App\Models\usuarioslcchs) {
            return response()->json([
            'tipo' => 'lcch',
            'usuario' => $user,
            'abilities' => $abilities
            ]);
        } 
        elseif ($user instanceof \App\Models\planteladministrativos) {
            return response()->json([
            'tipo' => 'cliente',
            'usuario' => $user,
            'abilities' => $abilities
            ]);
        } 
        elseif ($user instanceof \App\Models\planteldocentes) {
            return response()->json([
            'tipo' => 'cliente',
            'usuario' => $user,
            'abilities' => $abilities
            ]);
        } 
        elseif ($user instanceof \App\Models\estudiantesifas) {
            return response()->json([
            'tipo' => 'cliente',
            'usuario' => $user,
            'abilities' => $abilities
            ]);
        } 
        else {
            return response()->json([
            'message' => 'No autenticado.'
            ], 401);
        }
    }

    public function cambiarClave(Request $request)
    {
        $request->validate([
            'claveActual' => 'required',
            'nuevaClave' => 'required|min:6',
        ]);

        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        // Verificar clave actual
        if (!Hash::check($request->input('claveActual'), $user->contrasenia)) {
            return response()->json(['message' => 'La clave actual es incorrecta.'], 400);
        }

        // Actualizar la contraseña
        $user->contrasenia = Hash::make($request->input('nuevaClave'));
        $user->save();

        return response()->json(['message' => 'Contraseña actualizada correctamente.']);
    }

}