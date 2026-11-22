<?php

namespace Tests\Feature;

use App\Http\Controllers\SesionAvanceEstudiantilController;
use App\Models\Planteladministrativos;
use App\Models\Planteldocentes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CalificacionesGrupalesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Schema::create('anios', function (Blueprint $t) { $t->integer('id'); $t->string('Anio'); $t->string('Predeterminado'); });
        Schema::create('instituciones', function (Blueprint $t) {
            $t->integer('id'); $t->string('Nombre'); $t->string('Logo')->nullable(); $t->string('ColorBajo')->nullable();
        });
        Schema::create('planteldocentes', function (Blueprint $t) { $t->integer('id'); $t->integer('instituciones_id'); });
        Schema::create('estudiantesifas', function (Blueprint $t) {
            $t->integer('id'); $t->string('Ap_Paterno'); $t->string('Ap_Materno');
            $t->string('Nombre'); $t->string('CI'); $t->string('Foto')->nullable();
        });
        Schema::create('infoestudiantesifas', function (Blueprint $t) {
            $t->integer('id'); $t->integer('estudiantesifas_id'); $t->integer('instituciones_id');
            $t->integer('planteldocadmins_id'); $t->integer('planteldocadmins_idPC')->nullable(); $t->integer('planteldocadmins_idOtros')->nullable();
            $t->string('Curso_Solicitado')->nullable(); $t->string('Paralelo_Solicitado')->nullable(); $t->string('Turno')->nullable();
            $t->string('InstrumentoMusical')->nullable(); $t->string('InstrumentoMusicalSecundario')->nullable();
        });
        Schema::create('carreras', function (Blueprint $t) {
            $t->integer('id'); $t->integer('instituciones_id'); $t->string('NombreCarrera'); $t->string('CantidadEvaluaciones');
        });
        Schema::create('plandeestudios', function (Blueprint $t) {
            $t->integer('id'); $t->integer('anio_id'); $t->integer('carreras_id'); $t->string('ModoMateria');
            $t->string('NombreMateria'); $t->string('SiglaMateria'); $t->string('LvlCurso');
            $t->integer('RangoLvlCurso'); $t->integer('Rango');
        });
        Schema::create('materias', function (Blueprint $t) { $t->integer('id'); $t->integer('plandeestudios_id'); $t->string('Paralelo'); });
        Schema::create('calificaciones', function (Blueprint $t) {
            $t->integer('id'); $t->integer('infoestudiantesifas_id'); $t->integer('materias_id');
            $t->integer('Teorico1')->nullable(); $t->integer('Practico1')->nullable();
            $t->integer('Promedio')->nullable(); $t->integer('PruebaRecuperacion')->nullable();
        });
        DB::table('anios')->insert([
            ['id' => 2025, 'Anio' => '2025', 'Predeterminado' => 'NO'],
            ['id' => 2026, 'Anio' => '2026', 'Predeterminado' => 'PREDETERMINADO'],
        ]);
        DB::table('instituciones')->insert([['id' => 1, 'Nombre' => 'Uno'], ['id' => 2, 'Nombre' => 'Dos']]);
        DB::table('planteldocentes')->insert([
            ['id' => 1, 'instituciones_id' => 1], ['id' => 2, 'instituciones_id' => 1], ['id' => 3, 'instituciones_id' => 2],
        ]);
        DB::table('carreras')->insert([
            ['id' => 1, 'instituciones_id' => 1, 'NombreCarrera' => 'Música', 'CantidadEvaluaciones' => 'DOS EVALUACIONES'],
            ['id' => 2, 'instituciones_id' => 2, 'NombreCarrera' => 'Otra', 'CantidadEvaluaciones' => 'DOS EVALUACIONES'],
        ]);
        foreach ([
            [10, 2026, 1, 'MODO INSTRUMENTOS DE ESPECIALIDAD', 'Piano'],
            [11, 2026, 1, 'MODO REGULAR', 'Teoría'],
            [12, 2026, 1, 'MODO REGULAR', 'Coro'],
            [13, 2025, 1, 'MODO INSTRUMENTOS DE ESPECIALIDAD', 'Piano antiguo'],
            [14, 2026, 2, 'MODO REGULAR', 'Otra institución'],
        ] as [$id, $anio, $carrera, $modo, $nombre]) {
            DB::table('plandeestudios')->insert([
                'id' => $id, 'anio_id' => $anio, 'carreras_id' => $carrera, 'ModoMateria' => $modo,
                'NombreMateria' => $nombre, 'SiglaMateria' => "M$id", 'LvlCurso' => 'Primero', 'RangoLvlCurso' => 1, 'Rango' => $id,
            ]);
            DB::table('materias')->insert(['id' => $id, 'plandeestudios_id' => $id, 'Paralelo' => 'A']);
        }
        foreach ([1, 2, 3, 4, 5] as $id) {
            DB::table('estudiantesifas')->insert(['id' => $id, 'Ap_Paterno' => "Apellido $id", 'Ap_Materno' => '', 'Nombre' => "Nombre $id", 'CI' => "$id"]);
            DB::table('infoestudiantesifas')->insert([
                'id' => $id, 'estudiantesifas_id' => $id, 'instituciones_id' => $id === 4 ? 2 : 1,
                'planteldocadmins_id' => $id === 3 ? 2 : ($id === 4 ? 3 : 1),
            ]);
        }
        foreach ([[1, 1, 10, 0], [2, 1, 11, 71], [3, 2, 10, 80], [4, 2, 12, 75], [5, 1, 13, 60],
                  [6, 3, 10, 90], [7, 4, 10, 90], [8, 5, 13, 60], [9, 1, 14, 90]] as [$id, $info, $materia, $nota]) {
            DB::table('calificaciones')->insert([
                'id' => $id, 'infoestudiantesifas_id' => $info, 'materias_id' => $materia,
                'Teorico1' => 0, 'Practico1' => $nota, 'Promedio' => $nota,
            ]);
        }
    }

    private function consultar($user, array $ids, int $anio = 2026, ?int $docenteId = null)
    {
        $query = http_build_query(['tipo_asignacion' => 'ESPECIALIDAD', 'anios_id' => $anio, 'planteldocentes_id' => $docenteId]);
        $request = Request::create('/?' . $query, 'POST', ['info_ids' => $ids]);
        $request->setUserResolver(fn () => $user);
        return (new SesionAvanceEstudiantilController())->calificacionesGrupales($request);
    }

    private function docente(): Planteldocentes
    {
        $docente = new Planteldocentes();
        $docente->id = 1;
        $docente->instituciones_id = 1;
        return $docente;
    }

    public function test_devuelve_las_materias_y_notas_de_cada_estudiante_solo_en_la_gestion_seleccionada(): void
    {
        $response = $this->consultar($this->docente(), [2, 1]);
        $this->assertSame(200, $response->getStatusCode());
        $data = $response->getData(true)['data'];
        $this->assertSame([2, 1], array_column($data, 'infoestudiantesifas_id'));
        $this->assertSame([10, 12], array_column($data[0]['materias'], 'materia_id'));
        $this->assertSame([10, 11], array_column($data[1]['materias'], 'materia_id'));
        $this->assertSame(0, $data[1]['materias'][0]['calificacion']['Promedio']);
        $this->assertSame([13], array_column($this->consultar($this->docente(), [1], 2025)->getData(true)['data'][0]['materias'], 'materia_id'));
    }

    public function test_rechaza_estudiantes_ajenos_y_administradores_de_otra_institucion(): void
    {
        foreach ([[1, 3], [1, 4], [1, 5]] as $ids) {
            $this->assertSame(403, $this->consultar($this->docente(), $ids)->getStatusCode());
        }
        $admin = new Planteladministrativos();
        $admin->instituciones_id = 1;
        $this->assertSame(200, $this->consultar($admin, [1, 2], 2026, 1)->getStatusCode());
        $admin->instituciones_id = 2;
        $this->assertSame(403, $this->consultar($admin, [1, 2], 2026, 1)->getStatusCode());
        $admin->instituciones_id = null;
        $this->assertSame(403, $this->consultar($admin, [1], 2026, 1)->getStatusCode());
        $this->assertSame(403, $this->consultar((object) ['id' => 1], [1])->getStatusCode());
    }
}
