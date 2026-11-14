<?php

namespace Tests\Feature;

use App\Http\Controllers\PublicHorariosController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CentralizadorGeneralCapacitacionTest extends TestCase
{
    public function test_returns_each_subject_career_capacitacion(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        DB::connection()->getPdo()->sqliteCreateFunction('CONCAT', fn (...$parts) => implode('', $parts));

        Schema::create('estudiantesifas', function (Blueprint $table) {
            $table->integer('id'); $table->string('Ap_Paterno'); $table->string('Ap_Materno');
            $table->string('Nombre'); $table->string('CI'); $table->string('Sexo');
        });
        Schema::create('infoestudiantesifas', function (Blueprint $table) {
            $table->integer('id'); $table->integer('estudiantesifas_id'); $table->integer('instituciones_id');
            $table->string('Observacion')->nullable(); $table->string('InstrumentoMusical')->nullable();
        });
        Schema::create('carreras', function (Blueprint $table) {
            $table->integer('id'); $table->integer('instituciones_id');
            $table->string('NombreCarrera'); $table->string('Capacitacion')->nullable();
            $table->string('Area')->nullable(); $table->string('Resolucion')->nullable();
        });
        Schema::create('plandeestudios', function (Blueprint $table) {
            $table->integer('id'); $table->integer('carreras_id'); $table->integer('anio_id');
            $table->string('LvlCurso'); $table->string('NombreMateria'); $table->string('SiglaMateria');
            $table->string('TipoMateria')->nullable(); $table->integer('Rango'); $table->integer('RangoLvlCurso');
        });
        Schema::create('materias', function (Blueprint $table) {
            $table->integer('id'); $table->integer('plandeestudios_id'); $table->string('Paralelo'); $table->string('Turno');
        });
        Schema::create('calificaciones', function (Blueprint $table) {
            $table->integer('id'); $table->integer('infoestudiantesifas_id'); $table->integer('materias_id');
            $table->integer('Promedio'); $table->integer('PruebaRecuperacion')->nullable();
            $table->string('EstadoRegistroMateria')->nullable();
        });
        Schema::create('planteldocentes', function (Blueprint $table) {
            $table->integer('id'); $table->string('Nombres'); $table->string('Apellidos');
        });
        Schema::create('planteldocentesmaterias', function (Blueprint $table) {
            $table->integer('planteldocentes_id'); $table->integer('materias_id');
        });

        DB::table('estudiantesifas')->insert(['id' => 1, 'Ap_Paterno' => 'Perez', 'Ap_Materno' => '', 'Nombre' => 'Ana', 'CI' => '123', 'Sexo' => 'F']);
        DB::table('infoestudiantesifas')->insert(['id' => 1, 'estudiantesifas_id' => 1, 'instituciones_id' => 1]);
        DB::table('carreras')->insert([
            ['id' => 1, 'instituciones_id' => 1, 'NombreCarrera' => 'Primera', 'Capacitacion' => 'NO EXISTE REPROBADOS'],
            ['id' => 2, 'instituciones_id' => 1, 'NombreCarrera' => 'Segunda', 'Capacitacion' => null],
        ]);
        foreach ([1, 2] as $id) {
            DB::table('plandeestudios')->insert(['id' => $id, 'carreras_id' => $id, 'anio_id' => 2026,
                'LvlCurso' => 'Primero', 'NombreMateria' => "Materia $id", 'SiglaMateria' => "M$id",
                'Rango' => $id, 'RangoLvlCurso' => 1]);
            DB::table('materias')->insert(['id' => $id, 'plandeestudios_id' => $id, 'Paralelo' => 'A', 'Turno' => 'M']);
            DB::table('calificaciones')->insert(['id' => $id, 'infoestudiantesifas_id' => 1, 'materias_id' => $id, 'Promedio' => 50]);
        }

        $request = Request::create('/', 'POST', ['Anio_id' => 2026, 'LvlCurso' => 'Primero', 'Paralelo' => 'A', 'instituciones_id' => 1]);
        $result = (new PublicHorariosController())->centralizadorGeneral($request)->getData(true);
        $this->assertSame(['NO EXISTE REPROBADOS', null], array_column($result['data'], 'Capacitacion'));
        $this->assertSame([50, 50], array_column($result['data'], 'Promedio'));
    }
}
