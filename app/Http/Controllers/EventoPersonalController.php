<?php

namespace App\Http\Controllers;

use App\Http\Middleware\UpdateTokenExpiration;
use App\Models\EventoPersonal;
use App\Models\Eventos;
use App\Models\Estudianteseventos;
use App\Models\Planteladministrativos;
use App\Models\Planteldocentes;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

// Docentes y administrativos inscritos a eventos, y sus certificados (Destinadoa DOCENTES / ADMINISTRATIVOS).
class EventoPersonalController extends Controller
{
    private const TIPOS = ['DOCENTE', 'ADMINISTRATIVO'];

    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }

    private function institucionId(Request $request): ?int
    {
        $id = $request->user()?->instituciones_id;
        return empty($id) ? null : (int) $id;
    }

    private function modeloPersona(string $tipo): string
    {
        return $tipo === 'DOCENTE' ? Planteldocentes::class : Planteladministrativos::class;
    }

    private function fila(EventoPersonal $r): array
    {
        $p = $r->tipo === 'DOCENTE' ? $r->docente : $r->administrativo;
        return array_merge($r->toArray(), [
            'persona' => $p ? [
                'id' => $p->id,
                'Nombres' => $p->Nombres,
                'Apellidos' => $p->Apellidos,
                'Carnet' => $p->Carnet,
                'Foto' => $p->Foto,
                'Cargo' => $p->Cargo ?? null,
            ] : null,
        ]);
    }

    public function index(Request $request)
    {
        $instId = $this->institucionId($request);
        $rows = EventoPersonal::with(['docente', 'administrativo'])
            ->when($instId, fn ($q) => $q->where('instituciones_id', $instId))
            ->when($request->input('eventos_id'), fn ($q, $v) => $q->where('eventos_id', (int) $v))
            ->when($request->input('tipo'), fn ($q, $v) => $q->where('tipo', strtoupper($v)))
            ->when($request->input('personal_id'), function ($q, $v) use ($request) {
                $col = strtoupper((string) $request->input('tipo')) === 'ADMINISTRATIVO' ? 'planteladministrativos_id' : 'planteldocentes_id';
                $q->where($col, (int) $v);
            })
            ->orderBy('id')
            ->get()
            ->map(fn ($r) => $this->fila($r));

        return response()->json(['data' => $rows]);
    }

    // Personal de la institución que aún no está inscrito en el evento.
    public function candidatos(Request $request)
    {
        $data = $request->validate([
            'eventos_id' => 'required|integer|exists:eventos,id',
            'tipo' => 'required|in:DOCENTE,ADMINISTRATIVO',
        ]);
        $instId = $this->institucionId($request);
        $col = $data['tipo'] === 'DOCENTE' ? 'planteldocentes_id' : 'planteladministrativos_id';
        $yaInscritos = EventoPersonal::where('eventos_id', $data['eventos_id'])->whereNotNull($col)->pluck($col);

        $modelo = $this->modeloPersona($data['tipo']);
        $lista = $modelo::query()
            ->when($instId, fn ($q) => $q->where('instituciones_id', $instId))
            ->whereNotIn('id', $yaInscritos)
            ->orderBy('Apellidos')->orderBy('Nombres')
            ->get(['id', 'Nombres', 'Apellidos', 'Carnet', 'Foto', 'Cargo', 'Estado']);

        return response()->json(['data' => $lista]);
    }

    public function store(Request $request)
    {
        $v = $request->validate([
            'eventos_id' => 'required|integer|exists:eventos,id',
            'tipo' => 'required|in:DOCENTE,ADMINISTRATIVO',
            'personal_id' => 'required|integer',
            'rol' => 'nullable|string|max:100',
            'parametro1' => 'nullable|string|max:80',
            'parametro2' => 'nullable|string|max:80',
            'parametro3' => 'nullable|string|max:80',
            'parametro4' => 'nullable|string|max:80',
            'parametro5' => 'nullable|string|max:80',
        ]);
        $instId = $this->institucionId($request);

        $evento = Eventos::find($v['eventos_id']);
        $persona = $this->modeloPersona($v['tipo'])::find($v['personal_id']);
        if (!$persona || !$evento) {
            return response()->json(['data' => 'Datos inválidos'], 422);
        }
        if ((int) $evento->instituciones_id !== (int) $persona->instituciones_id || ($instId && (int) $evento->instituciones_id !== $instId)) {
            return response()->json(['data' => 'El evento y la persona deben pertenecer a su institución'], 422);
        }

        $col = $v['tipo'] === 'DOCENTE' ? 'planteldocentes_id' : 'planteladministrativos_id';
        if (EventoPersonal::where('eventos_id', $evento->id)->where($col, $persona->id)->exists()) {
            return response()->json(['data' => 'Ya está inscrito en este evento'], 422);
        }

        $reg = EventoPersonal::create([
            'instituciones_id' => (int) $evento->instituciones_id,
            'eventos_id' => $evento->id,
            'tipo' => $v['tipo'],
            $col => $persona->id,
            'rol' => $v['rol'] ?? null,
            'parametro1' => $v['parametro1'] ?? null,
            'parametro2' => $v['parametro2'] ?? null,
            'parametro3' => $v['parametro3'] ?? null,
            'parametro4' => $v['parametro4'] ?? null,
            'parametro5' => $v['parametro5'] ?? null,
        ]);

        return response()->json(['data' => $this->fila($reg->load(['docente', 'administrativo']))], 201);
    }

    private function propio(Request $request, $id): EventoPersonal
    {
        $instId = $this->institucionId($request);
        return EventoPersonal::query()
            ->when($instId, fn ($q) => $q->where('instituciones_id', $instId))
            ->findOrFail((int) $id);
    }

    public function update(Request $request, $id)
    {
        $reg = $this->propio($request, $id);
        $v = $request->validate([
            'rol' => 'nullable|string|max:100',
            'parametro1' => 'nullable|string|max:80',
            'parametro2' => 'nullable|string|max:80',
            'parametro3' => 'nullable|string|max:80',
            'parametro4' => 'nullable|string|max:80',
            'parametro5' => 'nullable|string|max:80',
        ]);
        $reg->update($v);
        return response()->json(['data' => $this->fila($reg->load(['docente', 'administrativo']))]);
    }

    public function destroy(Request $request, $id)
    {
        $this->propio($request, $id)->delete();
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }

    // PDF al vuelo, con o sin firmas, usando el diseño asignado con Destinadoa DOCENTES / ADMINISTRATIVOS.
    public function generarCertificado(Request $request, $id)
    {
        $reg = $this->propio($request, $id)->load(['docente', 'administrativo', 'evento']);
        $p = $reg->tipo === 'DOCENTE' ? $reg->docente : $reg->administrativo;
        if (!$p || !$reg->evento) {
            return response()->json(['error' => 'Registro incompleto'], 422);
        }

        $persona = new Estudianteseventos();
        $persona->Nombres = $p->Nombres;
        $persona->Ap_Paterno = $p->Apellidos;
        $persona->Ap_Materno = '';
        $persona->Carnet = $p->Carnet;
        $persona->Foto = $p->Foto;
        $persona->Especialidad = '';
        $persona->Categoria = '';
        $persona->DatosEspeciales = json_encode([
            'ROL' => $reg->rol,
            'CARGO' => $p->Cargo ?? '',
            'PARAMETRO1' => $reg->parametro1,
            'PARAMETRO2' => $reg->parametro2,
            'PARAMETRO3' => $reg->parametro3,
            'PARAMETRO4' => $reg->parametro4,
            'PARAMETRO5' => $reg->parametro5,
        ]);

        $conFirmas = filter_var($request->input('conFirmas', false), FILTER_VALIDATE_BOOLEAN);
        $destinadoa = $reg->tipo === 'DOCENTE' ? 'DOCENTES' : 'ADMINISTRATIVOS';
        return app(EstudianteseventosController::class)->emitirCertificadoPdf($persona, $reg->evento, $conFirmas, $request->user(), $destinadoa);
    }
}
