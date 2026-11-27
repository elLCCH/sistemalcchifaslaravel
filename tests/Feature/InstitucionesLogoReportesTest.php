<?php

namespace Tests\Feature;

use App\Http\Controllers\InstitucionesController;
use App\Models\Usuarioslcchs;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InstitucionesLogoReportesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Schema::create('instituciones', function (Blueprint $table) {
            $table->increments('id');
            $table->string('Nombre')->nullable();
            $table->json('LogoBoletin2026')->nullable();
            $table->json('LogoCentralizador2026')->nullable();
            $table->timestamps();
        });
    }

    public function test_guarda_y_recupera_ajustes_independientes_por_institucion(): void
    {
        DB::table('instituciones')->insert([
            ['id' => 1, 'Nombre' => 'Una'],
            ['id' => 2, 'Nombre' => 'Otra'],
        ]);
        $controller = new InstitucionesController();
        $request = Request::create('/', 'PUT', [
            'id' => 1,
            'LogoBoletin2026' => ['ancho' => -5, 'alto' => 2.5, 'x' => 4, 'y' => -3],
            'LogoCentralizador2026' => ['ancho' => 6, 'alto' => 0, 'x' => -7, 'y' => 8],
        ]);
        $request->setUserResolver(fn () => new Usuarioslcchs(['Tipo' => 'CREADOR']));
        $controller->update($request, 1);

        $primera = $controller->show(1)->getData()->data;
        $segunda = $controller->show(2)->getData()->data;
        $this->assertEquals(['ancho' => -5, 'alto' => 2.5, 'x' => 4, 'y' => -3], (array) $primera->LogoBoletin2026);
        $this->assertEquals(['ancho' => 6, 'alto' => 0, 'x' => -7, 'y' => 8], (array) $primera->LogoCentralizador2026);
        $this->assertNull($segunda->LogoBoletin2026);
        $this->assertNull($segunda->LogoCentralizador2026);
    }

    public function test_rechaza_configuracion_no_numerica(): void
    {
        $this->expectException(ValidationException::class);
        (new InstitucionesController())->store(Request::create('/', 'POST', [
            'Nombre' => 'Una', 'LogoBoletin2026' => ['ancho' => 'texto'],
        ]));
    }

    public function test_acepta_valores_vacios_y_nulos_en_los_ajustes(): void
    {
        DB::table('instituciones')->insert(['id' => 1, 'Nombre' => 'Una']);
        $controller = new InstitucionesController();
        $request = Request::create('/', 'PUT', [
            'id' => 1,
            'LogoBoletin2026' => ['ancho' => null, 'alto' => '', 'x' => '', 'y' => null],
        ]);
        $request->setUserResolver(fn () => new Usuarioslcchs(['Tipo' => 'CREADOR']));
        $controller->update($request, 1);
        $ajuste = (array) $controller->show(1)->getData()->data->LogoBoletin2026;
        $this->assertSame('', $ajuste['x']);
        $this->assertNull($ajuste['y']);
    }
}
