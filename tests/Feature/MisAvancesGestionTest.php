<?php

namespace Tests\Feature;

use App\Http\Controllers\SesionAvanceEstudiantilController;
use App\Models\Estudiantesifas;
use App\Models\Planteldocentes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MisAvancesGestionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Schema::create('infoestudiantesifas', function (Blueprint $t) {
            $t->integer('id'); $t->integer('estudiantesifas_id'); $t->integer('instituciones_id');
        });
        Schema::create('instituciones', function (Blueprint $t) {
            $t->integer('id'); $t->string('Nombre'); $t->string('Logo')->nullable(); $t->string('ColorBajo')->nullable();
        });
        Schema::create('anios', function (Blueprint $t) { $t->integer('id'); $t->string('Anio'); });
        Schema::create('plandeestudios', function (Blueprint $t) { $t->integer('id'); $t->integer('anio_id'); $t->string('ModoMateria'); });
        Schema::create('materias', function (Blueprint $t) { $t->integer('id'); $t->integer('plandeestudios_id'); });
        Schema::create('calificaciones', function (Blueprint $t) { $t->integer('infoestudiantesifas_id'); $t->integer('materias_id'); });
        Schema::create('sesiones_avance_estudiantil', function (Blueprint $t) {
            $t->integer('id'); $t->integer('infoestudiantesifas_id'); $t->integer('planteldocentes_id'); $t->string('tipo_asignacion');
            $t->integer('evaluacion'); $t->date('fecha'); $t->integer('estrellas')->nullable();
        });
        DB::table('instituciones')->insert([['id' => 1, 'Nombre' => 'Uno'], ['id' => 2, 'Nombre' => 'Dos']]);
        DB::table('infoestudiantesifas')->insert([
            ['id' => 1, 'estudiantesifas_id' => 1, 'instituciones_id' => 1],
            ['id' => 2, 'estudiantesifas_id' => 1, 'instituciones_id' => 2],
            ['id' => 3, 'estudiantesifas_id' => 2, 'instituciones_id' => 1],
            ['id' => 4, 'estudiantesifas_id' => 1, 'instituciones_id' => 1],
        ]);
        DB::table('anios')->insert([['id' => 7, 'Anio' => 'Gestión anterior'], ['id' => 8, 'Anio' => 'Gestión actual']]);
        DB::table('plandeestudios')->insert([
            ['id' => 1, 'anio_id' => 7, 'ModoMateria' => 'MODO INSTRUMENTOS DE ESPECIALIDAD'],
            ['id' => 2, 'anio_id' => 8, 'ModoMateria' => 'MODO INSTRUMENTOS DE ESPECIALIDAD'],
            ['id' => 3, 'anio_id' => 8, 'ModoMateria' => 'MODO PRÁCTICA DE CONJUNTO'],
            ['id' => 4, 'anio_id' => 8, 'ModoMateria' => 'MODO INSTRUMENTO COMPLEMENTARIO'],
        ]);
        foreach ([1, 2, 3, 4, 5] as $id) {
            DB::table('materias')->insert(['id' => $id, 'plandeestudios_id' => $id === 5 ? 2 : $id]);
        }
        DB::table('calificaciones')->insert([
            ['infoestudiantesifas_id' => 1, 'materias_id' => 1],
            ['infoestudiantesifas_id' => 2, 'materias_id' => 2],
            ['infoestudiantesifas_id' => 2, 'materias_id' => 5],
            ['infoestudiantesifas_id' => 2, 'materias_id' => 3],
            ['infoestudiantesifas_id' => 2, 'materias_id' => 4],
            ['infoestudiantesifas_id' => 3, 'materias_id' => 2],
        ]);
        DB::table('sesiones_avance_estudiantil')->insert([
            ['id' => 1, 'infoestudiantesifas_id' => 1, 'planteldocentes_id' => 1, 'tipo_asignacion' => 'ESPECIALIDAD', 'evaluacion' => 1, 'fecha' => '2027-01-01', 'estrellas' => 1],
            ['id' => 2, 'infoestudiantesifas_id' => 1, 'planteldocentes_id' => 1, 'tipo_asignacion' => 'ESPECIALIDAD', 'evaluacion' => 1, 'fecha' => '2026-01-01', 'estrellas' => 5],
            ['id' => 3, 'infoestudiantesifas_id' => 2, 'planteldocentes_id' => 1, 'tipo_asignacion' => 'ESPECIALIDAD', 'evaluacion' => 2, 'fecha' => '2024-12-31', 'estrellas' => 3],
            ['id' => 4, 'infoestudiantesifas_id' => 2, 'planteldocentes_id' => 1, 'tipo_asignacion' => 'PRACTICA_CONJUNTOS', 'evaluacion' => 1, 'fecha' => '2026-04-01', 'estrellas' => 4],
            ['id' => 5, 'infoestudiantesifas_id' => 3, 'planteldocentes_id' => 1, 'tipo_asignacion' => 'ESPECIALIDAD', 'evaluacion' => 1, 'fecha' => '2026-03-01', 'estrellas' => 5],
            ['id' => 6, 'infoestudiantesifas_id' => 4, 'planteldocentes_id' => 1, 'tipo_asignacion' => 'ESPECIALIDAD', 'evaluacion' => 1, 'fecha' => '2026-01-01', 'estrellas' => 2],
            ['id' => 7, 'infoestudiantesifas_id' => 2, 'planteldocentes_id' => 1, 'tipo_asignacion' => 'ESPECIALIDAD', 'evaluacion' => 1, 'fecha' => '2025-01-01', 'estrellas' => 2],
            ['id' => 8, 'infoestudiantesifas_id' => 2, 'planteldocentes_id' => 1, 'tipo_asignacion' => 'COMPLEMENTARIO', 'evaluacion' => 1, 'fecha' => '2025-01-01', 'estrellas' => 5],
        ]);
    }

    private function consultar(array $params): array
    {
        $request = Request::create('/?' . http_build_query($params));
        $estudiante = new Estudiantesifas();
        $estudiante->id = 1;
        $request->setUserResolver(fn () => $estudiante);
        return (new SesionAvanceEstudiantilController())->misSesionesEstudiante($request)->getData(true);
    }

    public function test_filtra_por_gestion_de_materia_independientemente_de_fecha_antes_de_paginar(): void
    {
        $params = ['anios_id' => 8, 'tipo_asignacion' => 'ESPECIALIDAD'];
        $sesiones = $this->consultar($params)['data'];
        $this->assertSame([7, 3], array_column($sesiones, 'id'));
        $this->assertSame(['Gestión actual', 'Gestión actual'], array_column($sesiones, 'gestion_nombre'));
        $pagina = $this->consultar($params + ['per_page' => 1]);
        $this->assertSame(2, $pagina['total']);
        $this->assertSame([7], array_column($pagina['data'], 'id'));
        $this->assertSame([7], array_column($this->consultar($params + ['evaluacion' => 1])['data'], 'id'));
        $this->assertSame([1, 2], array_column($this->consultar(['anios_id' => 7, 'tipo_asignacion' => 'ESPECIALIDAD'])['data'], 'id'));
        $this->assertSame([4], array_column($this->consultar(['anios_id' => 8, 'tipo_asignacion' => 'PRACTICA_CONJUNTOS'])['data'], 'id'));
        $this->assertSame([8], array_column($this->consultar(['anios_id' => 8, 'tipo_asignacion' => 'COMPLEMENTARIO'])['data'], 'id'));
        $this->assertSame([], $this->consultar(['anios_id' => 99])['data']);
        $sinFiltro = $this->consultar([])['data'];
        $this->assertCount(7, $sinFiltro);
        $sinAsignacion = array_values(array_filter($sinFiltro, fn ($s) => $s['id'] === 6))[0];
        $this->assertNull($sinAsignacion['gestion_id']);
    }

    public function test_el_docente_ve_la_gestion_asociada_a_la_inscripcion(): void
    {
        $docente = new Planteldocentes();
        $docente->id = 1;
        $request = Request::create('/?infoestudiantesifas_id=1&tipo_asignacion=ESPECIALIDAD&anios_id=7');
        $request->setUserResolver(fn () => $docente);
        $sesiones = (new SesionAvanceEstudiantilController())->index($request)->getData(true)['data'];
        $this->assertSame([1, 2], array_column($sesiones, 'id'));
        $this->assertSame(['Gestión anterior', 'Gestión anterior'], array_column($sesiones, 'gestion_nombre'));
    }

    public function test_rechaza_gestion_ambigua_en_lugar_de_atribuir_sesiones_incorrectamente(): void
    {
        DB::table('calificaciones')->insert(['infoestudiantesifas_id' => 1, 'materias_id' => 2]);
        $this->expectException(ValidationException::class);
        $this->consultar(['anios_id' => 7, 'tipo_asignacion' => 'ESPECIALIDAD']);
    }

    public function test_rechaza_una_gestion_invalida(): void
    {
        $this->expectException(ValidationException::class);
        $this->consultar(['anios_id' => '2025-2026']);
    }
}
