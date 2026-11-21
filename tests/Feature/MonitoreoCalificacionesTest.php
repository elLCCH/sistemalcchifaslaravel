<?php

namespace Tests\Feature;

use App\Http\Controllers\MonitoreoCalificacionesController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MonitoreoCalificacionesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Schema::create('estudiantesifas', function (Blueprint $t) {
            $t->integer('id'); $t->string('Ap_Paterno'); $t->string('Ap_Materno'); $t->string('Nombre'); $t->string('CI');
        });
        Schema::create('infoestudiantesifas', function (Blueprint $t) {
            $t->integer('id'); $t->integer('estudiantesifas_id'); $t->integer('instituciones_id');
            $t->integer('planteldocadmins_id')->nullable(); $t->integer('planteldocadmins_idPC')->nullable();
            $t->integer('planteldocadmins_idOtros')->nullable();
            $t->string('InstrumentoMusical')->nullable(); $t->string('InstrumentoMusicalSecundario')->nullable();
        });
        Schema::create('carreras', function (Blueprint $t) {
            $t->integer('id'); $t->integer('instituciones_id'); $t->string('Resolucion')->nullable(); $t->string('NombreCarrera')->nullable();
            $t->string('CantidadEvaluaciones')->nullable(); $t->integer('NotaAprobacion')->nullable(); $t->integer('NotaMinRevalida')->nullable();
        });
        Schema::create('plandeestudios', function (Blueprint $t) {
            $t->integer('id'); $t->integer('carreras_id'); $t->integer('anio_id');
            $t->string('LvlCurso'); $t->string('NombreMateria'); $t->string('ModoMateria')->nullable();
            $t->integer('RangoLvlCurso');
        });
        Schema::create('materias', function (Blueprint $t) {
            $t->integer('id'); $t->integer('plandeestudios_id'); $t->string('Paralelo'); $t->string('Turno'); $t->string('EstadoHabilitacion')->nullable();
        });
        Schema::create('calificaciones', function (Blueprint $t) {
            $t->integer('id'); $t->integer('materias_id'); $t->integer('infoestudiantesifas_id');
            $t->string('EstadoRegistroMateria')->nullable(); $t->integer('PruebaRecuperacion')->nullable();
            foreach (['Teorico1', 'Teorico2', 'Teorico3', 'Teorico4', 'Practico1', 'Practico2', 'Practico3', 'Practico4', 'PromTeorico', 'PromPractico', 'Promedio'] as $campo) {
                $t->integer($campo)->nullable();
            }
        });
        Schema::create('planteldocentes', function (Blueprint $t) {
            $t->integer('id'); $t->integer('instituciones_id'); $t->string('Nombres'); $t->string('Apellidos');
        });
        Schema::create('planteldocentesmaterias', function (Blueprint $t) {
            $t->integer('materias_id'); $t->integer('planteldocentes_id');
        });

        DB::table('planteldocentes')->insert([
            ['id' => 1, 'instituciones_id' => 1, 'Nombres' => 'Ana', 'Apellidos' => 'Pérez'],
            ['id' => 2, 'instituciones_id' => 1, 'Nombres' => 'Luis', 'Apellidos' => 'Rojas'],
            ['id' => 3, 'instituciones_id' => 1, 'Nombres' => 'Sol', 'Apellidos' => 'Vega'],
            ['id' => 4, 'instituciones_id' => 2, 'Nombres' => 'Otra', 'Apellidos' => 'Institución'],
        ]);
        DB::table('carreras')->insert([
            ['id' => 1, 'instituciones_id' => 1, 'Resolucion' => ' R1 ', 'CantidadEvaluaciones' => 'DOS EVALUACIONES'],
            ['id' => 2, 'instituciones_id' => 2, 'Resolucion' => 'R2', 'CantidadEvaluaciones' => 'CUATRO EVALUACIONES'],
            ['id' => 3, 'instituciones_id' => 1, 'Resolucion' => null, 'CantidadEvaluaciones' => 'CERO EVALUACIONES'],
        ]);
        foreach ([
            [1, 1, 2026, '1 DOCENTE x MATERIA'], [2, 1, 2026, 'MODO INSTRUMENTOS DE ESPECIALIDAD'],
            [3, 1, 2026, 'MODO PRÁCTICA DE CONJUNTOS'], [4, 1, 2026, 'MODO INSTRUMENTO COMPLEMENTARIO'],
            [5, 1, 2026, 'MODO INSTRUMENTOS DE ESPECIALIDAD'], [6, 1, 2025, '1 DOCENTE x MATERIA'],
            [7, 2, 2026, '1 DOCENTE x MATERIA'], [8, 3, 2026, '1 DOCENTE x MATERIA'],
        ] as [$id, $carrera, $anio, $modo]) {
            DB::table('plandeestudios')->insert(['id' => $id, 'carreras_id' => $carrera, 'anio_id' => $anio,
                'LvlCurso' => $id === 4 ? 'Segundo' : 'Primero', 'NombreMateria' => "Materia $id", 'ModoMateria' => $modo, 'RangoLvlCurso' => $id]);
            DB::table('materias')->insert(['id' => $id, 'plandeestudios_id' => $id, 'Paralelo' => 'A', 'Turno' => 'M']);
            DB::table('estudiantesifas')->insert(['id' => $id, 'Ap_Paterno' => 'López', 'Ap_Materno' => 'Flores', 'Nombre' => "Alumno $id", 'CI' => "CI$id"]);
            DB::table('infoestudiantesifas')->insert([
                'id' => $id, 'estudiantesifas_id' => $id, 'instituciones_id' => $carrera === 2 ? 2 : 1,
                'planteldocadmins_id' => $id === 5 ? null : 1, 'planteldocadmins_idPC' => 2,
                'planteldocadmins_idOtros' => 3, 'InstrumentoMusical' => 'Piano', 'InstrumentoMusicalSecundario' => 'Flauta',
            ]);
            DB::table('calificaciones')->insert(['id' => $id, 'materias_id' => $id, 'infoestudiantesifas_id' => $id,
                'Teorico1' => 0, 'Practico1' => null, 'Teorico2' => 10, 'Practico2' => 10]);
        }
        DB::table('planteldocentesmaterias')->insert([
            ['materias_id' => 1, 'planteldocentes_id' => 1], ['materias_id' => 1, 'planteldocentes_id' => 2],
        ]);
    }

    private function consultar(array $filtros = []): array
    {
        $request = Request::create('/', 'GET', array_merge([
            'anio_id' => 2026, 'institucion_id' => 1, 'evaluaciones' => [1], 'incluir_promedios' => '0',
        ], $filtros));
        $request->setUserResolver(fn () => (object) ['instituciones_id' => 1]);
        return (new MonitoreoCalificacionesController())->buscar($request)->getData(true);
    }

    public function test_identifica_casillas_y_responsables_por_modo_sin_contar_ceros(): void
    {
        $resultado = $this->consultar();
        $filas = collect($resultado['data'])->keyBy('id');
        $this->assertSame(5, $resultado['meta']['total']);
        $this->assertSame(['Práctico 1'], $filas[1]['vacios']);
        $this->assertEqualsCanonicalizing(['Pérez Ana', 'Rojas Luis'], $filas[1]['docentes']);
        $this->assertSame(['Pérez Ana'], $filas[2]['docentes']);
        $this->assertSame('Piano', $filas[2]['instrumento']);
        $this->assertSame(['Rojas Luis'], $filas[3]['docentes']);
        $this->assertNull($filas[3]['instrumento']);
        $this->assertSame(['Vega Sol'], $filas[4]['docentes']);
        $this->assertSame('Flauta', $filas[4]['instrumento']);
        $this->assertSame([], $filas[5]['docentes']);
    }

    public function test_respeta_evaluaciones_configuradas_y_opcion_de_promedios(): void
    {
        $this->assertSame(0, $this->consultar(['evaluaciones' => [3]])['meta']['total']);
        $resultado = $this->consultar(['evaluaciones' => [3], 'incluir_promedios' => '1']);
        $this->assertSame(6, $resultado['meta']['total']);
        $this->assertSame(['Promedio teórico', 'Promedio práctico', 'Promedio final'], $resultado['data'][0]['vacios']);
    }

    public function test_interpreta_cantidades_en_palabras_y_digitos_sin_admitir_texto_no_valido(): void
    {
        DB::table('calificaciones')->whereIn('id', [1, 2, 3, 4, 5])->update(['Teorico2' => null]);
        foreach (['DOS EVALUACIONES', '2 EVALUACIONES', 'TRES EVALUACIONES'] as $cantidad) {
            DB::table('carreras')->where('id', 1)->update(['CantidadEvaluaciones' => $cantidad]);
            $resultado = $this->consultar(['evaluaciones' => [2]]);
            $this->assertSame(5, $resultado['meta']['total'], $cantidad);
            $this->assertSame([], $resultado['carreras_sin_cantidad']);
        }
        DB::table('carreras')->where('id', 1)->update(['CantidadEvaluaciones' => 'UNA EVALUACIÓN']);
        $this->assertSame(0, $this->consultar(['evaluaciones' => [2]])['meta']['total']);
        DB::table('carreras')->where('id', 1)->update(['CantidadEvaluaciones' => 'TODOS LOS MODOS']);
        $resultado = $this->consultar(['evaluaciones' => [2]]);
        $this->assertSame(0, $resultado['meta']['total']);
        $this->assertSame(['Carrera 1'], $resultado['carreras_sin_cantidad']);
    }

    public function test_excluye_las_casillas_y_registros_solo_de_la_evaluacion_ignorada(): void
    {
        DB::table('carreras')->where('id', 1)->update(['CantidadEvaluaciones' => 'CUATRO EVALUACIONES']);
        DB::table('calificaciones')->whereIn('id', [1, 2, 3, 4, 5])->update([
            'Teorico1' => 10, 'Practico1' => 10, 'Teorico2' => 10, 'Practico2' => 10,
            'Teorico3' => 10, 'Practico3' => 10, 'Teorico4' => 10, 'Practico4' => 10,
        ]);
        DB::table('calificaciones')->where('id', 1)->update(['Teorico3' => null]);
        DB::table('calificaciones')->where('id', 2)->update(['Practico3' => null, 'Practico4' => null]);
        DB::table('calificaciones')->where('id', 3)->update(['Teorico1' => null]);

        $todos = $this->consultar(['evaluaciones' => [1, 2, 3, 4]]);
        $this->assertEqualsCanonicalizing([1, 2, 3], array_column($todos['data'], 'id'));
        $sinTercera = $this->consultar(['evaluaciones' => [1, 2, 4]]);
        $filas = collect($sinTercera['data'])->keyBy('id');
        $this->assertEqualsCanonicalizing([2, 3], array_keys($filas->all()));
        $this->assertSame(['Práctico 4'], $filas[2]['vacios']);
        $this->assertSame(['Teórico 1'], $filas[3]['vacios']);
    }

    public function test_advierte_la_cantidad_de_evaluaciones_invalida_sin_presentar_un_resultado_completo(): void
    {
        DB::table('carreras')->where('id', 1)->update(['NombreCarrera' => 'Música', 'CantidadEvaluaciones' => null]);
        $resultado = $this->consultar();
        $this->assertSame(0, $resultado['meta']['total']);
        $this->assertSame(['Música'], $resultado['carreras_sin_cantidad']);
        $resultado = $this->consultar(['evaluaciones' => [], 'incluir_promedios' => '1']);
        $this->assertSame(6, $resultado['meta']['total']);
        $this->assertSame([], $resultado['carreras_sin_cantidad']);
        $this->assertSame([], $this->consultar(['resolucion' => '__SIN_RESOLUCION__'])['carreras_sin_cantidad']);
    }

    public function test_filtra_resolucion_curso_docente_y_nombre_completo(): void
    {
        $resultado = $this->consultar(['resolucion' => 'R1', 'curso' => 'Segundo', 'docente_id' => 3, 'estudiante' => 'López Alumno']);
        $this->assertSame([4], array_column($resultado['data'], 'id'));
        $resultado = $this->consultar(['docente_id' => 2]);
        $this->assertEqualsCanonicalizing([1, 3], array_column($resultado['data'], 'id'));
        $this->assertSame(0, $this->consultar(['resolucion' => '__SIN_RESOLUCION__'])['meta']['total']);
        $this->assertSame([6], array_column($this->consultar(['anio_id' => 2025])['data'], 'id'));
    }

    public function test_opciones_pertenecen_a_la_gestion_y_la_institucion(): void
    {
        $request = Request::create('/', 'GET', ['anio_id' => 2026, 'institucion_id' => 1]);
        $request->setUserResolver(fn () => (object) ['instituciones_id' => 1]);
        $opciones = (new MonitoreoCalificacionesController())->opciones($request)->getData(true);
        $this->assertSame(['R1'], $opciones['resoluciones']);
        $this->assertTrue($opciones['sin_resolucion']);
        $this->assertEqualsCanonicalizing([1, 2, 3], array_column($opciones['docentes'], 'id'));
        $this->assertEqualsCanonicalizing(['Primero', 'Segundo'], $opciones['cursos']);
    }

    public function test_filtra_multiples_cursos_y_expone_estado_de_la_materia(): void
    {
        DB::table('materias')->where('id', 1)->update(['EstadoHabilitacion' => 'PRIMERA EVALUACIÓN, SEGUNDA EVALUACIÓN']);
        $resultado = $this->consultar(['cursos' => ['Primero', 'Segundo']]);
        $this->assertSame(5, $resultado['meta']['total']);
        $fila = collect($resultado['data'])->firstWhere('id', 1);
        $this->assertSame(1, $fila['materia_id']);
        $this->assertSame([1, 2], $fila['evaluaciones_habilitadas']);
        $this->assertSame([4], array_column($this->consultar(['cursos' => ['Segundo']])['data'], 'id'));
    }

    public function test_filtra_multiples_opciones_por_categoria_y_docentes_por_modo(): void
    {
        DB::table('materias')->where('id', 4)->update(['Paralelo' => 'B', 'Turno' => 'T']);
        $resultado = $this->consultar([
            'resoluciones' => ['R1', '__SIN_RESOLUCION__'],
            'cursos' => ['Primero', 'Segundo'], 'paralelos' => ['A', 'B'],
            'turnos' => ['M', 'T'], 'docentes_ids' => [2, 3],
        ]);
        $this->assertEqualsCanonicalizing([1, 3, 4], array_column($resultado['data'], 'id'));
        $resultado = $this->consultar(['paralelos' => ['B'], 'turnos' => ['T'], 'docentes_ids' => [2, 3]]);
        $this->assertSame([4], array_column($resultado['data'], 'id'));
        $this->assertSame(0, $this->consultar(['resoluciones' => ['__SIN_RESOLUCION__']])['meta']['total']);
    }

    public function test_rechaza_docente_de_otra_institucion_en_seleccion_multiple(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->consultar(['docentes_ids' => [1, 4]]);
    }

    public function test_segunda_instancia_identifica_elegibles_incluso_con_otros_vacios(): void
    {
        DB::table('carreras')->where('id', 1)->update(['NotaAprobacion' => 61, 'NotaMinRevalida' => 40]);
        DB::table('calificaciones')->where('id', 1)->update(['Promedio' => 50]);
        DB::table('calificaciones')->where('id', 2)->update(['Promedio' => 39]);
        DB::table('calificaciones')->where('id', 3)->update(['Promedio' => 61]);
        DB::table('calificaciones')->where('id', 4)->update(['Promedio' => 50, 'PruebaRecuperacion' => 70]);
        DB::table('calificaciones')->where('id', 5)->update(['Promedio' => 50, 'Teorico2' => 0, 'Practico2' => 0]);
        $filas = collect($this->consultar(['incluir_segunda_instancia' => '1'])['data'])->keyBy('id');
        $this->assertContains('Segunda instancia', $filas[1]['vacios']);
        foreach ([2, 3, 4, 5] as $id) $this->assertNotContains('Segunda instancia', $filas[$id]['vacios']);
        $soloSegunda = $this->consultar(['evaluaciones' => [], 'incluir_segunda_instancia' => '1']);
        $this->assertSame([1], array_column($soloSegunda['data'], 'id'));
        DB::table('calificaciones')->insert(['id' => 100, 'materias_id' => 2, 'infoestudiantesifas_id' => 1, 'Promedio' => null]);
        $this->assertSame(0, $this->consultar(['evaluaciones' => [], 'incluir_segunda_instancia' => '1'])['meta']['total']);
        DB::table('calificaciones')->where('id', 100)->update(['Promedio' => 50]);
        foreach ([101, 102] as $id) DB::table('calificaciones')->insert(['id' => $id, 'materias_id' => 2, 'infoestudiantesifas_id' => 1, 'Promedio' => 50]);
        $this->assertSame(0, $this->consultar(['evaluaciones' => [], 'incluir_segunda_instancia' => '1'])['meta']['total']);
    }

    public function test_cambios_de_habilitacion_preservan_las_otras_evaluaciones_y_detectan_conflictos(): void
    {
        $controller = new MonitoreoCalificacionesController();
        $cambiar = function ($estado, $numero, $habilitar) use ($controller) {
            $request = Request::create('/', 'PUT', ['anio_id' => 2026, 'institucion_id' => 1, 'estado_actual' => $estado, 'evaluacion' => $numero, 'habilitar' => $habilitar]);
            $request->setUserResolver(fn () => (object) ['instituciones_id' => 1]);
            return $controller->cambiarHabilitacion($request, 1);
        };
        $this->assertSame('PRIMERA EVALUACIÓN', $cambiar(null, 1, true)->getData(true)['estado_habilitacion']);
        $this->assertSame('PRIMERA EVALUACIÓN, SEGUNDA EVALUACIÓN', $cambiar('PRIMERA EVALUACIÓN', 2, true)->getData(true)['estado_habilitacion']);
        $this->assertSame(409, $cambiar('PRIMERA EVALUACIÓN', 1, false)->getStatusCode());
        $this->assertSame('SEGUNDA INSTANCIA', $cambiar('PRIMERA EVALUACIÓN, SEGUNDA EVALUACIÓN', 5, true)->getData(true)['estado_habilitacion']);
        $this->assertSame('NINGUNA EVALUACIÓN', $cambiar('SEGUNDA INSTANCIA', 5, false)->getData(true)['estado_habilitacion']);
        $this->assertSame(422, $cambiar('NINGUNA EVALUACIÓN', 4, true)->getStatusCode());
    }

    public function test_pagina_los_resultados_y_no_permita_otra_institucion(): void
    {
        for ($i = 100; $i < 155; $i++) {
            DB::table('calificaciones')->insert(['id' => $i, 'materias_id' => 1, 'infoestudiantesifas_id' => 1, 'Teorico1' => null, 'Practico1' => 0]);
        }
        $pagina = $this->consultar(['page' => 2]);
        $this->assertSame(60, $pagina['meta']['total']);
        $this->assertCount(10, $pagina['data']);

        $request = Request::create('/', 'GET', ['anio_id' => 2026, 'institucion_id' => 2, 'evaluaciones' => [1], 'incluir_promedios' => '0']);
        $request->setUserResolver(fn () => (object) ['instituciones_id' => 1]);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new MonitoreoCalificacionesController())->buscar($request);
    }
}
