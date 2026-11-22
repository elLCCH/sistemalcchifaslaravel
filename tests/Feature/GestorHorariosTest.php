<?php

namespace Tests\Feature;

use App\Http\Controllers\SesionAvanceEstudiantilController;
use App\Models\Planteldocentes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GestorHorariosTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Schema::create('anios', function (Blueprint $t) { $t->integer('id'); $t->string('Predeterminado'); });
        Schema::create('plandeestudios', function (Blueprint $t) { $t->integer('id'); $t->integer('anio_id'); $t->string('ModoMateria'); });
        Schema::create('materias', function (Blueprint $t) { $t->integer('id'); $t->integer('plandeestudios_id'); });
        Schema::create('calificaciones', function (Blueprint $t) { $t->integer('materias_id'); $t->integer('infoestudiantesifas_id'); });
        Schema::create('instituciones', function (Blueprint $t) {
            $t->integer('id'); $t->string('Nombre'); $t->string('Logo')->nullable(); $t->string('ColorBajo')->nullable();
        });
        Schema::create('estudiantesifas', function (Blueprint $t) {
            $t->integer('id'); $t->string('Ap_Paterno'); $t->string('Ap_Materno');
            $t->string('Nombre'); $t->string('CI'); $t->string('Foto')->nullable();
        });
        Schema::create('infoestudiantesifas', function (Blueprint $t) {
            $t->integer('id'); $t->integer('estudiantesifas_id'); $t->integer('instituciones_id');
            $t->integer('planteldocadmins_id'); $t->integer('planteldocadmins_idPC')->nullable();
            $t->integer('planteldocadmins_idOtros')->nullable(); $t->string('Turno')->nullable();
            $t->string('Curso_Solicitado')->nullable(); $t->string('Paralelo_Solicitado')->nullable();
            $t->string('InstrumentoMusical')->nullable(); $t->string('InstrumentoMusicalSecundario')->nullable();
        });
        Schema::create('horarios_estudiantes', function (Blueprint $t) {
            $t->integer('infoestudiantesifas_id'); $t->string('tipo_asignacion'); $t->integer('dia_semana');
            $t->string('hora_inicio')->nullable(); $t->string('hora_fin')->nullable(); $t->timestamps();
        });
        DB::table('anios')->insert(['id' => 2026, 'Predeterminado' => 'PREDETERMINADO']);
        DB::table('plandeestudios')->insert([
            ['id' => 1, 'anio_id' => 2026, 'ModoMateria' => 'MODO INSTRUMENTOS DE ESPECIALIDAD'],
            ['id' => 2, 'anio_id' => 2026, 'ModoMateria' => 'MODO PRÁCTICA DE CONJUNTOS'],
        ]);
        DB::table('materias')->insert([['id' => 1, 'plandeestudios_id' => 1], ['id' => 2, 'plandeestudios_id' => 2]]);
        DB::table('instituciones')->insert(['id' => 1, 'Nombre' => 'Institucion']);
        foreach ([1, 2, 3] as $id) {
            DB::table('estudiantesifas')->insert([
                'id' => $id, 'Ap_Paterno' => "Alumno $id", 'Ap_Materno' => '', 'Nombre' => '', 'CI' => "$id",
            ]);
            DB::table('infoestudiantesifas')->insert([
                'id' => $id, 'estudiantesifas_id' => $id, 'instituciones_id' => 1,
                'planteldocadmins_id' => $id === 3 ? 2 : 1, 'planteldocadmins_idPC' => 1,
                'Curso_Solicitado' => $id === 1 ? 'Segundo' : 'Primero',
            ]);
            DB::table('calificaciones')->insert([
                ['materias_id' => 1, 'infoestudiantesifas_id' => $id],
                ['materias_id' => 2, 'infoestudiantesifas_id' => $id],
            ]);
        }
        DB::table('horarios_estudiantes')->insert([
            ['infoestudiantesifas_id' => 1, 'tipo_asignacion' => 'ESPECIALIDAD', 'dia_semana' => 1, 'hora_inicio' => '14:00', 'hora_fin' => '15:00'],
            ['infoestudiantesifas_id' => 1, 'tipo_asignacion' => 'PRACTICA_CONJUNTOS', 'dia_semana' => 2, 'hora_inicio' => '16:00', 'hora_fin' => '17:00'],
            ['infoestudiantesifas_id' => 2, 'tipo_asignacion' => 'ESPECIALIDAD', 'dia_semana' => 3, 'hora_inicio' => null, 'hora_fin' => null],
        ]);
    }

    private function solicitud(string $tipo, array $estudiantes = []): Request
    {
        $request = Request::create('/?tipo_asignacion=' . $tipo, 'POST', ['estudiantes' => $estudiantes]);
        $docente = new Planteldocentes();
        $docente->id = 1;
        $docente->instituciones_id = 1;
        $request->setUserResolver(fn () => $docente);
        return $request;
    }

    public function test_carga_horarios_por_categoria_y_guarda_solo_los_estudiantes_modificados(): void
    {
        $controller = new SesionAvanceEstudiantilController();
        $resultado = $controller->horariosGestor($this->solicitud('ESPECIALIDAD'))->getData(true);
        $this->assertSame([2, 1], array_column($resultado['data'], 'infoestudiantesifas_id'));
        $this->assertSame(3, $resultado['data'][0]['horarios'][0]['dia_semana']);
        $this->assertSame(1, count($resultado['data'][1]['horarios']));

        $resultado = $controller->horariosGestor($this->solicitud('PRACTICA_CONJUNTOS'))->getData(true);
        $this->assertCount(3, $resultado['data']);
        $this->assertCount(1, $resultado['data'][2]['horarios']);

        $request = $this->solicitud('ESPECIALIDAD', [
            ['id' => 1, 'horarios' => [
                ['dia_semana' => 1, 'hora_inicio' => '14:00', 'hora_fin' => '15:00'],
                ['dia_semana' => 7, 'hora_inicio' => '09:00', 'hora_fin' => '10:00'],
            ]],
        ]);
        $this->assertSame(200, $controller->guardarHorariosGestor($request)->getStatusCode());
        $this->assertSame(2, DB::table('horarios_estudiantes')->where('infoestudiantesifas_id', 1)->where('tipo_asignacion', 'ESPECIALIDAD')->count());
        $this->assertSame(1, DB::table('horarios_estudiantes')->where('infoestudiantesifas_id', 1)->where('tipo_asignacion', 'PRACTICA_CONJUNTOS')->count());
        $this->assertNull(DB::table('horarios_estudiantes')->where('infoestudiantesifas_id', 2)->value('hora_inicio'));

        $controller->guardarHorariosGestor($this->solicitud('ESPECIALIDAD', [['id' => 1, 'horarios' => []]]));
        $this->assertSame(0, DB::table('horarios_estudiantes')->where('infoestudiantesifas_id', 1)->where('tipo_asignacion', 'ESPECIALIDAD')->count());
        $this->assertSame(1, DB::table('horarios_estudiantes')->where('infoestudiantesifas_id', 1)->where('tipo_asignacion', 'PRACTICA_CONJUNTOS')->count());
    }

    public function test_rechaza_estudiantes_ajenos_sin_guardar_cambios_parciales(): void
    {
        $request = $this->solicitud('ESPECIALIDAD', [
            ['id' => 1, 'horarios' => []],
            ['id' => 3, 'horarios' => []],
        ]);
        $response = (new SesionAvanceEstudiantilController())->guardarHorariosGestor($request);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(3, DB::table('horarios_estudiantes')->count());
    }

    public function test_rechaza_dias_invalidos_sin_borrar_horarios(): void
    {
        $request = $this->solicitud('ESPECIALIDAD', [[
            'id' => 1, 'horarios' => [['dia_semana' => 8, 'hora_inicio' => '14:00', 'hora_fin' => '15:00']],
        ]]);
        try {
            (new SesionAvanceEstudiantilController())->guardarHorariosGestor($request);
            $this->fail('Se esperaba un error de validación.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('estudiantes.0.horarios.0.dia_semana', $e->errors());
        }
        $this->assertSame(3, DB::table('horarios_estudiantes')->count());
    }

    public function test_un_error_de_insercion_revierte_todos_los_cambios(): void
    {
        DB::statement("CREATE TRIGGER fallo_horarios BEFORE INSERT ON horarios_estudiantes WHEN NEW.dia_semana = 7 BEGIN SELECT RAISE(ABORT, 'fallo de prueba'); END");
        $request = $this->solicitud('ESPECIALIDAD', [
            ['id' => 1, 'horarios' => []],
            ['id' => 2, 'horarios' => [['dia_semana' => 7, 'hora_inicio' => '09:00', 'hora_fin' => '10:00']]],
        ]);
        try {
            (new SesionAvanceEstudiantilController())->guardarHorariosGestor($request);
            $this->fail('Se esperaba un error de base de datos.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('fallo de prueba', $e->getMessage());
        }
        $this->assertSame(3, DB::table('horarios_estudiantes')->count());
        $this->assertSame('14:00', DB::table('horarios_estudiantes')->where('infoestudiantesifas_id', 1)->where('tipo_asignacion', 'ESPECIALIDAD')->value('hora_inicio'));
    }

    public function test_utiliza_la_gestion_seleccionada_para_listar_y_guardar(): void
    {
        DB::table('anios')->insert(['id' => 2025, 'Predeterminado' => 'NO']);
        DB::table('plandeestudios')->insert(['id' => 3, 'anio_id' => 2025, 'ModoMateria' => 'MODO INSTRUMENTOS DE ESPECIALIDAD']);
        DB::table('materias')->insert(['id' => 3, 'plandeestudios_id' => 3]);
        DB::table('calificaciones')->insert(['materias_id' => 3, 'infoestudiantesifas_id' => 1]);
        $controller = new SesionAvanceEstudiantilController();
        $request = $this->solicitud('ESPECIALIDAD', [['id' => 1, 'horarios' => []]]);
        $request->query->set('anios_id', 2025);
        $this->assertSame([1], array_column($controller->horariosGestor($request)->getData(true)['data'], 'infoestudiantesifas_id'));
        $this->assertSame(200, $controller->guardarHorariosGestor($request)->getStatusCode());

        $ajeno = $this->solicitud('ESPECIALIDAD', [['id' => 2, 'horarios' => []]]);
        $ajeno->query->set('anios_id', 2025);
        $this->assertSame(403, $controller->guardarHorariosGestor($ajeno)->getStatusCode());
        $this->assertSame(1, DB::table('horarios_estudiantes')->where('infoestudiantesifas_id', 2)->count());
    }
}
