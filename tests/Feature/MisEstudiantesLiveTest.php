<?php

namespace Tests\Feature;

use App\Http\Controllers\HorariosEstudiantesController;
use App\Http\Controllers\SesionAvanceEstudiantilController;
use App\Models\Planteldocentes;
use App\Models\Planteladministrativos;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Pagination\PaginationState;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MisEstudiantesLiveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');

        Schema::create('anios', function (Blueprint $t) {
            $t->integer('id'); $t->string('Predeterminado'); $t->string('Anio')->nullable();
        });
        Schema::create('plandeestudios', function (Blueprint $t) { $t->integer('id'); $t->integer('anio_id'); $t->string('ModoMateria'); });
        Schema::create('materias', function (Blueprint $t) { $t->integer('id'); $t->integer('plandeestudios_id'); });
        Schema::create('calificaciones', function (Blueprint $t) { $t->integer('materias_id'); $t->integer('infoestudiantesifas_id'); });
        Schema::create('planteldocentes', function (Blueprint $t) { $t->integer('id'); $t->integer('instituciones_id'); });
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
        Schema::create('sesiones_avance_estudiantil', function (Blueprint $t) {
            $t->increments('id'); $t->integer('infoestudiantesifas_id'); $t->integer('planteldocentes_id');
            $t->string('tipo_asignacion'); $t->integer('evaluacion')->nullable(); $t->date('fecha'); $t->text('avance_texto')->nullable();
            $t->integer('estrellas')->nullable(); $t->text('sugerencia')->nullable(); $t->string('asistencia')->nullable(); $t->timestamps();
        });

        DB::table('anios')->insert(['id' => 2026, 'Predeterminado' => 'PREDETERMINADO', 'Anio' => '2026']);
        DB::table('plandeestudios')->insert(['id' => 1, 'anio_id' => 2026, 'ModoMateria' => 'MODO INSTRUMENTOS DE ESPECIALIDAD']);
        DB::table('materias')->insert(['id' => 1, 'plandeestudios_id' => 1]);
        DB::table('planteldocentes')->insert(['id' => 1, 'instituciones_id' => 1]);
        DB::table('instituciones')->insert(['id' => 1, 'Nombre' => 'Institucion']);
        for ($id = 1; $id <= 5; $id++) {
            DB::table('estudiantesifas')->insert([
                'id' => $id, 'Ap_Paterno' => "Alumno $id", 'Ap_Materno' => '', 'Nombre' => '', 'CI' => "$id",
            ]);
            DB::table('infoestudiantesifas')->insert([
                'id' => $id, 'estudiantesifas_id' => $id, 'instituciones_id' => 1, 'planteldocadmins_id' => 1,
            ]);
            DB::table('calificaciones')->insert(['materias_id' => 1, 'infoestudiantesifas_id' => $id]);
        }
    }

    protected function tearDown(): void
    {
        PaginationState::resolveUsing($this->app);
        parent::tearDown();
    }

    private function consultar(array $parametros = [], int $page = 1): array
    {
        Paginator::currentPageResolver(fn () => $page);
        $request = Request::create('/', 'GET', array_merge([
            'tipo_asignacion' => 'ESPECIALIDAD', 'dia_semana' => 1, 'hora_actual' => '14:30', 'per_page' => 1,
        ], $parametros));
        $docente = new Planteldocentes();
        $docente->id = 1;
        $docente->instituciones_id = 1;
        $request->setUserResolver(fn () => $docente);
        return (new SesionAvanceEstudiantilController())->misEstudiantesPaginado($request)->getData(true);
    }

    public function test_live_filtra_antes_de_paginar_sin_duplicar_horarios_individuales_o_grupales(): void
    {
        $horarios = new HorariosEstudiantesController();
        $individual = $horarios->guardarHorario(Request::create('/', 'POST', ['dias' => [
            ['dia_semana' => 1, 'hora_inicio' => '14:40', 'hora_fin' => '15:30'],
        ]]), 1, 'ESPECIALIDAD');
        $grupal = $horarios->guardarHorarioGrupal(Request::create('/', 'POST', ['infoIds' => [2, 3], 'dias' => [
            ['dia_semana' => 1, 'hora_inicio' => '14:30:00', 'hora_fin' => '15:00:00'],
            ['dia_semana' => 1, 'hora_inicio' => '14:00:00', 'hora_fin' => '14:30:00'],
        ]]), 'ESPECIALIDAD');
        $horarios->guardarHorario(Request::create('/', 'POST', ['dias' => [
            ['dia_semana' => 2, 'hora_inicio' => '14:00', 'hora_fin' => '15:00'],
            ['dia_semana' => 1, 'hora_inicio' => null, 'hora_fin' => null],
        ]]), 4, 'ESPECIALIDAD');
        $horarios->guardarHorario(Request::create('/', 'POST', ['dias' => [
            ['dia_semana' => 1, 'hora_inicio' => '14:00', 'hora_fin' => '15:00'],
        ]]), 5, 'PRACTICA_CONJUNTOS');

        $this->assertSame(200, $individual->getStatusCode());
        $this->assertSame(200, $grupal->getStatusCode());
        $primera = $this->consultar();
        $segunda = $this->consultar([], 2);
        $this->assertSame(2, $primera['total']);
        $this->assertSame(2, $primera['last_page']);
        $this->assertSame(2, $primera['data'][0]['infoestudiantesifas_id']);
        $this->assertSame(3, $segunda['data'][0]['infoestudiantesifas_id']);
        $this->assertCount(2, $primera['data'][0]['horarios']);
        $this->assertSame(0, $this->consultar(['hora_actual' => '13:59'])['total']);
        $this->assertSame(3, $this->consultar(['hora_actual' => '15:00'])['total']);
        $this->assertSame(1, $this->consultar(['hora_actual' => '15:01'])['total']);
        $this->assertSame(1, $this->consultar(['dia_semana' => 2])['total']);
        $this->assertSame(5, $this->consultar(['dia_semana' => null, 'hora_actual' => null])['total']);
    }

    public function test_live_incluye_horarios_que_cruzan_medianoche_y_el_cambio_de_semana(): void
    {
        DB::table('horarios_estudiantes')->insert([
            ['infoestudiantesifas_id' => 1, 'tipo_asignacion' => 'ESPECIALIDAD', 'dia_semana' => 7, 'hora_inicio' => '23:30:00', 'hora_fin' => '00:15:00'],
            ['infoestudiantesifas_id' => 2, 'tipo_asignacion' => 'ESPECIALIDAD', 'dia_semana' => 1, 'hora_inicio' => '23:59:00', 'hora_fin' => '00:00:00'],
        ]);
        $this->assertSame(1, $this->consultar(['dia_semana' => 7, 'hora_actual' => '23:30'])['data'][0]['infoestudiantesifas_id']);
        $this->assertSame(1, $this->consultar(['dia_semana' => 1, 'hora_actual' => '00:15'])['data'][0]['infoestudiantesifas_id']);
        $this->assertSame(0, $this->consultar(['dia_semana' => 1, 'hora_actual' => '00:16'])['total']);
        $this->assertSame(2, $this->consultar(['dia_semana' => 2, 'hora_actual' => '00:00'])['data'][0]['infoestudiantesifas_id']);
        $this->assertSame(0, $this->consultar(['dia_semana' => 2, 'hora_actual' => '00:01'])['total']);
        $this->assertSame(1, $this->consultar(['dia_semana' => 7, 'hora_actual' => null])['total']);
    }

    public function test_agrupa_por_curso_y_paralelo_antes_de_paginar_y_conserva_los_filtros(): void
    {
        foreach ([
            1 => ['Segundo', 'B'], 2 => ['Primero', 'B'], 3 => ['Primero', 'A'],
            4 => ['Primero', 'A'], 5 => ['Primero', 'B'],
        ] as $id => [$curso, $paralelo]) {
            DB::table('infoestudiantesifas')->where('id', $id)->update([
                'Curso_Solicitado' => $curso, 'Paralelo_Solicitado' => $paralelo,
            ]);
        }
        $params = ['dia_semana' => null, 'hora_actual' => null, 'per_page' => 2, 'agrupar_curso' => 1];
        $this->assertSame([3, 4], array_column($this->consultar($params, 1)['data'], 'infoestudiantesifas_id'));
        $this->assertSame([2, 5], array_column($this->consultar($params, 2)['data'], 'infoestudiantesifas_id'));
        $this->assertSame([1], array_column($this->consultar($params, 3)['data'], 'infoestudiantesifas_id'));
        $this->assertSame([1, 2], array_column($this->consultar(array_merge($params, ['agrupar_curso' => 0]), 1)['data'], 'infoestudiantesifas_id'));
        $filtrados = $this->consultar(array_merge($params, ['cursos' => ['Primero'], 'per_page' => 10]));
        $this->assertSame(4, $filtrados['total']);
        $this->assertSame([3, 4, 2, 5], array_column($filtrados['data'], 'infoestudiantesifas_id'));

        DB::table('horarios_estudiantes')->insert([
            ['infoestudiantesifas_id' => 2, 'tipo_asignacion' => 'ESPECIALIDAD', 'dia_semana' => 1, 'hora_inicio' => '14:00', 'hora_fin' => '15:00'],
            ['infoestudiantesifas_id' => 3, 'tipo_asignacion' => 'ESPECIALIDAD', 'dia_semana' => 1, 'hora_inicio' => '14:00', 'hora_fin' => '15:00'],
        ]);
        $live = $this->consultar(['agrupar_curso' => 1, 'per_page' => 10]);
        $this->assertSame([3, 2], array_column($live['data'], 'infoestudiantesifas_id'));
    }

    public function test_clasifica_el_ultimo_avance_de_la_asignacion_y_docente_de_la_pagina(): void
    {
        DB::table('sesiones_avance_estudiantil')->insert([
            ['id' => 1, 'infoestudiantesifas_id' => 1, 'planteldocentes_id' => 1, 'tipo_asignacion' => 'ESPECIALIDAD', 'fecha' => '2025-01-01', 'avance_texto' => 'RETIRADO'],
            ['id' => 2, 'infoestudiantesifas_id' => 1, 'planteldocentes_id' => 1, 'tipo_asignacion' => 'ESPECIALIDAD', 'fecha' => '2026-01-01', 'avance_texto' => 'Activo'],
            ['id' => 3, 'infoestudiantesifas_id' => 2, 'planteldocentes_id' => 1, 'tipo_asignacion' => 'ESPECIALIDAD', 'fecha' => '2026-01-01', 'avance_texto' => 'Activo'],
            ['id' => 4, 'infoestudiantesifas_id' => 2, 'planteldocentes_id' => 1, 'tipo_asignacion' => 'ESPECIALIDAD', 'fecha' => '2026-01-01', 'avance_texto' => '<p>re<strong>tirado</strong></p>'],
            ['id' => 5, 'infoestudiantesifas_id' => 2, 'planteldocentes_id' => 2, 'tipo_asignacion' => 'ESPECIALIDAD', 'fecha' => '2027-01-01', 'avance_texto' => 'Activo'],
            ['id' => 6, 'infoestudiantesifas_id' => 2, 'planteldocentes_id' => 1, 'tipo_asignacion' => 'PRACTICA_CONJUNTOS', 'fecha' => '2027-01-01', 'avance_texto' => 'Activo'],
            ['id' => 7, 'infoestudiantesifas_id' => 3, 'planteldocentes_id' => 1, 'tipo_asignacion' => 'ESPECIALIDAD', 'fecha' => '2026-01-01', 'avance_texto' => '<p>Situación: aBaNdOnO</p>'],
            ['id' => 8, 'infoestudiantesifas_id' => 4, 'planteldocentes_id' => 1, 'tipo_asignacion' => 'ESPECIALIDAD', 'fecha' => '2026-01-01', 'avance_texto' => '<p title="RETIRADO">Activo</p>'],
            ['id' => 9, 'infoestudiantesifas_id' => 5, 'planteldocentes_id' => 1, 'tipo_asignacion' => 'ESPECIALIDAD', 'fecha' => '2026-01-01', 'avance_texto' => null],
        ]);
        $params = ['dia_semana' => null, 'hora_actual' => null, 'per_page' => 3];
        $primera = $this->consultar($params);
        $segunda = $this->consultar($params, 2);
        $this->assertSame(['normal', 'retirado', 'abandono'], array_column($primera['data'], 'estado_avance'));
        $this->assertSame(['normal', 'sin_avance'], array_column($segunda['data'], 'estado_avance'));
        DB::table('horarios_estudiantes')->insert([
            ['infoestudiantesifas_id' => 2, 'tipo_asignacion' => 'ESPECIALIDAD', 'dia_semana' => 1, 'hora_inicio' => '14:00', 'hora_fin' => '15:00'],
            ['infoestudiantesifas_id' => 3, 'tipo_asignacion' => 'ESPECIALIDAD', 'dia_semana' => 1, 'hora_inicio' => '14:00', 'hora_fin' => '15:00'],
        ]);
        $live = $this->consultar(['per_page' => 10]);
        $this->assertSame([2, 3], array_column($live['data'], 'infoestudiantesifas_id'));
        $this->assertSame(['retirado', 'abandono'], array_column($live['data'], 'estado_avance'));
        DB::table('sesiones_avance_estudiantil')->where('id', 9)->delete();
        $this->assertSame('sin_avance', $this->consultar($params, 2)['data'][1]['estado_avance']);

        Paginator::currentPageResolver(fn () => 1);
        $admin = new Planteladministrativos();
        $request = Request::create('/', 'GET', ['tipo_asignacion' => 'ESPECIALIDAD', 'planteldocentes_id' => 1, 'per_page' => 10]);
        $request->setUserResolver(fn () => $admin);
        $resultado = (new SesionAvanceEstudiantilController())->misEstudiantesPaginado($request)->getData(true);
        $this->assertSame(['normal', 'retirado', 'abandono', 'normal', 'sin_avance'], array_column($resultado['data'], 'estado_avance'));
    }

    public function test_trata_texto_y_html_vacios_como_sin_avance_sin_afectar_contenido_multimedia(): void
    {
        $avances = ['', '  ', '<p><br></p>', '<p>&nbsp;&#8203;</p>', '<p><img src="imagen.png"></p>'];
        foreach ($avances as $indice => $avance) {
            DB::table('sesiones_avance_estudiantil')->insert([
                'infoestudiantesifas_id' => $indice + 1, 'planteldocentes_id' => 1,
                'tipo_asignacion' => 'ESPECIALIDAD', 'fecha' => '2026-01-01',
                'avance_texto' => $avance, 'sugerencia' => 'Una sugerencia con contenido',
            ]);
        }
        $resultado = $this->consultar(['dia_semana' => null, 'hora_actual' => null, 'per_page' => 10]);
        $this->assertSame(['sin_avance', 'sin_avance', 'sin_avance', 'sin_avance', 'normal'], array_column($resultado['data'], 'estado_avance'));
    }

    public function test_gestion_y_materia_asignada_se_aplican_en_todos_los_listados(): void
    {
        DB::table('anios')->insert([
            ['id' => 2025, 'Predeterminado' => 'NO', 'Anio' => '2025'],
            ['id' => 2027, 'Predeterminado' => 'NO', 'Anio' => '2027'],
        ]);
        DB::table('plandeestudios')->insert([
            ['id' => 2, 'anio_id' => 2025, 'ModoMateria' => 'MODO INSTRUMENTOS DE ESPECIALIDAD'],
            ['id' => 3, 'anio_id' => 2025, 'ModoMateria' => 'MODO PRACTICA DE CONJUNTOS'],
            ['id' => 4, 'anio_id' => 2025, 'ModoMateria' => 'MODO OTROS'],
            ['id' => 5, 'anio_id' => 2025, 'ModoMateria' => 'MODO REGULAR'],
        ]);
        foreach ([2, 3, 4, 5] as $id) {
            DB::table('materias')->insert(['id' => $id, 'plandeestudios_id' => $id]);
        }
        DB::table('calificaciones')->insert([
            ['materias_id' => 2, 'infoestudiantesifas_id' => 1],
            ['materias_id' => 2, 'infoestudiantesifas_id' => 1],
            ['materias_id' => 3, 'infoestudiantesifas_id' => 1],
            ['materias_id' => 3, 'infoestudiantesifas_id' => 2],
            ['materias_id' => 4, 'infoestudiantesifas_id' => 2],
            ['materias_id' => 4, 'infoestudiantesifas_id' => 3],
            ['materias_id' => 5, 'infoestudiantesifas_id' => 5],
        ]);
        foreach ([1, 2, 3, 4, 5] as $id) {
            DB::table('infoestudiantesifas')->where('id', $id)->update([
                'planteldocadmins_id' => $id === 3 ? 2 : 1,
                'planteldocadmins_idPC' => $id === 1 ? 2 : 1,
                'planteldocadmins_idOtros' => $id === 2 ? 2 : 1,
                'Curso_Solicitado' => "Curso $id", 'Paralelo_Solicitado' => 'A', 'Turno' => 'Mañana',
            ]);
        }
        DB::table('horarios_estudiantes')->insert([
            ['infoestudiantesifas_id' => 1, 'tipo_asignacion' => 'ESPECIALIDAD', 'dia_semana' => 1, 'hora_inicio' => '14:00', 'hora_fin' => '15:00'],
            ['infoestudiantesifas_id' => 4, 'tipo_asignacion' => 'ESPECIALIDAD', 'dia_semana' => 1, 'hora_inicio' => '14:00', 'hora_fin' => '15:00'],
        ]);
        $docente = new Planteldocentes();
        $docente->id = 1;
        $docente->instituciones_id = 1;
        $admin = new Planteladministrativos();
        $controller = new SesionAvanceEstudiantilController();
        Paginator::currentPageResolver(fn () => 1);
        $solicitud = function ($user, string $tipo, int $anio): Request {
            $request = Request::create('/', 'GET', [
                'tipo_asignacion' => $tipo, 'anios_id' => $anio, 'planteldocentes_id' => 1,
            ]);
            $request->setUserResolver(fn () => $user);
            return $request;
        };
        foreach (['ESPECIALIDAD' => 1, 'PRACTICA_CONJUNTOS' => 2, 'COMPLEMENTARIO' => 3] as $tipo => $id) {
            $request = $solicitud($docente, $tipo, 2025);
            foreach (['misEstudiantesPaginado', 'misEstudiantesTodosIds', 'horariosGestor'] as $metodo) {
                $this->assertSame([$id], array_column($controller->$metodo($request)->getData(true)['data'], 'infoestudiantesifas_id'), $metodo . ' ' . $tipo);
            }
            $this->assertSame(["Curso $id"], $controller->misEstudiantesFiltros($request)->getData(true)['cursos']);
            $this->assertSame(["Curso $id"], array_column($controller->misEstudiantesCursos($request)->getData(true)['data'], 'curso'));
            $adminRequest = $solicitud($admin, $tipo, 2025);
            $this->assertSame([$id], array_column($controller->misEstudiantesPaginado($adminRequest)->getData(true)['data'], 'infoestudiantesifas_id'));
            $this->assertSame([$id], array_column($controller->misEstudiantesTodosIds($adminRequest)->getData(true)['data'], 'infoestudiantesifas_id'));
            $this->assertSame(["Curso $id"], $controller->misEstudiantesFiltros($adminRequest)->getData(true)['cursos']);
            $this->assertSame([$id], array_column($controller->horariosGestor($adminRequest)->getData(true)['data'], 'infoestudiantesifas_id'));
        }
        $request = $solicitud($docente, 'ESPECIALIDAD', 2025);
        $totales = $controller->misEstudiantesTotales($request)->getData(true);
        $this->assertSame([1, 1, 1], [$totales['especialidad'], $totales['practica'], $totales['complementario']]);
        $grupos = $controller->misEstudiantes($request)->getData(true);
        $this->assertSame([1], array_column($grupos['especialidad'], 'infoestudiantesifas_id'));
        $this->assertSame([2], array_column($grupos['practica'], 'infoestudiantesifas_id'));
        $this->assertSame([3], array_column($grupos['complementario'], 'infoestudiantesifas_id'));
        $this->assertSame([1], array_column($this->consultar(['anios_id' => 2025])['data'], 'infoestudiantesifas_id'));
        $this->assertSame(4, $controller->misEstudiantesPaginado($solicitud($docente, 'ESPECIALIDAD', 2026))->getData(true)['total']);
        $this->assertSame(0, $controller->misEstudiantesPaginado($solicitud($docente, 'ESPECIALIDAD', 2027))->getData(true)['total']);

        $guardar = Request::create('/?tipo_asignacion=ESPECIALIDAD&anios_id=2025', 'POST', ['estudiantes' => [['id' => 2, 'horarios' => []]]]);
        $guardar->setUserResolver(fn () => $docente);
        $this->assertSame(403, $controller->guardarHorariosGestor($guardar)->getStatusCode());

        $terminar = Request::create('/?anios_id=2025', 'POST', ['tipo_asignacion' => 'ESPECIALIDAD', 'cursos' => ['Curso 2||A||Mañana']]);
        $terminar->setUserResolver(fn () => $docente);
        $this->assertSame(422, $controller->terminarClase($terminar)->getStatusCode());

        Schema::create('terminar_clase_logs', function (Blueprint $t) {
            $t->increments('id'); $t->integer('planteldocentes_id'); $t->integer('instituciones_id');
            $t->string('tipo_asignacion'); $t->integer('evaluacion'); $t->date('fecha');
            $t->text('cursos_json')->nullable(); $t->text('sesiones_creadas_ids'); $t->integer('cantidad_creadas'); $t->timestamps();
        });
        $terminar = Request::create('/?anios_id=2025', 'POST', ['tipo_asignacion' => 'ESPECIALIDAD']);
        $terminar->setUserResolver(fn () => $docente);
        $this->assertSame(1, $controller->terminarClase($terminar)->getData(true)['creadas']);
        $this->assertSame([1], DB::table('sesiones_avance_estudiantil')->pluck('infoestudiantesifas_id')->all());
    }

    public function test_requiere_gestion_si_no_hay_predeterminada(): void
    {
        DB::table('anios')->delete();
        $this->expectException(ValidationException::class);
        $this->consultar();
    }
}
