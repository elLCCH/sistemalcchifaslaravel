<?php

namespace Tests\Feature;

use App\Http\Controllers\CalificacionesController;
use App\Models\Planteldocentes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CalificacionesEdicionTemporalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');

        Schema::create('anios', function (Blueprint $t) {
            $t->integer('id'); $t->string('EdicionCalificaciones');
        });
        Schema::create('carreras', function (Blueprint $t) {
            $t->integer('id'); $t->integer('instituciones_id'); $t->integer('CantidadEvaluaciones');
            $t->integer('LimiteMaxTeorico'); $t->integer('LimiteMaxPractico');
        });
        Schema::create('plandeestudios', function (Blueprint $t) {
            $t->integer('id'); $t->integer('carreras_id'); $t->integer('anio_id');
        });
        Schema::create('materias', function (Blueprint $t) {
            $t->integer('id'); $t->integer('plandeestudios_id'); $t->string('EstadoHabilitacion');
        });
        Schema::create('planteldocentesmaterias', function (Blueprint $t) {
            $t->integer('planteldocentes_id'); $t->integer('materias_id');
        });
        Schema::create('calificaciones', function (Blueprint $t) {
            $t->integer('id')->primary(); $t->integer('materias_id');
            foreach (['Teorico1', 'Teorico2', 'Teorico3', 'Teorico4', 'Practico1', 'Practico2', 'Practico3', 'Practico4', 'PromTeorico', 'PromPractico', 'Promedio', 'PruebaRecuperacion'] as $field) {
                $t->integer($field)->nullable();
            }
            $t->timestamps();
        });

        DB::table('anios')->insert(['id' => 1, 'EdicionCalificaciones' => 'HABILITADO']);
        DB::table('carreras')->insert(['id' => 1, 'instituciones_id' => 1, 'CantidadEvaluaciones' => 3, 'LimiteMaxTeorico' => 30, 'LimiteMaxPractico' => 70]);
        DB::table('plandeestudios')->insert(['id' => 1, 'carreras_id' => 1, 'anio_id' => 1]);
        DB::table('materias')->insert(['id' => 1, 'plandeestudios_id' => 1, 'EstadoHabilitacion' => 'SEGUNDA INSTANCIA']);
        DB::table('planteldocentesmaterias')->insert(['planteldocentes_id' => 5, 'materias_id' => 1]);
        DB::table('calificaciones')->insert(['id' => 1, 'materias_id' => 1, 'Teorico3' => 10, 'Practico3' => 20, 'Teorico4' => 7]);
    }

    private function guardar($user, bool $temporal, array $enabled = [true, true, true, false])
    {
        $request = Request::create('/', 'POST', [
            'materias_id' => 1, 'avg_eval_count' => 3, 'enabled_evals' => $enabled,
            'admin_temp_enabled' => $temporal,
            'items' => [[
                'id' => 1, 'Teorico3' => 25, 'Practico3' => 60, 'Teorico4' => 30,
                'PromTeorico' => 20, 'PromPractico' => 45, 'Promedio' => 65, 'PruebaRecuperacion' => 70,
            ]],
        ]);
        $request->setUserResolver(fn () => $user);
        return (new CalificacionesController())->bulkUpdateMateria($request);
    }

    public function test_admin_guarda_evaluaciones_habilitadas_y_promedios_en_segunda_instancia(): void
    {
        $response = $this->guardar((object) ['instituciones_id' => null], true);
        $this->assertSame(200, $response->getStatusCode());
        $row = DB::table('calificaciones')->where('id', 1)->first();
        $this->assertSame(25, $row->Teorico3);
        $this->assertSame(60, $row->Practico3);
        $this->assertSame(7, $row->Teorico4);
        $this->assertSame(20, $row->PromTeorico);
        $this->assertSame(45, $row->PromPractico);
        $this->assertSame(65, $row->Promedio);
        $this->assertSame(70, $row->PruebaRecuperacion);
    }

    public function test_sin_edicion_temporal_no_modifica_evaluaciones_en_segunda_instancia(): void
    {
        $this->guardar((object) ['instituciones_id' => null], false);
        $row = DB::table('calificaciones')->where('id', 1)->first();
        $this->assertSame(10, $row->Teorico3);
        $this->assertSame(20, $row->Practico3);
        $this->assertSame(70, $row->PruebaRecuperacion);
    }

    public function test_docente_no_puede_activar_edicion_temporal(): void
    {
        $docente = new Planteldocentes();
        $docente->id = 5;
        $docente->instituciones_id = 1;
        $response = $this->guardar($docente, true);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(10, DB::table('calificaciones')->where('id', 1)->value('Teorico3'));
    }

    public function test_edicion_temporal_respeta_evaluaciones_seleccionadas_y_gestion(): void
    {
        $this->guardar((object) ['instituciones_id' => null], true, [true, true, false, false]);
        $this->assertSame(10, DB::table('calificaciones')->where('id', 1)->value('Teorico3'));
        DB::table('anios')->where('id', 1)->update(['EdicionCalificaciones' => 'DESHABILITADO']);
        $this->assertSame(403, $this->guardar((object) ['instituciones_id' => null], true)->getStatusCode());
    }

    public function test_evaluacion_final_sin_evaluaciones_acepta_promedios_vacios(): void
    {
        DB::table('materias')->where('id', 1)->update(['EstadoHabilitacion' => 'EVALUACION FINAL']);
        DB::table('carreras')->where('id', 1)->update(['CantidadEvaluaciones' => 0]);
        $request = Request::create('/', 'POST', [
            'materias_id' => 1,
            'items' => [['id' => 1, 'PromTeorico' => null, 'PromPractico' => 35, 'Promedio' => null]],
        ]);
        $request->setUserResolver(fn () => (object) ['instituciones_id' => null]);
        $response = (new CalificacionesController())->bulkUpdateMateria($request);
        $this->assertSame(200, $response->getStatusCode());
        $row = DB::table('calificaciones')->where('id', 1)->first();
        $this->assertNull($row->PromTeorico);
        $this->assertSame(35, $row->PromPractico);
        $this->assertNull($row->Promedio);
    }
}
