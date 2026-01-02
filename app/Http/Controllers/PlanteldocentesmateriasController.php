<?php

namespace App\Http\Controllers;

use App\Models\Planteldocentesmaterias;
use Illuminate\Http\Request;

use Illuminate\Routing\Controller;
use App\Http\Middleware\UpdateTokenExpiration;
use App\Models\Materias;
use App\Models\Planteldocentes;
class PlanteldocentesmateriasController extends Controller
{
   public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $isSuperAdmin = empty($user?->instituciones_id);

        $query = Planteldocentesmaterias::query()
            ->select('planteldocentesmaterias.*')
            ->join('planteldocentes', 'planteldocentesmaterias.planteldocentes_id', '=', 'planteldocentes.id');

        if (!$isSuperAdmin) {
            $query->where('planteldocentes.instituciones_id', $user->instituciones_id);
        }

        if ($request->filled('planteldocentes_id')) {
            $query->where('planteldocentesmaterias.planteldocentes_id', $request->get('planteldocentes_id'));
        }

        // Filtros opcionales: Año/Resolución (global para el asignador)
        $anioId = $request->query('anio_id');
        $resolucion = trim((string) $request->query('resolucion', ''));

        $filtraPorAnio = ($anioId !== null && $anioId !== '' && (int) $anioId > 0);
        $filtraPorResolucion = ($resolucion !== '');
        $filtraPorInstitucion = $request->filled('instituciones_id') || !$isSuperAdmin;

        if ($filtraPorAnio || $filtraPorResolucion || $filtraPorInstitucion) {
            $query
                ->join('materias', 'planteldocentesmaterias.materias_id', '=', 'materias.id')
                ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
                ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id');

            if ($filtraPorAnio) {
                $query->where('plandeestudios.anio_id', (int) $anioId);
            }

            if ($filtraPorResolucion) {
                $query->where('carreras.Resolucion', $resolucion);
            }

            $institucionId = null;
            if (!$isSuperAdmin) {
                $institucionId = (int) $user->instituciones_id;
            } elseif ($request->filled('instituciones_id')) {
                $institucionId = (int) $request->get('instituciones_id');
            }

            if (!empty($institucionId)) {
                // Restringir por institución para que el conteo sea coherente
                $query->where('carreras.instituciones_id', $institucionId);
                $query->where('planteldocentes.instituciones_id', $institucionId);
            }
        }

        return response()->json(['data' => $query->get()]);
    }

    public function store(Request $request)
    {
        return $this->assign($request);
    }

    public function show(Request $request, $id)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $isSuperAdmin = empty($user?->instituciones_id);

        $row = Planteldocentesmaterias::query()
            ->select('planteldocentesmaterias.*')
            ->join('planteldocentes', 'planteldocentesmaterias.planteldocentes_id', '=', 'planteldocentes.id')
            ->when(!$isSuperAdmin, function ($q) use ($user) {
                $q->where('planteldocentes.instituciones_id', $user->instituciones_id);
            })
            ->where('planteldocentesmaterias.id', $id)
            ->firstOrFail();

        return response()->json(['data' => $row]);
    }

    public function update(Request $request, $id)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $isSuperAdmin = empty($user?->instituciones_id);

        $assignment = Planteldocentesmaterias::query()
            ->join('planteldocentes', 'planteldocentesmaterias.planteldocentes_id', '=', 'planteldocentes.id')
            ->when(!$isSuperAdmin, function ($q) use ($user) {
                $q->where('planteldocentes.instituciones_id', $user->instituciones_id);
            })
            ->where('planteldocentesmaterias.id', $id)
            ->select('planteldocentesmaterias.*')
            ->firstOrFail();

        $data = $request->only(['Paralelo', 'EstadoHabilitacion', 'EstadoEnvio']);
        $assignment->update($data);

        return response()->json(['data' => $assignment]);
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $isSuperAdmin = empty($user?->instituciones_id);

        $deleted = Planteldocentesmaterias::query()
            ->join('planteldocentes', 'planteldocentesmaterias.planteldocentes_id', '=', 'planteldocentes.id')
            ->when(!$isSuperAdmin, function ($q) use ($user) {
                $q->where('planteldocentes.instituciones_id', $user->instituciones_id);
            })
            ->where('planteldocentesmaterias.id', $id)
            ->delete();

        return response()->json(['data' => $deleted ? 'ELIMINADO EXITOSAMENTE' : 'NO ENCONTRADO']);
    }

    public function assign(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $isSuperAdmin = empty($user?->instituciones_id);

        $validated = $request->validate([
            'planteldocentes_id' => ['required', 'integer'],
            'materias_id' => ['required', 'integer'],
            'Paralelo' => ['nullable', 'string', 'max:50'],
        ]);

        $docenteInstitucionId = Planteldocentes::query()
            ->where('id', $validated['planteldocentes_id'])
            ->value('instituciones_id');

        if (!$docenteInstitucionId) {
            return response()->json(['message' => 'Docente no encontrado'], 404);
        }

        $materiaInstitucionId = Materias::query()
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
            ->where('materias.id', $validated['materias_id'])
            ->value('carreras.instituciones_id');

        if (!$materiaInstitucionId) {
            return response()->json(['message' => 'Materia no encontrada'], 404);
        }

        if (!$isSuperAdmin) {
            if ((int) $docenteInstitucionId !== (int) $user->instituciones_id) {
                return response()->json(['message' => 'Docente no pertenece a la institución'], 403);
            }
            if ((int) $materiaInstitucionId !== (int) $user->instituciones_id) {
                return response()->json(['message' => 'Materia no pertenece a la institución'], 403);
            }
        } else {
            // Superadmin: evitar asignaciones cruzadas entre instituciones
            if ((int) $docenteInstitucionId !== (int) $materiaInstitucionId) {
                return response()->json(['message' => 'Docente y materia pertenecen a instituciones distintas'], 422);
            }
        }

        // Validaciones por ModoMateria (reglas de asignación)
        $modoMateria = Materias::query()
            ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
            ->where('materias.id', $validated['materias_id'])
            ->value('plandeestudios.ModoMateria');

        $modoNorm = mb_strtoupper(trim((string) $modoMateria), 'UTF-8');

        $esInstrumentoEspecialidad = (str_contains($modoNorm, 'INSTRUMENT') && str_contains($modoNorm, 'ESPECIAL'));
        if ($esInstrumentoEspecialidad) {
            return response()->json([
                'message' => 'NO ES NECESARIO ASIGNAR MATERIAS DE INSTRUMENTO DE ESPECIALIDAD PORQUE LA DETECCION DE ESTUDIANTES SE HACE AUTOMATICO DESDE INSCRIPCIONES DE ESTUDIANTES.'
            ], 422);
        }

        $esPracticaConjuntos = (
            (str_contains($modoNorm, 'PRACTICA') || str_contains($modoNorm, 'PRÁCTICA'))
            && str_contains($modoNorm, 'CONJUNTO')
        );
        if ($esPracticaConjuntos) {
            return response()->json([
                'message' => 'NO ES NECESARIO ASIGNAR MATERIAS DE PRÁCTICA DE CONJUNTOS PORQUE LA DETECCION DE ESTUDIANTES SE HACE AUTOMATICO DESDE INSCRIPCIONES DE ESTUDIANTES.'
            ], 422);
        }

        $esUnDocentePorMateria = (str_contains($modoNorm, '1') && str_contains($modoNorm, 'DOCENTE'));
        if ($esUnDocentePorMateria) {
            $otrosAsignados = Planteldocentesmaterias::query()
                ->where('materias_id', $validated['materias_id'])
                ->where('planteldocentes_id', '!=', $validated['planteldocentes_id'])
                ->exists();

            if ($otrosAsignados) {
                $nombres = Planteldocentesmaterias::query()
                    ->join('planteldocentes', 'planteldocentesmaterias.planteldocentes_id', '=', 'planteldocentes.id')
                    ->where('planteldocentesmaterias.materias_id', $validated['materias_id'])
                    ->select(['planteldocentes.id', 'planteldocentes.Nombres', 'planteldocentes.Apellidos'])
                    ->get()
                    ->map(function ($d) {
                        $nombre = trim(((string) ($d->Nombres ?? '') . ' ' . (string) ($d->Apellidos ?? '')));
                        return $nombre !== '' ? $nombre : ('#' . (string) $d->id);
                    })
                    ->filter()
                    ->values()
                    ->implode(', ');

                $msg = 'No se puede asignar: esta materia es "1 DOCENTE x MATERIA" y ya tiene docente asignado.';
                if (!empty($nombres)) {
                    $msg .= ' Docente(s): ' . $nombres;
                }
                return response()->json(['message' => $msg], 422);
            }
        }

        $assignment = Planteldocentesmaterias::query()->firstOrCreate(
            [
                'planteldocentes_id' => $validated['planteldocentes_id'],
                'materias_id' => $validated['materias_id'],
            ],
            [
                'Paralelo' => $validated['Paralelo'] ?? null,
                'EstadoHabilitacion' => $request->input('EstadoHabilitacion'),
                'EstadoEnvio' => $request->input('EstadoEnvio'),
            ]
        );

        if ($request->filled('Paralelo') && $assignment->Paralelo !== $request->input('Paralelo')) {
            $assignment->Paralelo = $request->input('Paralelo');
            $assignment->save();
        }

        return response()->json(['data' => $assignment]);
    }

    public function unassign(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $isSuperAdmin = empty($user?->instituciones_id);

        $validated = $request->validate([
            'planteldocentes_id' => ['required', 'integer'],
            'materias_id' => ['required', 'integer'],
        ]);

        $docenteInstitucionId = Planteldocentes::query()
            ->where('id', $validated['planteldocentes_id'])
            ->value('instituciones_id');

        if (!$docenteInstitucionId) {
            return response()->json(['message' => 'Docente no encontrado'], 404);
        }

        if (!$isSuperAdmin && (int) $docenteInstitucionId !== (int) $user->instituciones_id) {
            return response()->json(['message' => 'Docente no pertenece a la institución'], 403);
        }

        $deleted = Planteldocentesmaterias::query()
            ->where('planteldocentes_id', $validated['planteldocentes_id'])
            ->where('materias_id', $validated['materias_id'])
            ->delete();

        return response()->json(['data' => ['deleted' => $deleted]]);
    }
    //#endregion Fin Controller de Crud PHP de planteldocentesmaterias
}
