<?php

namespace Tests\Feature;

use App\Http\Middleware\AuditMiddleware;
use App\Models\Planteladministrativos;
use App\Models\Usuarioslcchs;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InstitucionesPermisosTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        $this->withoutMiddleware(AuditMiddleware::class);
        Schema::create('instituciones', function (Blueprint $table) {
            $table->increments('id');
            $table->string('Nombre')->nullable();
            $table->string('Funciones')->nullable();
            $table->string('Caractisticas')->nullable();
            $table->json('LogoBoletin2026')->nullable();
            $table->timestamps();
        });
        Schema::create('planteladministrativos', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('instituciones_id')->nullable();
            $table->string('Tipo');
            $table->timestamps();
        });
        Schema::create('usuarioslcchs', function (Blueprint $table) {
            $table->increments('id');
            $table->string('Tipo');
            $table->timestamps();
        });
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
        DB::table('instituciones')->insert([
            ['id' => 1, 'Nombre' => 'Una', 'Funciones' => 'Original', 'Caractisticas' => 'Original'],
            ['id' => 2, 'Nombre' => 'Otra', 'Funciones' => 'Otra', 'Caractisticas' => 'Otra'],
        ]);
    }

    private function tokenAdministrativo(string $tipo, ?int $institucionId = 1): string
    {
        $user = Planteladministrativos::create(['Tipo' => $tipo, 'instituciones_id' => $institucionId]);
        return $user->createToken('test', [$tipo])->plainTextToken;
    }

    public function test_cargos_autorizados_modifican_solo_su_institucion(): void
    {
        foreach (['RECTOR(A)', 'DIRECTOR(A)_ACADÉMICO(A)', 'SECRETARIO(A)', 'ADMINISTRADOR(A)'] as $tipo) {
            $token = $this->tokenAdministrativo($tipo);
            app('auth')->forgetGuards();
            $this->withToken($token)->putJson('/api/instituciones/1', [
                'id' => 2, 'Nombre' => $tipo, 'LogoBoletin2026' => ['x' => -5],
            ])->assertOk();
            $this->assertDatabaseHas('instituciones', ['id' => 1, 'Nombre' => $tipo, 'Funciones' => 'Original']);
            $this->assertDatabaseHas('instituciones', ['id' => 2, 'Nombre' => 'Otra']);
            $this->withToken($token)->putJson('/api/instituciones/2', ['Nombre' => 'No'])->assertForbidden();
        }
    }

    public function test_no_pueden_modificar_campos_reservados_crear_ni_eliminar(): void
    {
        $token = $this->tokenAdministrativo('SECRETARIO(A)');
        foreach (['Funciones', 'Caractisticas'] as $campo) {
            $this->withToken($token)->putJson('/api/instituciones/1', [
                'Nombre' => 'No debe guardar', $campo => 'Alterado',
            ])->assertForbidden();
        }
        $this->withToken($token)->postJson('/api/instituciones', ['Nombre' => 'Nueva'])->assertForbidden();
        $this->withToken($token)->deleteJson('/api/instituciones/1')->assertForbidden();
        $this->assertDatabaseHas('instituciones', [
            'id' => 1, 'Nombre' => 'Una', 'Funciones' => 'Original', 'Caractisticas' => 'Original',
        ]);
    }

    public function test_rechaza_roles_no_autorizados_y_usuarios_sin_institucion(): void
    {
        foreach ([['CONSERJE', 1], ['PRACTICANTE', 1], ['RECTOR(A)', null]] as [$tipo, $id]) {
            $token = $this->tokenAdministrativo($tipo, $id);
            app('auth')->forgetGuards();
            $this->withToken($token)->putJson('/api/instituciones/1', ['Nombre' => 'No'])->assertForbidden();
        }
        $this->assertDatabaseHas('instituciones', ['id' => 1, 'Nombre' => 'Una']);
    }

    public function test_creador_conserva_acceso_completo(): void
    {
        $creador = Usuarioslcchs::create(['Tipo' => 'CREADOR']);
        $token = $creador->createToken('test', ['CREADOR'])->plainTextToken;
        $this->withToken($token)->putJson('/api/instituciones/2', [
            'id' => 1, 'Funciones' => 'Nuevas', 'Caractisticas' => 'Nuevas',
        ])->assertOk();
        $this->assertDatabaseHas('instituciones', ['id' => 2, 'Funciones' => 'Nuevas', 'Caractisticas' => 'Nuevas']);
        $this->withToken($token)->postJson('/api/instituciones', ['Nombre' => 'Nueva'])->assertOk();
        $this->withToken($token)->deleteJson('/api/instituciones/2')->assertOk();
        $this->assertDatabaseMissing('instituciones', ['id' => 2]);
    }
}
