<?php

namespace App\Http\Controllers;

use App\Models\AsistenciaRegistro;
use App\Models\AsistenciaSesion;
use App\Models\AsistenciaQrToken;
use App\Models\AulaVirtual;
use App\Models\Planteldocentes;
use App\Models\Planteladministrativos;
use App\Models\Usuarioslcchs;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class AsistenciaSesionController extends Controller
{
    private string $modoInstrumentosEspecialidad = 'MODO INSTRUMENTOS DE ESPECIALIDAD';
    private string $modoPracticaConjuntos = 'MODO PRÁCTICA DE CONJUNTOS';
    private string $modoInstrumentoComplementario = 'MODO INSTRUMENTO COMPLEMENTARIO';

    private function normModoAsistencia($v): string
    {
        $s = trim((string) ($v ?? ''));
        if ($s === '' || strtolower($s) === 'null' || strtolower($s) === 'undefined') {
            return 'NORMAL';
        }
        return mb_strtoupper($s, 'UTF-8');
    }

    private function modoPermiteQr(string $modo): bool
    {
        return str_contains($modo, 'QR');
    }

    private function modoPermiteVirtual(string $modo): bool
    {
        return str_contains($modo, 'REGISTRO VIRTUAL');
    }

    private function modoEsEstrictoQr(string $modo): bool
    {
        return $modo === mb_strtoupper('POR QR (ESTRICTO)', 'UTF-8');
    }

    private function licenciasTable(): string
    {
        // Compatibilidad: si ya existe la nueva tabla, se usa como fuente de LICENCIAS.
        // Si no existe, seguir usando la tabla legacy `permisos_asistencia`.
        return Schema::hasTable('licenciasestudiantesifas') ? 'licenciasestudiantesifas' : 'permisos_asistencia';
    }

    private function sesionMateriaContext(AsistenciaSesion $sesion): array
    {
        $row = DB::table('aulas_virtuales as av')
            ->leftJoin('materias as m', 'm.id', '=', 'av.materias_id')
            ->leftJoin('plandeestudios as p', 'p.id', '=', 'm.plandeestudios_id')
            ->leftJoin('carreras as c', 'c.id', '=', 'p.carreras_id')
            ->where('av.id', (int) $sesion->aulas_virtuales_id)
            ->first([
                'av.materias_id as materia_id',
                'm.ModoAsistencia as materia_modo_asistencia',
                'm.Paralelo as paralelo',
                'm.Turno as turno',
                'p.ModoMateria as plande_modo_materia',
                'p.NombreMateria as nombre_materia',
                'p.SiglaMateria as sigla_materia',
                'p.LvlCurso as lvl_curso',
                'c.NombreCarrera as nombre_carrera',
            ]);

        $modo = $this->normModoAsistencia($row->materia_modo_asistencia ?? null);

        return [
            'materia_id' => $row?->materia_id ? (int) $row->materia_id : null,
            'materia_modo_asistencia' => $modo,
            'plande_modo_materia' => $row?->plande_modo_materia,
            'nombre_materia' => $row?->nombre_materia,
            'sigla_materia' => $row?->sigla_materia,
            'paralelo' => $row?->paralelo,
            'turno' => $row?->turno,
            'lvl_curso' => $row?->lvl_curso,
            'nombre_carrera' => $row?->nombre_carrera,
        ];
    }

    private function estudiantesQueryPorSesion(AsistenciaSesion $sesion)
    {
        $ctx = $this->sesionMateriaContext($sesion);
        $materiaId = (int) ($ctx['materia_id'] ?? 0);
        if ($materiaId <= 0) {
            return null;
        }

        $q = DB::table('calificaciones as cal')
            ->join('infoestudiantesifas as ie', 'cal.infoestudiantesifas_id', '=', 'ie.id')
            ->join('estudiantesifas as e', 'e.id', '=', 'ie.estudiantesifas_id')
            ->where('cal.materias_id', $materiaId)
            ->where('ie.instituciones_id', (int) $sesion->instituciones_id);

        $modoMateria = (string) ($ctx['plande_modo_materia'] ?? '');

        if ($modoMateria === $this->modoInstrumentosEspecialidad) {
            $q->where('ie.planteldocadmins_id', (int) $sesion->planteldocentes_id);
        } elseif ($modoMateria === $this->modoPracticaConjuntos) {
            $q->where('ie.planteldocadmins_idPC', (int) $sesion->planteldocentes_id);
        } elseif ($modoMateria === $this->modoInstrumentoComplementario) {
            $q->where('ie.planteldocadmins_idOtros', (int) $sesion->planteldocentes_id);
        }

        return $q;
    }

    public function openByMateria(Request $request, $materiaId)
    {
        $user = $request->user();
        if (!$user || !($user instanceof Planteldocentes)) {
            return response()->json(['ok' => false, 'message' => 'Solo docentes pueden abrir asistencia por materia.'], 403);
        }

        $materiaId = (int) $materiaId;
        if ($materiaId <= 0) {
            return response()->json(['ok' => false, 'message' => 'Materia inválida.'], 422);
        }

        $validator = Validator::make($request->all(), [
            'fecha' => 'nullable|date',
            'hora_ingreso' => 'nullable|date',
            'tiempo_espera_minutos' => 'nullable|integer|min:1|max:120',
            'gps_requerido' => 'nullable|boolean',
            'radio_metros' => 'nullable|integer|min:1|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json(['ok' => false, 'errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();

        // Verifica institución propietaria de la materia
        $mat = DB::table('materias as m')
            ->join('plandeestudios as p', 'm.plandeestudios_id', '=', 'p.id')
            ->join('carreras as c', 'p.carreras_id', '=', 'c.id')
            ->join('instituciones as i', 'c.instituciones_id', '=', 'i.id')
            ->where('m.id', $materiaId)
            ->first([
                'm.id as materia_id',
                'm.ModoAsistencia as materia_modo_asistencia',
                'p.ModoMateria as plande_modo_materia',
                'p.NombreMateria as nombre_materia',
                'p.SiglaMateria as sigla_materia',
                'i.id as instituciones_id',
            ]);

        if (!$mat) {
            return response()->json(['ok' => false, 'message' => 'Materia no encontrada.'], 404);
        }

        $institucionId = (int) ($mat->instituciones_id ?? 0);
        if ($institucionId <= 0 || (int) $user->instituciones_id !== $institucionId) {
            return response()->json(['ok' => false, 'message' => 'No tienes acceso a esta institución/materia.'], 403);
        }

        $modoAsistencia = $this->normModoAsistencia($mat->materia_modo_asistencia ?? null);
        if ($modoAsistencia === 'NORMAL') {
            return response()->json(['ok' => false, 'message' => 'Esta materia está en modo NORMAL (sin asistencias).'], 409);
        }

        // Verifica que el docente tenga relación con la materia (asignado o por modos especiales con estudiantes)
        $asignado = DB::table('planteldocentesmaterias')
            ->where('planteldocentes_id', (int) $user->id)
            ->where('materias_id', $materiaId)
            ->exists();

        $tieneEstudiantes = false;
        if (!$asignado) {
            $q = DB::table('calificaciones as cal')
                ->join('infoestudiantesifas as ie', 'cal.infoestudiantesifas_id', '=', 'ie.id')
                ->where('cal.materias_id', $materiaId)
                ->where('ie.instituciones_id', $institucionId);

            $modoMateria = (string) ($mat->plande_modo_materia ?? '');
            if ($modoMateria === $this->modoInstrumentosEspecialidad) {
                $q->where('ie.planteldocadmins_id', (int) $user->id);
            } elseif ($modoMateria === $this->modoPracticaConjuntos) {
                $q->where('ie.planteldocadmins_idPC', (int) $user->id);
            } elseif ($modoMateria === $this->modoInstrumentoComplementario) {
                $q->where('ie.planteldocadmins_idOtros', (int) $user->id);
            }

            $tieneEstudiantes = $q->exists();
        }

        if (!$asignado && !$tieneEstudiantes) {
            return response()->json(['ok' => false, 'message' => 'No tienes estudiantes asignados en esta materia.'], 403);
        }

        // Asegurar aula_virtual vinculada a la materia
        $aula = AulaVirtual::query()
            ->where('instituciones_id', $institucionId)
            ->where('materias_id', $materiaId)
            ->first();

        if (!$aula) {
            $aula = AulaVirtual::create([
                'instituciones_id' => $institucionId,
                'materias_id' => $materiaId,
                'nombre' => trim(((string) ($mat->sigla_materia ?? '')) . ' ' . ((string) ($mat->nombre_materia ?? ''))),
                'descripcion' => 'Aula automática para asistencias',
                'estado' => 'ACTIVO',
                'visibilidad' => 'VISIBLE',
            ]);
        }

        $fecha = $payload['fecha'] ?? date('Y-m-d');
        $horaIngreso = $payload['hora_ingreso'] ?? date('Y-m-d H:i:s');
        $tiempoEspera = $payload['tiempo_espera_minutos'] ?? 20;
        $gpsRequerido = array_key_exists('gps_requerido', $payload) ? (int) $payload['gps_requerido'] : 1;
        $radio = $payload['radio_metros'] ?? 150;

        // Si existe sesión diaria, devolverla. Si no, crear.
        $sesion = AsistenciaSesion::query()
            ->where('aulas_virtuales_id', (int) $aula->id)
            ->whereDate('fecha', $fecha)
            ->first();

        if (!$sesion) {
            $sesion = AsistenciaSesion::create([
                'instituciones_id' => $institucionId,
                'aulas_virtuales_id' => (int) $aula->id,
                'planteldocentes_id' => (int) $user->id,
                'fecha' => $fecha,
                'hora_ingreso' => $horaIngreso,
                'tiempo_espera_minutos' => $tiempoEspera,
                'minutos_falta' => 40,
                'gps_requerido' => $gpsRequerido,
                'radio_metros' => $radio,
                'estado' => 'ABIERTA',
                'visibilidad' => 'VISIBLE',
            ]);
        }

        return response()->json([
            'ok' => true,
            'sesion' => $sesion,
            'materia' => [
                'id' => $materiaId,
                'modo_asistencia' => $modoAsistencia,
                'modo_materia' => $mat->plande_modo_materia ?? null,
            ],
        ]);
    }

    public function index(Request $request)
    {
        $fecha = $request->query('fecha');

        $q = AsistenciaSesion::query();

        if ($fecha) {
            $q->whereDate('fecha', $fecha);
        }

        // Multi-institución: si el token trae instituciones_id en abilities/meta, úsalo.
        $institucionId = $request->user()->instituciones_id ?? null;
        if ($institucionId) {
            $q->where('instituciones_id', $institucionId);
        }

        return response()->json([
            'ok' => true,
            'sesiones' => $q->orderByDesc('hora_ingreso')->limit(200)->get(),
        ]);
    }

    /**
     * Lista todas las sesiones de asistencia de una materia específica (para el docente).
     * Incluye conteos de estados y nombre de la materia.
     */
    public function listByMateria(Request $request, $materiaId)
    {
        $user = $request->user();
        $isDocente = ($user instanceof Planteldocentes);
        $isAdmin   = ($user instanceof Planteladministrativos) || ($user instanceof Usuarioslcchs);
        if (!$user || (!$isDocente && !$isAdmin)) {
            return response()->json(['ok' => false, 'message' => 'Solo docentes o administrativos pueden consultar sesiones por materia.'], 403);
        }

        $materiaId = (int) $materiaId;
        if ($materiaId <= 0) {
            return response()->json(['ok' => false, 'message' => 'Materia inválida.'], 422);
        }

        // Obtener info de la materia
        $mat = DB::table('materias as m')
            ->join('plandeestudios as p', 'm.plandeestudios_id', '=', 'p.id')
            ->join('carreras as c', 'p.carreras_id', '=', 'c.id')
            ->join('instituciones as i', 'c.instituciones_id', '=', 'i.id')
            ->where('m.id', $materiaId)
            ->first([
                'm.id as materia_id',
                'm.ModoAsistencia as materia_modo_asistencia',
                'm.Paralelo as paralelo',
                'm.Turno as turno',
                'p.ModoMateria as plande_modo_materia',
                'p.NombreMateria as nombre_materia',
                'p.SiglaMateria as sigla_materia',
                'p.LvlCurso as lvl_curso',
                'c.NombreCarrera as nombre_carrera',
                'i.id as instituciones_id',
            ]);

        if (!$mat) {
            return response()->json(['ok' => false, 'message' => 'Materia no encontrada.'], 404);
        }

        // Verificar que el docente pertenezca a la misma institución
        $institucionId = (int) ($mat->instituciones_id ?? 0);
        if ($institucionId <= 0 || (int) $user->instituciones_id !== $institucionId) {
            return response()->json(['ok' => false, 'message' => 'No tienes acceso a esta materia.'], 403);
        }

        // Buscar aula(s) vinculadas a la materia
        $aulas = AulaVirtual::query()
            ->where('instituciones_id', $institucionId)
            ->where('materias_id', $materiaId)
            ->pluck('id');

        $materiaInfo = [
            'materia_id' => $materiaId,
            'nombre_materia' => $mat->nombre_materia,
            'sigla_materia' => $mat->sigla_materia,
            'materia_modo_asistencia' => $this->normModoAsistencia($mat->materia_modo_asistencia),
            'paralelo' => $mat->paralelo ?? null,
            'turno' => $mat->turno ?? null,
            'lvl_curso' => $mat->lvl_curso ?? null,
            'nombre_carrera' => $mat->nombre_carrera ?? null,
        ];

        if ($aulas->isEmpty()) {
            return response()->json([
                'ok' => true,
                'materia' => $materiaInfo,
                'sesiones' => [],
            ]);
        }

        // Obtener sesiones ordenadas por fecha descendente
        $sesiones = AsistenciaSesion::query()
            ->whereIn('aulas_virtuales_id', $aulas->all())
            ->orderByDesc('fecha')
            ->limit(200)
            ->get();

        // Para cada sesión, agregar conteos de asistencia
        $sesionesConConteo = $sesiones->map(function ($s) {
            $registros = AsistenciaRegistro::query()
                ->where('asistencias_sesiones_id', (int) $s->id)
                ->get(['estado_asistencia']);

            $conteos = [
                'PRESENTE' => 0,
                'ATRASO' => 0,
                'FALTA' => 0,
                'PERMISO' => 0,
            ];

            foreach ($registros as $r) {
                $e = strtoupper(trim((string) ($r->estado_asistencia ?? '')));
                if (isset($conteos[$e])) {
                    $conteos[$e]++;
                }
            }

            $arr = $s->toArray();
            // Asegurar formato Y-m-d para fecha (evitar ISO full date del cast)
            $arr['fecha'] = $s->fecha ? $s->fecha->format('Y-m-d') : null;
            $arr['conteos'] = $conteos;
            $arr['total_registros'] = $registros->count();
            return $arr;
        });

        return response()->json([
            'ok' => true,
            'materia' => $materiaInfo,
            'sesiones' => $sesionesConConteo,
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'instituciones_id' => 'required|integer',
            'aulas_virtuales_id' => 'required|integer',
            'fecha' => 'nullable|date',
            'hora_ingreso' => 'nullable|date',
            'tiempo_espera_minutos' => 'nullable|integer|min:1|max:120',
            'gps_requerido' => 'nullable|boolean',
            'radio_metros' => 'nullable|integer|min:1|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json(['ok' => false, 'errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();

        $fecha = $payload['fecha'] ?? date('Y-m-d');
        $horaIngreso = $payload['hora_ingreso'] ?? date('Y-m-d H:i:s');

        $tiempoEspera = $payload['tiempo_espera_minutos'] ?? 20;
        $gpsRequerido = array_key_exists('gps_requerido', $payload) ? (int) $payload['gps_requerido'] : 1;
        $radio = $payload['radio_metros'] ?? 150;

        $docenteId = $request->user()->id ?? null;

        if (!$docenteId) {
            return response()->json(['ok' => false, 'message' => 'No se pudo determinar el docente autenticado.'], 401);
        }

        try {
            $sesion = AsistenciaSesion::create([
                'instituciones_id' => $payload['instituciones_id'],
                'aulas_virtuales_id' => $payload['aulas_virtuales_id'],
                'planteldocentes_id' => $docenteId,
                'fecha' => $fecha,
                'hora_ingreso' => $horaIngreso,
                'tiempo_espera_minutos' => $tiempoEspera,
                'minutos_falta' => 40,
                'gps_requerido' => $gpsRequerido,
                'radio_metros' => $radio,
                'estado' => 'ABIERTA',
                'visibilidad' => 'VISIBLE',
            ]);

            return response()->json(['ok' => true, 'sesion' => $sesion]);
        } catch (\Throwable $e) {
            // Si ya existe la sesión diaria (UNIQUE) devolvemos mensaje amigable.
            $msg = $e->getMessage();
            if (str_contains($msg, 'uq_asist_sesion_diaria') || str_contains($msg, 'Duplicate entry')) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Ya existe una sesión de asistencia para esta aula en esa fecha.',
                ], 409);
            }

            return response()->json(['ok' => false, 'message' => 'Error creando sesión.', 'error' => $msg], 500);
        }
    }

    public function show(Request $request, $id)
    {
        $sesion = AsistenciaSesion::find($id);

        if (!$sesion) {
            return response()->json(['ok' => false, 'message' => 'Sesión no encontrada'], 404);
        }

        $ctx = $this->sesionMateriaContext($sesion);

        return response()->json([
            'ok' => true,
            'sesion' => $sesion,
            'materia' => $ctx,
        ]);
    }

    public function registros(Request $request, $id)
    {
        $sesion = AsistenciaSesion::find($id);
        if (!$sesion) {
            return response()->json(['ok' => false, 'message' => 'Sesión no encontrada'], 404);
        }

        $registros = AsistenciaRegistro::query()
            ->where('asistencias_sesiones_id', $sesion->id)
            ->orderByDesc('fecha_registro')
            ->limit(5000)
            ->get();

        return response()->json(['ok' => true, 'sesion' => $sesion, 'registros' => $registros]);
    }

    public function estudiantes(Request $request, $id)
    {
        $sesion = AsistenciaSesion::find($id);
        if (!$sesion) {
            return response()->json(['ok' => false, 'message' => 'Sesión no encontrada'], 404);
        }

        $ctx = $this->sesionMateriaContext($sesion);
        $base = $this->estudiantesQueryPorSesion($sesion);
        if (!$base) {
            return response()->json(['ok' => false, 'message' => 'La sesión no está vinculada a una materia.'], 409);
        }

        // Listado de estudiantes por materia (según modo especial) + su estado en esta sesión (si existe registro)
        $rows = $base
            ->leftJoin('asistencias_registros as ar', function ($join) use ($sesion) {
                $join->on('ar.infoestudiantesifas_id', '=', 'ie.id')
                    ->where('ar.asistencias_sesiones_id', '=', (int) $sesion->id);
            })
            ->orderBy('e.Ap_Paterno')
            ->orderBy('e.Ap_Materno')
            ->orderBy('e.Nombre')
            ->distinct()
            ->get([
                'ie.id as infoestudiantesifas_id',
                'ie.estudiantesifas_id as estudiantesifas_id',
                'e.Ap_Paterno as ap_paterno',
                'e.Ap_Materno as ap_materno',
                'e.Nombre as nombre',
                'ar.estado_asistencia as estado_asistencia',
                'ar.metodo as metodo',
                'ar.fecha_registro as fecha_registro',
            ]);

        // `tiene_permiso` se considera SOLO cuando ya existe un registro PERMISO en esta sesión.
        // (La licencia se "aplica" explícitamente desde secretaría.)
        $estudiantes = $rows->map(function ($r) {
            $infoId = (int) $r->infoestudiantesifas_id;
            $estadoReg = strtoupper(trim((string) ($r->estado_asistencia ?? '')));
            $tienePermiso = ($estadoReg === 'PERMISO');

            return [
                'infoestudiantesifas_id' => $infoId,
                'estudiantesifas_id' => (int) $r->estudiantesifas_id,
                'nombre_completo' => trim(($r->ap_paterno ?? '') . ' ' . ($r->ap_materno ?? '') . ' ' . ($r->nombre ?? '')),
                'estado_asistencia' => $r->estado_asistencia,
                'metodo' => $r->metodo,
                'fecha_registro' => $r->fecha_registro,
                'tiene_permiso' => $tienePermiso,
            ];
        });

        return response()->json([
            'ok' => true,
            'sesion' => $sesion,
            'materia' => $ctx,
            'estudiantes' => $estudiantes,
        ]);
    }

    public function marcar(Request $request, $id)
    {
        $user = $request->user();
        $isDocente = ($user instanceof Planteldocentes);
        $isAdmin   = ($user instanceof Planteladministrativos) || ($user instanceof Usuarioslcchs);
        if (!$user || (!$isDocente && !$isAdmin)) {
            return response()->json(['ok' => false, 'message' => 'Solo docentes o administrativos pueden marcar asistencia.'], 403);
        }

        $sesion = AsistenciaSesion::find($id);
        if (!$sesion) {
            return response()->json(['ok' => false, 'message' => 'Sesión no encontrada'], 404);
        }

        // Docentes solo pueden modificar sus propias sesiones; admin puede modificar cualquiera
        if (!$isAdmin && (int) $sesion->planteldocentes_id !== (int) $user->id) {
            return response()->json(['ok' => false, 'message' => 'No tienes permiso para modificar esta sesión.'], 403);
        }

        $ctx = $this->sesionMateriaContext($sesion);
        $modo = (string) ($ctx['materia_modo_asistencia'] ?? 'NORMAL');

        // Administrativos pueden marcar en cualquier modo y estado de sesión
        if (!$isAdmin) {
            if ($modo === 'NORMAL') {
                return response()->json(['ok' => false, 'message' => 'Materia en modo NORMAL: no se permite marcado.'], 409);
            }

            if ($this->modoEsEstrictoQr($modo)) {
                return response()->json(['ok' => false, 'message' => 'Modo POR QR (ESTRICTO): no se permite registro virtual.'], 409);
            }

            if (!$this->modoPermiteVirtual($modo)) {
                return response()->json(['ok' => false, 'message' => 'Este modo de asistencia no permite registro virtual.'], 409);
            }

            if ($sesion->estado !== 'ABIERTA') {
                return response()->json(['ok' => false, 'message' => 'La sesión no está abierta.'], 409);
            }
        }

        $validator = Validator::make($request->all(), [
            'infoestudiantesifas_id' => 'required|integer|min:1',
            'estado_asistencia' => 'required|string|max:20',
            'observacion' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['ok' => false, 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        $infoId = (int) $data['infoestudiantesifas_id'];
        $estadoIn = mb_strtoupper(trim((string) $data['estado_asistencia']), 'UTF-8');

        $map = [
            'P' => 'PRESENTE',
            'PRESENTE' => 'PRESENTE',
            'A' => 'ATRASO',
            'ATRASO' => 'ATRASO',
            'F' => 'FALTA',
            'FALTA' => 'FALTA',
        ];

        if (!isset($map[$estadoIn])) {
            return response()->json(['ok' => false, 'message' => 'Estado inválido. Use P/A/F.'], 422);
        }

        $estado = $map[$estadoIn];

        // Verifica pertenencia del estudiante a la sesión según materia y modo
        $base = $this->estudiantesQueryPorSesion($sesion);
        if (!$base) {
            return response()->json(['ok' => false, 'message' => 'Sesión sin materia asociada.'], 409);
        }

        $pertenece = (clone $base)
            ->where('ie.id', $infoId)
            ->exists();

        if (!$pertenece) {
            return response()->json(['ok' => false, 'message' => 'El estudiante no pertenece a esta lista de la sesión.'], 403);
        }

        $registro = AsistenciaRegistro::query()
            ->where('asistencias_sesiones_id', (int) $sesion->id)
            ->where('infoestudiantesifas_id', $infoId)
            ->first();

        // Si secretaría ya aplicó la licencia (registro PERMISO), el docente no puede modificarlo.
        // Administrativos sí pueden anular/cambiar el registro PERMISO.
        if (!$isAdmin && $registro && strtoupper(trim((string) $registro->estado_asistencia)) === 'PERMISO') {
            return response()->json([
                'ok' => false,
                'message' => 'El estudiante tiene LICENCIA aplicada (PERMISO). No se puede modificar desde docente.',
            ], 409);
        }

        if (!$registro) {
            $registro = AsistenciaRegistro::create([
                'asistencias_sesiones_id' => (int) $sesion->id,
                'infoestudiantesifas_id' => $infoId,
                'estado_asistencia' => $estado,
                'metodo' => 'MANUAL',
                'fecha_registro' => now(),
                'gps_valido' => 0,
                'estado' => 'ACTIVO',
                'visibilidad' => 'VISIBLE',
                'observacion' => $data['observacion'] ?? null,
            ]);
        } else {
            $registro->estado_asistencia = $estado;
            $registro->metodo = 'MANUAL';
            $registro->fecha_registro = now();
            $registro->observacion = $data['observacion'] ?? $registro->observacion;
            $registro->save();
        }

        return response()->json(['ok' => true, 'registro' => $registro]);
    }

    /**
     * Verifica si un estudiante tiene licencia vigente para la fecha dada.
     */
    private function estudianteTieneLicencia(int $infoestudiantesifasId, string $fecha): bool
    {
        $tabla = $this->licenciasTable();

        return DB::table($tabla)
            ->where('infoestudiantesifas_id', $infoestudiantesifasId)
            ->where('fecha_inicio', '<=', $fecha)
            ->where('fecha_fin', '>=', $fecha)
            ->exists();
    }

    public function cerrar(Request $request, $id)
    {
        $sesion = AsistenciaSesion::find($id);
        if (!$sesion) {
            return response()->json(['ok' => false, 'message' => 'Sesión no encontrada'], 404);
        }

        if ($sesion->estado === 'CERRADA') {
            return response()->json(['ok' => true, 'sesion' => $sesion, 'message' => 'La sesión ya estaba cerrada.']);
        }

        $result = DB::transaction(function () use ($sesion) {
            $sesion->estado = 'CERRADA';
            $sesion->save();

            $base = $this->estudiantesQueryPorSesion($sesion);
            if (!$base) {
                return [
                    'sesion' => $sesion,
                    'total_participantes' => 0,
                    'total_ya_marcados' => 0,
                    'total_permiso' => 0,
                    'total_falta' => 0,
                ];
            }

            $participantes = $base->distinct()->get(['ie.id as infoestudiantesifas_id']);
            $fechaSesion = (string) ($sesion->fecha ?? date('Y-m-d'));

            $totalMarcados = 0;
            $totalFalta = 0;
            $totalPermiso = 0;

            foreach ($participantes as $p) {
                $estudianteId = $p->infoestudiantesifas_id;
                if (!$estudianteId) {
                    continue;
                }

                $yaRegistrado = AsistenciaRegistro::query()
                    ->where('asistencias_sesiones_id', $sesion->id)
                    ->where('infoestudiantesifas_id', $estudianteId)
                    ->exists();

                if ($yaRegistrado) {
                    $totalMarcados++;
                    continue;
                }

                // Auto-aplicar licencia si el estudiante tiene una vigente para la fecha de la sesión
                $tieneLicencia = $this->estudianteTieneLicencia((int) $estudianteId, $fechaSesion);

                if ($tieneLicencia) {
                    AsistenciaRegistro::create([
                        'asistencias_sesiones_id' => $sesion->id,
                        'infoestudiantesifas_id' => $estudianteId,
                        'estado_asistencia' => 'PERMISO',
                        'metodo' => 'SISTEMA',
                        'fecha_registro' => now(),
                        'gps_valido' => 0,
                        'estado' => 'ACTIVO',
                        'visibilidad' => 'VISIBLE',
                        'observacion' => 'Licencia aplicada automáticamente al cerrar sesión',
                    ]);
                    $totalPermiso++;
                } else {
                    AsistenciaRegistro::create([
                        'asistencias_sesiones_id' => $sesion->id,
                        'infoestudiantesifas_id' => $estudianteId,
                        'estado_asistencia' => 'FALTA',
                        'metodo' => 'SISTEMA',
                        'fecha_registro' => now(),
                        'gps_valido' => 0,
                        'estado' => 'ACTIVO',
                        'visibilidad' => 'VISIBLE',
                        'observacion' => 'Cierre de sesión (no registró asistencia)',
                    ]);
                    $totalFalta++;
                }
            }

            return [
                'sesion' => $sesion,
                'total_participantes' => count($participantes),
                'total_ya_marcados' => $totalMarcados,
                'total_permiso' => $totalPermiso,
                'total_falta' => $totalFalta,
            ];
        });

        return response()->json(['ok' => true] + $result);
    }

    /**
     * Quitar/eliminar el registro de asistencia de un estudiante en una sesión.
     * Disponible para docentes (sus propias sesiones) y administrativos.
     */
    public function quitarAsistencia(Request $request, $id)
    {
        $user = $request->user();
        $isDocente = ($user instanceof Planteldocentes);
        $isAdmin   = ($user instanceof Planteladministrativos) || ($user instanceof Usuarioslcchs);
        if (!$user || (!$isDocente && !$isAdmin)) {
            return response()->json(['ok' => false, 'message' => 'Solo docentes o administrativos pueden quitar asistencia.'], 403);
        }

        $sesion = AsistenciaSesion::find($id);
        if (!$sesion) {
            return response()->json(['ok' => false, 'message' => 'Sesión no encontrada'], 404);
        }

        if (!$isAdmin && (int) $sesion->planteldocentes_id !== (int) $user->id) {
            return response()->json(['ok' => false, 'message' => 'No tienes permiso para modificar esta sesión.'], 403);
        }

        $infoId = (int) $request->input('infoestudiantesifas_id');
        if ($infoId <= 0) {
            return response()->json(['ok' => false, 'message' => 'ID de estudiante inválido.'], 422);
        }

        $registro = AsistenciaRegistro::query()
            ->where('asistencias_sesiones_id', (int) $sesion->id)
            ->where('infoestudiantesifas_id', $infoId)
            ->first();

        if (!$registro) {
            return response()->json(['ok' => false, 'message' => 'No existe registro de asistencia para este estudiante en esta sesión.'], 404);
        }

        $registro->delete();

        return response()->json(['ok' => true, 'message' => 'Registro de asistencia eliminado.']);
    }

    /**
     * Historial de asistencia de un estudiante en una materia (para el propio estudiante).
     * Devuelve las sesiones y el estado de asistencia del estudiante en cada una.
     */
    public function misAsistenciasMateria(Request $request, $materiaId)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['ok' => false, 'message' => 'No autenticado.'], 401);
        }

        $materiaId = (int) $materiaId;
        if ($materiaId <= 0) {
            return response()->json(['ok' => false, 'message' => 'Materia inválida.'], 422);
        }

        // Determinar infoestudiantesifas_id
        // Si es estudiante, buscar su propio info
        $isEstudiante = method_exists($user, 'getTable') && $user->getTable() === 'estudiantesifas';
        if (!$isEstudiante) {
            // También soportar docentes/admin que consulten (futuro), pero por ahora solo estudiantes
            $isEstudiante = ($user instanceof \App\Models\Estudiantesifas);
        }

        if (!$isEstudiante) {
            return response()->json(['ok' => false, 'message' => 'Solo estudiantes pueden consultar sus propias asistencias.'], 403);
        }

        // Buscar infoestudiantesifas del estudiante
        $infoEst = DB::table('infoestudiantesifas')
            ->where('estudiantesifas_id', $user->id)
            ->first(['id as info_id', 'instituciones_id']);

        if (!$infoEst) {
            return response()->json(['ok' => false, 'message' => 'No se encontró información del estudiante.'], 404);
        }

        $infoId = (int) $infoEst->info_id;

        // Obtener info de la materia
        $mat = DB::table('materias as m')
            ->join('plandeestudios as p', 'm.plandeestudios_id', '=', 'p.id')
            ->join('carreras as c', 'p.carreras_id', '=', 'c.id')
            ->where('m.id', $materiaId)
            ->first([
                'm.id as materia_id',
                'p.NombreMateria as nombre_materia',
                'p.SiglaMateria as sigla_materia',
                'p.LvlCurso as lvl_curso',
                'm.Paralelo as paralelo',
                'm.Turno as turno',
                'c.NombreCarrera as nombre_carrera',
            ]);

        if (!$mat) {
            return response()->json(['ok' => false, 'message' => 'Materia no encontrada.'], 404);
        }

        // Buscar aulas de la materia
        $aulas = AulaVirtual::query()
            ->where('materias_id', $materiaId)
            ->pluck('id');

        if ($aulas->isEmpty()) {
            return response()->json([
                'ok' => true,
                'materia' => $mat,
                'asistencias' => [],
                'resumen' => ['PRESENTE' => 0, 'ATRASO' => 0, 'FALTA' => 0, 'PERMISO' => 0, 'SIN REGISTRO' => 0],
            ]);
        }

        // Obtener sesiones de esas aulas ordenadas por fecha
        $sesiones = AsistenciaSesion::query()
            ->whereIn('aulas_virtuales_id', $aulas->all())
            ->orderByDesc('fecha')
            ->limit(200)
            ->get();

        $resumen = ['PRESENTE' => 0, 'ATRASO' => 0, 'FALTA' => 0, 'PERMISO' => 0, 'SIN REGISTRO' => 0];
        $asistencias = [];

        foreach ($sesiones as $s) {
            $reg = AsistenciaRegistro::query()
                ->where('asistencias_sesiones_id', (int) $s->id)
                ->where('infoestudiantesifas_id', $infoId)
                ->first(['estado_asistencia', 'metodo', 'fecha_registro']);

            $estado = $reg ? strtoupper(trim((string) $reg->estado_asistencia)) : 'SIN REGISTRO';
            if (isset($resumen[$estado])) {
                $resumen[$estado]++;
            } else {
                $resumen['SIN REGISTRO']++;
            }

            $asistencias[] = [
                'sesion_id' => $s->id,
                'fecha' => $s->fecha ? $s->fecha->format('Y-m-d') : null,
                'estado_sesion' => $s->estado,
                'estado_asistencia' => $reg ? $reg->estado_asistencia : null,
                'metodo' => $reg ? $reg->metodo : null,
                'fecha_registro' => $reg ? $reg->fecha_registro : null,
            ];
        }

        return response()->json([
            'ok' => true,
            'materia' => $mat,
            'asistencias' => $asistencias,
            'resumen' => $resumen,
        ]);
    }

    /**
     * Indica si el estudiante autenticado tiene materias del AÑO PREDETERMINADO
     * cuyo ModoAsistencia requiere asistencia por QR.
     *
     * Se usa para mostrar/ocultar el botón flotante y accesos rápidos del offcanvas.
     */
    public function estudianteCanScan(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['ok' => false, 'message' => 'No autenticado.'], 401);
        }

        $isEstudiante = method_exists($user, 'getTable') && $user->getTable() === 'estudiantesifas';
        if (!$isEstudiante) {
            $isEstudiante = ($user instanceof \App\Models\Estudiantesifas);
        }
        if (!$isEstudiante) {
            return response()->json(['ok' => false, 'message' => 'Solo estudiantes.'], 403);
        }

        $anioPred = DB::table('anios')
            ->where('Predeterminado', 'PREDETERMINADO')
            ->orderByDesc('id')
            ->first(['id']);

        if (!$anioPred) {
            return response()->json(['ok' => true, 'can_scan' => false, 'reason' => 'NO_ANIO_PREDETERMINADO']);
        }

        $infoEst = DB::table('infoestudiantesifas')
            ->where('estudiantesifas_id', $user->id)
            ->first(['id']);

        if (!$infoEst) {
            return response()->json(['ok' => true, 'can_scan' => false, 'reason' => 'NO_INFO_ESTUDIANTE']);
        }

        $modos = [
            'POR QR (ESTRICTO)',
            'POR QR Y REGISTRO VIRTUAL',
        ];

        $tiene = DB::table('calificaciones as cal')
            ->join('materias as m', 'cal.materias_id', '=', 'm.id')
            ->join('plandeestudios as p', 'm.plandeestudios_id', '=', 'p.id')
            ->where('cal.infoestudiantesifas_id', (int) $infoEst->id)
            ->where('p.anio_id', (int) $anioPred->id)
            ->whereIn(DB::raw('UPPER(TRIM(m.ModoAsistencia))'), $modos)
            ->exists();

        return response()->json([
            'ok' => true,
            'can_scan' => (bool) $tiene,
            'anio_id' => (int) $anioPred->id,
        ]);
    }

    /**
     * Indica si el docente/administrativo autenticado tiene al menos una materia
     * con asistencia habilitada cuyo ModoAsistencia NO contiene "QR".
     *
     * Se usa para mostrar/ocultar el botón flotante "Llamar asistencia".
     */
    public function docenteCanLlamar(Request $request)
    {
        $user = $request->user();
        $isDocente = ($user instanceof Planteldocentes);
        $isAdmin   = ($user instanceof Planteladministrativos) || ($user instanceof Usuarioslcchs);
        if (!$user || (!$isDocente && !$isAdmin)) {
            return response()->json(['ok' => false, 'message' => 'Solo docentes o administrativos.'], 403);
        }

        $instId = (int) ($user->instituciones_id ?? 0);

        $base = null;
        if ($isDocente) {
            $base = DB::table('planteldocentesmaterias as pdm')
                ->join('materias as m', 'pdm.materias_id', '=', 'm.id')
                ->join('plandeestudios as p', 'm.plandeestudios_id', '=', 'p.id')
                ->join('carreras as c', 'p.carreras_id', '=', 'c.id')
                ->where('pdm.planteldocentes_id', (int) $user->id)
                ->where('c.instituciones_id', $instId);
        } else {
            $base = DB::table('materias as m')
                ->join('plandeestudios as p', 'm.plandeestudios_id', '=', 'p.id')
                ->join('carreras as c', 'p.carreras_id', '=', 'c.id')
                ->where('c.instituciones_id', $instId);
        }

        // Asistencia habilitada != NORMAL y SIN QR
        $tiene = $base
            ->whereNotNull('m.ModoAsistencia')
            ->where('m.ModoAsistencia', '!=', '')
            ->where(DB::raw('UPPER(TRIM(m.ModoAsistencia))'), '!=', 'NORMAL')
            ->whereRaw("UPPER(TRIM(m.ModoAsistencia)) NOT LIKE '%QR%'")
            ->exists();

        return response()->json(['ok' => true, 'can_llamar' => (bool) $tiene]);
    }

    /**
     * Materias del docente autenticado (para el botón flotante de "Llamar asistencia").
     * Solo devuelve materias con modo de asistencia != NORMAL.
     */
    public function misMaterias(Request $request)
    {
        $user = $request->user();
        $isDocente = ($user instanceof Planteldocentes);
        $isAdmin   = ($user instanceof Planteladministrativos) || ($user instanceof Usuarioslcchs);
        if (!$user || (!$isDocente && !$isAdmin)) {
            return response()->json(['ok' => false, 'message' => 'Solo docentes o administrativos.'], 403);
        }

        $instId = (int) ($user->instituciones_id ?? 0);

        // Para docentes: materias asignadas vía planteldocentesmaterias
        // Para admin: todas las materias de su institución con modo != NORMAL
        if ($isDocente) {
            $materias = DB::table('planteldocentesmaterias as pdm')
                ->join('materias as m', 'pdm.materias_id', '=', 'm.id')
                ->join('plandeestudios as p', 'm.plandeestudios_id', '=', 'p.id')
                ->join('carreras as c', 'p.carreras_id', '=', 'c.id')
                ->where('pdm.planteldocentes_id', $user->id)
                ->where('c.instituciones_id', $instId)
                ->whereNotNull('m.ModoAsistencia')
                ->where('m.ModoAsistencia', '!=', '')
                ->where('m.ModoAsistencia', '!=', 'NORMAL')
                ->get([
                    'm.id as materia_id',
                    'p.NombreMateria as nombre_materia',
                    'p.SiglaMateria as sigla_materia',
                    'p.LvlCurso as lvl_curso',
                    'm.Paralelo as paralelo',
                    'm.Turno as turno',
                    'c.NombreCarrera as nombre_carrera',
                    'm.ModoAsistencia as modo_asistencia',
                ]);
        } else {
            $materias = DB::table('materias as m')
                ->join('plandeestudios as p', 'm.plandeestudios_id', '=', 'p.id')
                ->join('carreras as c', 'p.carreras_id', '=', 'c.id')
                ->where('c.instituciones_id', $instId)
                ->whereNotNull('m.ModoAsistencia')
                ->where('m.ModoAsistencia', '!=', '')
                ->where('m.ModoAsistencia', '!=', 'NORMAL')
                ->get([
                    'm.id as materia_id',
                    'p.NombreMateria as nombre_materia',
                    'p.SiglaMateria as sigla_materia',
                    'p.LvlCurso as lvl_curso',
                    'm.Paralelo as paralelo',
                    'm.Turno as turno',
                    'c.NombreCarrera as nombre_carrera',
                    'm.ModoAsistencia as modo_asistencia',
                ]);
        }

        return response()->json(['ok' => true, 'materias' => $materias]);
    }

    /**
     * Eliminar sesión.
     * - Administrativos/super: pueden eliminar cualquier sesión.
     * - Docentes: solo pueden eliminar sus propias sesiones si NO tienen registros de asistencia.
     * Borra registros de asistencia (si los hay), tokens QR y la sesión misma.
     */
    public function destroy(Request $request, $id)
    {
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json(['ok' => false, 'message' => 'No autenticado'], 401);
            }

            $isAdmin = ($user instanceof Planteladministrativos) || ($user instanceof Usuarioslcchs);
            $isDocente = ($user instanceof Planteldocentes);

            if (!$isAdmin && !$isDocente) {
                return response()->json(['ok' => false, 'message' => 'No tienes permiso para eliminar sesiones.'], 403);
            }

            $sesion = AsistenciaSesion::find((int) $id);
            if (!$sesion) {
                return response()->json(['ok' => false, 'message' => 'Sesión no encontrada'], 404);
            }

            // Docentes: verificar que sea su propia sesión
            if ($isDocente) {
                if ((int) $sesion->planteldocentes_id !== (int) $user->id) {
                    return response()->json(['ok' => false, 'message' => 'Solo puedes eliminar tus propias sesiones.'], 403);
                }

                // Docentes solo pueden eliminar si no hay registros de asistencia
                $tieneRegistros = AsistenciaRegistro::where('asistencias_sesiones_id', (int) $sesion->id)->exists();
                if ($tieneRegistros) {
                    return response()->json(['ok' => false, 'message' => 'No se puede eliminar: la sesión ya tiene registros de asistencia. Solo puedes eliminar sesiones sin asistencia registrada.'], 409);
                }
            }

            // Eliminar registros de asistencia asociados (para admins, o si por alguna razón hay)
            AsistenciaRegistro::where('asistencias_sesiones_id', (int) $sesion->id)->delete();

            // Eliminar tokens QR asociados
            AsistenciaQrToken::where('asistencias_sesiones_id', (int) $sesion->id)->delete();

            $sesion->delete();

            return response()->json(['ok' => true, 'message' => 'Sesión eliminada correctamente']);
        } catch (\Throwable $e) {
            // \Log::error('Destroy sesion error', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['ok' => false, 'message' => 'Error interno: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Reporte de asistencias por materia en un rango de fechas.
     * Devuelve una matriz: estudiantes × fechas con el estado de asistencia.
     */
    public function reporteAsistenciasMateria(Request $request, $materiaId)
    {
        $user = $request->user();
        $isDocente = ($user instanceof Planteldocentes);
        $isAdmin   = ($user instanceof Planteladministrativos) || ($user instanceof Usuarioslcchs);
        if (!$user || (!$isDocente && !$isAdmin)) {
            return response()->json(['ok' => false, 'message' => 'Solo docentes o administrativos.'], 403);
        }

        $materiaId = (int) $materiaId;
        $fechaInicio = $request->input('fecha_inicio');
        $fechaFin = $request->input('fecha_fin');

        if (!$fechaInicio || !$fechaFin) {
            return response()->json(['ok' => false, 'message' => 'Debe indicar fecha_inicio y fecha_fin.'], 422);
        }

        // Buscar aulas de la materia en la institución del usuario
        $aulas = AulaVirtual::query()
            ->where('instituciones_id', (int) $user->instituciones_id)
            ->where('materias_id', $materiaId)
            ->pluck('id');

        if ($aulas->isEmpty()) {
            return response()->json(['ok' => true, 'fechas' => [], 'estudiantes' => []]);
        }

        // Sesiones en el rango
        $sesiones = AsistenciaSesion::query()
            ->whereIn('aulas_virtuales_id', $aulas->all())
            ->whereDate('fecha', '>=', $fechaInicio)
            ->whereDate('fecha', '<=', $fechaFin)
            ->orderBy('fecha')
            ->get();

        if ($sesiones->isEmpty()) {
            return response()->json(['ok' => true, 'fechas' => [], 'estudiantes' => []]);
        }

        $sesionIds = $sesiones->pluck('id')->all();
        $fechasMap = []; // sesion_id => fecha (Y-m-d)
        foreach ($sesiones as $s) {
            $fechasMap[$s->id] = $s->fecha ? $s->fecha->format('Y-m-d') : null;
        }
        $fechas = array_values(array_unique(array_filter(array_values($fechasMap))));
        sort($fechas);

        // Obtener todos los registros de asistencia de esas sesiones
        $registros = AsistenciaRegistro::query()
            ->whereIn('asistencias_sesiones_id', $sesionIds)
            ->get(['infoestudiantesifas_id', 'asistencias_sesiones_id', 'estado_asistencia']);

        // Obtener nombres de estudiantes
        $infoIds = $registros->pluck('infoestudiantesifas_id')->unique()->values()->all();

        // También incluir estudiantes que están en la materia pero sin registros (para ver SIN REGISTRO)
        $base = $this->estudiantesQueryPorSesion($sesiones->first());
        $todosIds = [];
        if ($base) {
            $todosIds = $base->distinct()->pluck('ie.id')->map(fn ($x) => (int) $x)->values()->all();
        }
        $allIds = array_values(array_unique(array_merge($infoIds, $todosIds)));

        $nombres = DB::table('infoestudiantesifas as ie')
            ->join('estudiantesifas as e', 'e.id', '=', 'ie.estudiantesifas_id')
            ->whereIn('ie.id', $allIds)
            ->get(['ie.id as infoestudiantesifas_id', DB::raw("CONCAT(e.Ap_Paterno, ' ', e.Ap_Materno, ' ', e.Nombre) as nombre_completo")])
            ->keyBy('infoestudiantesifas_id');

        // Construir la matriz
        $matrix = []; // infoId => [fecha => estado]
        foreach ($registros as $r) {
            $infoId = (int) $r->infoestudiantesifas_id;
            $fecha = $fechasMap[$r->asistencias_sesiones_id] ?? null;
            if (!$fecha) continue;
            if (!isset($matrix[$infoId])) $matrix[$infoId] = [];
            $matrix[$infoId][$fecha] = strtoupper(trim($r->estado_asistencia ?? ''));
        }

        $estudiantes = [];
        foreach ($allIds as $infoId) {
            $infoId = (int) $infoId;
            $nombre = $nombres[$infoId]->nombre_completo ?? '—';
            $asistencias = [];
            foreach ($fechas as $f) {
                $asistencias[$f] = $matrix[$infoId][$f] ?? null;
            }
            $estudiantes[] = [
                'infoestudiantesifas_id' => $infoId,
                'nombre_completo' => $nombre,
                'asistencias' => $asistencias,
            ];
        }

        // Ordenar por nombre
        usort($estudiantes, fn ($a, $b) => strcasecmp($a['nombre_completo'], $b['nombre_completo']));

        return response()->json([
            'ok' => true,
            'fechas' => $fechas,
            'estudiantes' => $estudiantes,
        ]);
    }
}
