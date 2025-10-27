<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\usuarioslcchs;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        // validar
        $request->validate([
            "usuario" => "required",
            "contrasenia" => "required",
        ]);

        // verificar
        $user = $request->input('usuario');
        $pass = $request->input('contrasenia');

        $usuariolcch = usuarioslcchs::where('usuario', '=', $user)->where('contrasenia', '=', $pass)->first();

        if ($usuariolcch) {
            // generar token
            //$tokenResult = $usuariolcch->createToken("login");

            $NomC = 'LUIS CHOQUE';
            $tokenResult = $usuariolcch->createPersonalizedToken('login', ['*'], now()->addMinutes(60), ['nombrecompleto' => $NomC]);


            // $tokenResult = $usuariolcch->createToken('admin', ['*'], now()->addMinutes(60));
            $token = $tokenResult->plainTextToken;

            // responder
            return response()->json([
                "access_token" => $token, //ES SI SE GUARDA POR EL FUTURO INTERCEPTOR EN ANGULAR
                "token_type" => "Bearer", //PODEMOS GUARDAR ESTA VARIABLE EN UN localStorage
                "usuario" => $usuariolcch //PODEMOS GUARDAR ESTA VARIABLE EN UN localStorage
            ]);
        } else {
            return response()->json([
                "message" => "Nombre de usuario o contraseña incorrectos."
            ], 401);
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
            'tipo' => 'admin',
            'usuario' => $user,
            'abilities' => $abilities
            ]);
        } 
        // elseif ($user instanceof \App\Models\Cliente) {
        //     return response()->json([
        //     'tipo' => 'cliente',
        //     'usuario' => $user,
        //     'abilities' => $abilities
        //     ]);
        // } 
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