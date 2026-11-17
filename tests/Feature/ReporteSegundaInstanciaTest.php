<?php

namespace Tests\Feature;

use App\Http\Controllers\CalificacionesController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReporteSegundaInstanciaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');

        Schema::create('estudiantesifas', function (Blueprint $table) {
            $table->integer('id'); $table->string('Ap_Paterno'); $table->string('Ap_Materno');
            $table->string('Nombre'); $table->string('CI');
        });
        Schema::create('infoestudiantesifas', function (Blueprint $table) {
            $table->integer('id'); $table->integer('estudiantesifas_id'); $table->integer('instituciones_id');
        });
        Schema::create('carreras', function (Blueprint $table) {
            $table->integer('id'); $table->integer('instituciones_id');
            $table->integer('NotaAprobacion')->nullable(); $table->integer('NotaMinRevalida')->nullable();
        });
        Schema::create('plandeestudios', function (Blueprint $table) {
            $table->integer('id'); $table->integer('carreras_id'); $table->integer('anio_id');
            $table->string('LvlCurso'); $table->string('NombreMateria'); $table->integer('Rango');
        });
        Schema::create('materias', function (Blueprint $table) {
            $table->integer('id'); $table->integer('plandeestudios_id'); $table->string('Paralelo');
        });
        Schema::create('calificaciones', function (Blueprint $table) {
            $table->integer('id'); $table->integer('infoestudiantesifas_id'); $table->integer('materias_id');
            foreach (['Teorico1', 'Teorico2', 'Teorico3', 'Teorico4', 'Practico1', 'Practico2', 'Practico3', 'Practico4', 'PromTeorico', 'PromPractico', 'Promedio', 'PruebaRecuperacion'] as $field) {
                $table->integer($field)->nullable();
            }
        });
    }

    public function test_returns_all_assigned_subjects_in_same_year_and_institution_for_selected_students(): void
    {
        DB::table('estudiantesifas')->insert(['id' => 1, 'Ap_Paterno' => 'Perez', 'Ap_Materno' => '', 'Nombre' => 'Ana', 'CI' => '123']);
        DB::table('infoestudiantesifas')->insert(['id' => 1, 'estudiantesifas_id' => 1, 'instituciones_id' => 1]);
        DB::table('carreras')->insert([
            ['id' => 1, 'instituciones_id' => 1, 'NotaAprobacion' => 61, 'NotaMinRevalida' => 40],
            ['id' => 2, 'instituciones_id' => 2, 'NotaAprobacion' => 61, 'NotaMinRevalida' => 40],
        ]);
        foreach ([[1, 1, 2026, 'Primero'], [2, 1, 2026, 'Segundo'], [3, 1, 2025, 'Primero'], [4, 2, 2026, 'Primero']] as [$id, $carrera, $anio, $curso]) {
            DB::table('plandeestudios')->insert(['id' => $id, 'carreras_id' => $carrera, 'anio_id' => $anio, 'LvlCurso' => $curso, 'NombreMateria' => "Materia $id", 'Rango' => $id]);
            DB::table('materias')->insert(['id' => $id, 'plandeestudios_id' => $id, 'Paralelo' => 'A']);
            DB::table('calificaciones')->insert(['id' => $id, 'infoestudiantesifas_id' => 1, 'materias_id' => $id, 'Promedio' => 50]);
        }
        $request = Request::create('/', 'POST', ['anio_id' => 2026, 'institucion_id' => 1, 'lvl_curso' => 'Primero', 'paralelo' => 'A']);
        $request->setUserResolver(fn () => (object) ['instituciones_id' => 1]);
        $result = (new CalificacionesController())->reporteSegundaInstancia($request)->getData(true);
        $this->assertSame([1, 2], array_column($result['data'], 'materias_id'));
    }

    public function test_rejects_another_institution(): void
    {
        $request = Request::create('/', 'POST', ['anio_id' => 2026, 'institucion_id' => 2, 'lvl_curso' => 'Primero', 'paralelo' => 'A']);
        $request->setUserResolver(fn () => (object) ['instituciones_id' => 1]);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new CalificacionesController())->reporteSegundaInstancia($request);
    }

    public function test_materias_para_boletines_respeta_gestion_institucion_y_modo_del_docente(): void
    {
        Schema::table('plandeestudios', function (Blueprint $table) { $table->string('ModoMateria')->nullable(); });
        Schema::table('infoestudiantesifas', function (Blueprint $table) {
            $table->integer('planteldocadmins_id')->nullable(); $table->integer('planteldocadmins_idPC')->nullable();
            $table->integer('planteldocadmins_idOtros')->nullable();
        });
        Schema::create('planteldocentes', function (Blueprint $table) {
            $table->integer('id'); $table->integer('instituciones_id');
        });
        Schema::create('planteldocentesmaterias', function (Blueprint $table) {
            $table->integer('planteldocentes_id'); $table->integer('materias_id');
        });
        DB::table('planteldocentes')->insert(['id' => 10, 'instituciones_id' => 1]);
        DB::table('carreras')->insert([['id' => 1, 'instituciones_id' => 1], ['id' => 2, 'instituciones_id' => 2]]);
        DB::table('infoestudiantesifas')->insert([
            ['id' => 1, 'estudiantesifas_id' => 1, 'instituciones_id' => 1, 'planteldocadmins_id' => 10, 'planteldocadmins_idPC' => 10, 'planteldocadmins_idOtros' => 10],
            ['id' => 2, 'estudiantesifas_id' => 2, 'instituciones_id' => 1, 'planteldocadmins_id' => 10, 'planteldocadmins_idPC' => null, 'planteldocadmins_idOtros' => null],
            ['id' => 3, 'estudiantesifas_id' => 3, 'instituciones_id' => 2, 'planteldocadmins_id' => 10, 'planteldocadmins_idPC' => 10, 'planteldocadmins_idOtros' => 10],
        ]);
        $modos = [
            [1, '1 DOCENTE x MATERIA', 1, 2026],
            [2, 'MODO INSTRUMENTOS DE ESPECIALIDAD', 1, 2026],
            [3, 'MODO PRÁCTICA DE CONJUNTOS', 1, 2026],
            [4, 'MODO INSTRUMENTO COMPLEMENTARIO', 1, 2026],
            [5, 'MODO PRÁCTICA DE CONJUNTOS', 1, 2026],
            [6, 'MODO INSTRUMENTOS DE ESPECIALIDAD', 1, 2025],
            [7, 'MODO INSTRUMENTOS DE ESPECIALIDAD', 2, 2026],
            [8, 'MODO INSTRUMENTO COMPLEMENTARIO', 1, 2026],
        ];
        foreach ($modos as [$id, $modo, $carrera, $anio]) {
            DB::table('plandeestudios')->insert(['id' => $id, 'carreras_id' => $carrera, 'anio_id' => $anio, 'LvlCurso' => 'Primero', 'NombreMateria' => "Materia $id", 'Rango' => $id, 'ModoMateria' => $modo]);
            DB::table('materias')->insert(['id' => $id, 'plandeestudios_id' => $id, 'Paralelo' => 'A']);
            DB::table('calificaciones')->insert(['id' => $id, 'infoestudiantesifas_id' => $id === 5 ? 2 : ($id === 8 ? 3 : 1), 'materias_id' => $id]);
        }
        DB::table('planteldocentesmaterias')->insert(['planteldocentes_id' => 10, 'materias_id' => 1]);
        $request = Request::create('/', 'GET', ['anio_id' => 2026, 'institucion_id' => 1, 'docente_id' => 10]);
        $request->setUserResolver(fn () => (object) ['instituciones_id' => 1]);
        $result = (new CalificacionesController())->materiasParaBoletinesDocente($request)->getData(true);
        $this->assertEqualsCanonicalizing([1, 2, 3, 4], $result['data']);

        $request = Request::create('/', 'GET', ['anio_id' => 2026, 'institucion_id' => 2, 'docente_id' => 10]);
        $request->setUserResolver(fn () => (object) ['instituciones_id' => 1]);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new CalificacionesController())->materiasParaBoletinesDocente($request);
    }

    public function test_by_materia_includes_teachers_assigned_both_to_subject_and_students(): void
    {
        Schema::table('estudiantesifas', function (Blueprint $table) { $table->string('Foto')->nullable(); });
        Schema::table('infoestudiantesifas', function (Blueprint $table) {
            $table->string('InstrumentoMusical')->nullable(); $table->string('InstrumentoMusicalSecundario')->nullable();
            $table->integer('planteldocadmins_id')->nullable(); $table->integer('planteldocadmins_idPC')->nullable();
            $table->integer('planteldocadmins_idOtros')->nullable();
        });
        Schema::table('carreras', function (Blueprint $table) {
            $table->integer('CantidadEvaluaciones')->nullable(); $table->integer('LimiteMaxTeorico')->nullable();
            $table->integer('LimiteMaxPractico')->nullable(); $table->string('NombreCarrera')->nullable();
            $table->string('Area')->nullable(); $table->string('Resolucion')->nullable(); $table->string('Nivel')->nullable();
        });
        Schema::table('plandeestudios', function (Blueprint $table) {
            $table->string('SiglaMateria')->nullable(); $table->string('ModoMateria')->nullable();
        });
        Schema::table('materias', function (Blueprint $table) {
            $table->string('Turno')->nullable(); $table->integer('EstadoHabilitacion')->nullable();
        });
        Schema::create('anios', function (Blueprint $table) {
            $table->integer('id'); $table->string('Anio'); $table->integer('EdicionCalificaciones')->nullable();
        });
        Schema::create('planteldocentes', function (Blueprint $table) {
            $table->integer('id'); $table->integer('instituciones_id');
            $table->string('Nombres'); $table->string('Apellidos');
        });
        Schema::create('planteldocentesmaterias', function (Blueprint $table) {
            $table->integer('planteldocentes_id'); $table->integer('materias_id');
        });

        DB::table('estudiantesifas')->insert(['id' => 1, 'Ap_Paterno' => 'Perez', 'Ap_Materno' => '', 'Nombre' => 'Ana', 'CI' => '123']);
        DB::table('infoestudiantesifas')->insert(['id' => 1, 'estudiantesifas_id' => 1, 'instituciones_id' => 1, 'planteldocadmins_id' => 2]);
        DB::table('carreras')->insert(['id' => 1, 'instituciones_id' => 1]);
        DB::table('plandeestudios')->insert(['id' => 1, 'carreras_id' => 1, 'anio_id' => 1, 'LvlCurso' => 'Primero', 'NombreMateria' => 'Instrumento', 'Rango' => 1, 'ModoMateria' => 'MODO INSTRUMENTOS DE ESPECIALIDAD']);
        DB::table('materias')->insert(['id' => 1, 'plandeestudios_id' => 1, 'Paralelo' => 'A']);
        DB::table('calificaciones')->insert(['id' => 1, 'infoestudiantesifas_id' => 1, 'materias_id' => 1]);
        DB::table('planteldocentes')->insert([
            ['id' => 1, 'instituciones_id' => 1, 'Nombres' => 'Uno', 'Apellidos' => 'Docente'],
            ['id' => 2, 'instituciones_id' => 1, 'Nombres' => 'Dos', 'Apellidos' => 'Docente'],
        ]);
        DB::table('planteldocentesmaterias')->insert(['planteldocentes_id' => 1, 'materias_id' => 1]);

        $request = Request::create('/', 'GET');
        $request->setUserResolver(fn () => (object) ['instituciones_id' => 1]);
        $result = (new CalificacionesController())->byMateria($request, 1)->getData(true);
        $this->assertSame([1, 2], array_column($result['docentes'], 'id'));
        $this->assertSame(2, $result['data'][0]['docente_id_especialidad']);
    }
}
