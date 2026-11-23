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
            $table->string('InstrumentoMusicalSecundario')->nullable();
            $table->integer('planteldocadmins_id')->nullable(); $table->integer('planteldocadmins_idPC')->nullable();
            $table->integer('planteldocadmins_idOtros')->nullable();
        });
        Schema::create('carreras', function (Blueprint $table) {
            $table->integer('id'); $table->integer('instituciones_id');
            $table->string('NombreCarrera'); $table->string('Capacitacion')->nullable();
            $table->string('Area')->nullable(); $table->string('Resolucion')->nullable();
        });
        Schema::create('plandeestudios', function (Blueprint $table) {
            $table->integer('id'); $table->integer('carreras_id'); $table->integer('anio_id');
            $table->string('LvlCurso'); $table->string('NombreMateria'); $table->string('SiglaMateria');
            $table->string('TipoMateria')->nullable(); $table->string('ModoMateria')->nullable();
            $table->integer('Rango'); $table->integer('RangoLvlCurso');
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

        DB::table('planteldocentes')->insert([
            ['id' => 1, 'Nombres' => 'Especialidad Piano', 'Apellidos' => 'Docente'],
            ['id' => 2, 'Nombres' => 'Conjunto Piano', 'Apellidos' => 'Docente'],
            ['id' => 3, 'Nombres' => 'Complementario Piano', 'Apellidos' => 'Docente'],
            ['id' => 4, 'Nombres' => 'Especialidad Violin', 'Apellidos' => 'Docente'],
            ['id' => 5, 'Nombres' => 'Conjunto Violin', 'Apellidos' => 'Docente'],
            ['id' => 6, 'Nombres' => 'Complementario Violin', 'Apellidos' => 'Docente'],
        ]);
        DB::table('infoestudiantesifas')->update([
            'InstrumentoMusical' => 'Piano',
            'InstrumentoMusicalSecundario' => 'Flauta',
            'planteldocadmins_id' => 1,
            'planteldocadmins_idPC' => 2,
            'planteldocadmins_idOtros' => 3,
        ]);
        DB::table('estudiantesifas')->insert([
            'id' => 2, 'Ap_Paterno' => 'Lopez', 'Ap_Materno' => '', 'Nombre' => 'Eva', 'CI' => '456', 'Sexo' => 'F',
        ]);
        DB::table('infoestudiantesifas')->insert([
            'id' => 2, 'estudiantesifas_id' => 2, 'instituciones_id' => 1, 'InstrumentoMusical' => 'Violin',
            'InstrumentoMusicalSecundario' => 'Piano',
            'planteldocadmins_id' => 4, 'planteldocadmins_idPC' => 5, 'planteldocadmins_idOtros' => 6,
        ]);

        $modosEspeciales = [
            [3, 'INSTRUMENTO DE ESPECIALIDAD', 'MODO INSTRUMENTOS DE ESPECIALIDAD'],
            [4, 'PRÁCTICA DE CONJUNTOS', 'MODO PRÁCTICA DE CONJUNTOS'],
            [5, 'INSTRUMENTO COMPLEMENTARIO', 'MODO INSTRUMENTO COMPLEMENTARIO'],
        ];
        foreach ($modosEspeciales as [$id, $nombre, $modo]) {
            DB::table('plandeestudios')->insert([
                'id' => $id, 'carreras_id' => 1, 'anio_id' => 2026, 'LvlCurso' => 'Primero',
                'NombreMateria' => $nombre, 'SiglaMateria' => "M$id", 'TipoMateria' => 'PRÁCTICO',
                'ModoMateria' => $modo, 'Rango' => $id, 'RangoLvlCurso' => 1,
            ]);
            DB::table('materias')->insert(['id' => $id, 'plandeestudios_id' => $id, 'Paralelo' => 'A', 'Turno' => 'M']);
            DB::table('calificaciones')->insert([
                ['id' => $id * 10, 'infoestudiantesifas_id' => 1, 'materias_id' => $id, 'Promedio' => 80],
                ['id' => $id * 10 + 1, 'infoestudiantesifas_id' => 2, 'materias_id' => $id, 'Promedio' => 80],
            ]);
        }

        $result = (new PublicHorariosController())->centralizadorGeneral($request)->getData(true);
        $firmasPorModo = array_map(
            fn ($firma) => implode('|', [$firma['ModoMateria'], $firma['Especialidad'], $firma['InstrumentoSecundario'], $firma['Docente']]),
            $result['meta']['firmas_docentes']
        );
        $this->assertEqualsCanonicalizing([
            'MODO INSTRUMENTOS DE ESPECIALIDAD|Piano|Flauta|Especialidad Piano Docente',
            'MODO INSTRUMENTOS DE ESPECIALIDAD|Violin|Piano|Especialidad Violin Docente',
            'MODO PRÁCTICA DE CONJUNTOS|Piano|Flauta|Conjunto Piano Docente',
            'MODO PRÁCTICA DE CONJUNTOS|Violin|Piano|Conjunto Violin Docente',
            'MODO INSTRUMENTO COMPLEMENTARIO|Piano|Flauta|Complementario Piano Docente',
            'MODO INSTRUMENTO COMPLEMENTARIO|Violin|Piano|Complementario Violin Docente',
        ], $firmasPorModo);
        $this->assertEqualsCanonicalizing(['M3', 'M3', 'M4', 'M4', 'M5', 'M5'], array_column($result['meta']['firmas_docentes'], 'SiglaMateria'));

        DB::table('planteldocentes')->insert(['id' => 7, 'Nombres' => 'Otro Piano', 'Apellidos' => 'Docente']);
        DB::table('estudiantesifas')->insert([
            ['id' => 3, 'Ap_Paterno' => 'Rojas', 'Ap_Materno' => '', 'Nombre' => 'Luis', 'CI' => '789', 'Sexo' => 'M'],
            ['id' => 4, 'Ap_Paterno' => 'Diaz', 'Ap_Materno' => '', 'Nombre' => 'Juan', 'CI' => '987', 'Sexo' => 'M'],
        ]);
        DB::table('infoestudiantesifas')->insert([
            ['id' => 3, 'estudiantesifas_id' => 3, 'instituciones_id' => 1, 'InstrumentoMusical' => 'Piano',
                'InstrumentoMusicalSecundario' => 'Guitarra', 'planteldocadmins_id' => 1,
                'planteldocadmins_idPC' => 2, 'planteldocadmins_idOtros' => 3],
            ['id' => 4, 'estudiantesifas_id' => 4, 'instituciones_id' => 1, 'InstrumentoMusical' => 'Piano',
                'InstrumentoMusicalSecundario' => 'Flauta', 'planteldocadmins_id' => 7,
                'planteldocadmins_idPC' => 2, 'planteldocadmins_idOtros' => 3],
        ]);
        foreach ([3, 4, 5] as $materiaId) {
            foreach ([3, 4] as $estudianteId) {
                DB::table('calificaciones')->insert([
                    'id' => $materiaId * 10 + $estudianteId - 1, 'infoestudiantesifas_id' => $estudianteId,
                    'materias_id' => $materiaId, 'Promedio' => 80,
                ]);
            }
        }

        $result = (new PublicHorariosController())->centralizadorGeneral($request)->getData(true);
        $firmas = $result['meta']['firmas_docentes'];
        $porModo = fn ($modo) => array_values(array_filter($firmas, fn ($firma) => $firma['ModoMateria'] === $modo));
        $this->assertEqualsCanonicalizing(
            ['Especialidad Piano Docente', 'Especialidad Violin Docente', 'Otro Piano Docente'],
            array_column($porModo('MODO INSTRUMENTOS DE ESPECIALIDAD'), 'Docente')
        );
        $this->assertEqualsCanonicalizing(
            ['Conjunto Piano Docente', 'Conjunto Violin Docente'],
            array_column($porModo('MODO PRÁCTICA DE CONJUNTOS'), 'Docente')
        );
        $this->assertEqualsCanonicalizing(
            ['Piano|Flauta', 'Piano|Guitarra', 'Violin|Piano'],
            array_map(fn ($firma) => $firma['Especialidad'] . '|' . $firma['InstrumentoSecundario'], $porModo('MODO INSTRUMENTO COMPLEMENTARIO'))
        );

        DB::table('plandeestudios')->where('id', 2)->update(['NombreMateria' => 'Materia 1']);
        DB::table('planteldocentesmaterias')->insert([
            ['planteldocentes_id' => 1, 'materias_id' => 1],
            ['planteldocentes_id' => 1, 'materias_id' => 2],
        ]);
        $result = (new PublicHorariosController())->centralizadorGeneral($request)->getData(true);
        $firmasComunes = array_filter($result['meta']['firmas_docentes'], fn ($firma) => $firma['NombreMateria'] === 'Materia 1');
        $this->assertEqualsCanonicalizing(['M1', 'M2'], array_column($firmasComunes, 'SiglaMateria'));
    }
}
