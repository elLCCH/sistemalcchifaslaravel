<?php

namespace App\Http\Controllers;

use App\Models\Eventos;
use App\Models\Estudianteseventos;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

class PublicEventosController extends BaseController
{
    public function index(Request $request)
    {
        $institucionId = $request->query('instituciones_id');
        $anio = $request->query('anio');
        $search = trim((string) $request->query('search', ''));

        $query = Eventos::query()
            ->where('eventos.Activo', 1)
            ->where('eventos.PublicoWeb', 1);

        if ($institucionId !== null && $institucionId !== '' && (int) $institucionId > 0) {
            $query->where('eventos.instituciones_id', (int) $institucionId);
        }

        if ($anio !== null && $anio !== '' && (int) $anio > 0) {
            $query->where('eventos.Anio', (int) $anio);
        }

        if ($search !== '') {
            $q = '%' . $search . '%';
            $query->where(function ($sub) use ($q) {
                $sub
                    ->where('eventos.NombreEvento', 'like', $q)
                    ->orWhere('eventos.ModoInscripcion', 'like', $q)
                    ->orWhere('eventos.Lugar', 'like', $q);
            });
        }

        $items = $query->orderByDesc('eventos.Anio')->orderByDesc('eventos.id')->get();
        return response()->json(['data' => $items]);
    }

    public function show(int $id)
    {
        $row = Eventos::query()
            ->where('eventos.id', $id)
            ->where('eventos.Activo', 1)
            ->where('eventos.PublicoWeb', 1)
            ->firstOrFail();

        return response()->json(['data' => $row]);
    }

    private function parseSchema($raw): ?array
    {
        if ($raw === null) return null;
        $txt = trim((string) $raw);
        if ($txt === '') return null;

        try {
            $decoded = json_decode($txt, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function schemaRequiredKeys(?array $schema): array
    {
        if (!$schema) return [];

        $required = [];
        $phases = $schema['phases'] ?? null;
        if (is_array($phases)) {
            foreach ($phases as $ph) {
                $fields = $ph['fields'] ?? [];
                if (!is_array($fields)) continue;
                foreach ($fields as $f) {
                    if (!is_array($f)) continue;
                    $key = trim((string) ($f['key'] ?? ''));
                    $isReq = (bool) ($f['required'] ?? false);
                    if ($key !== '' && $isReq) $required[] = $key;
                }
            }
            return array_values(array_unique($required));
        }

        $fields = $schema['fields'] ?? [];
        if (is_array($fields)) {
            foreach ($fields as $f) {
                if (!is_array($f)) continue;
                $key = trim((string) ($f['key'] ?? ''));
                $isReq = (bool) ($f['required'] ?? false);
                if ($key !== '' && $isReq) $required[] = $key;
            }
        }

        return array_values(array_unique($required));
    }

    public function inscribir(int $eventoId, Request $request)
    {
        $evento = Eventos::query()
            ->where('id', $eventoId)
            ->where('Activo', 1)
            ->where('PublicoWeb', 1)
            ->firstOrFail();

        $data = $request->all();

        $apP = trim((string) ($data['Ap_Paterno'] ?? ''));
        $apM = trim((string) ($data['Ap_Materno'] ?? ''));
        $nom = trim((string) ($data['Nombres'] ?? ''));
        $carnet = trim((string) ($data['Carnet'] ?? ''));

        if ($apP === '' || $apM === '' || $nom === '' || $carnet === '') {
            return response()->json(['error' => 'Datos personales incompletos'], 422);
        }

        // Normalizar/cargar datos especiales (acepta objeto o string JSON)
        $datosEspecialesRaw = $data['DatosEspeciales'] ?? null;
        $datosEspeciales = null;
        if (is_array($datosEspecialesRaw)) {
            $datosEspeciales = $datosEspecialesRaw;
        } elseif (is_string($datosEspecialesRaw)) {
            $txt = trim($datosEspecialesRaw);
            if ($txt !== '') {
                try {
                    $decoded = json_decode($txt, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($decoded)) $datosEspeciales = $decoded;
                } catch (\Throwable $e) {
                    return response()->json(['error' => 'DatosEspeciales JSON inválido'], 422);
                }
            }
        }

        $schema = $this->parseSchema($evento->InputsEspecial);
        $requiredKeys = $this->schemaRequiredKeys($schema);
        if (!empty($requiredKeys)) {
            $bag = is_array($datosEspeciales) ? $datosEspeciales : [];
            foreach ($requiredKeys as $key) {
                $v = $bag[$key] ?? null;
                $s = trim((string) ($v ?? ''));
                if ($s === '') {
                    return response()->json(['error' => 'Falta completar: ' . $key], 422);
                }
            }
        }

        // Campos de pago vienen definidos por el evento
        $tienePago = (int) ($evento->TienePago ?? 0) === 1 ? 1 : 0;

        $insert = [
            'instituciones_id' => $evento->instituciones_id,
            'eventos_id' => (int) $evento->id,
            'estudiantesifas_id' => null,
            'Ap_Paterno' => $apP,
            'Ap_Materno' => $apM,
            'Nombres' => $nom,
            'Carnet' => $carnet,
            'Celular' => trim((string) ($data['Celular'] ?? '')),
            'Correo' => trim((string) ($data['Correo'] ?? '')),
            'DatosEspeciales' => json_encode($datosEspeciales ?? [], JSON_UNESCAPED_UNICODE),
            'TienePago' => $tienePago,
            'Monto' => $tienePago ? ($evento->Monto ?? null) : null,
            'MetodoPago' => $tienePago ? trim((string) ($data['MetodoPago'] ?? '')) : '',
            'FechaPago' => $tienePago ? trim((string) ($data['FechaPago'] ?? '')) : '',
            'ComprobantePago' => $tienePago ? trim((string) ($data['ComprobantePago'] ?? '')) : '',
            'EstadoPago' => $tienePago ? 'PENDIENTE' : 'NO_APLICA',
            'EstadoInscripcion' => 'PENDIENTE',
            'Observacion' => trim((string) ($data['Observacion'] ?? '')),
        ];

        // Evitar duplicados por evento + carnet + institucion
        $dupQuery = Estudianteseventos::query()
            ->where('eventos_id', (int) $evento->id)
            ->where('Carnet', $carnet);

        if ($evento->instituciones_id === null) {
            $dupQuery->whereNull('instituciones_id');
        } else {
            $dupQuery->where('instituciones_id', (int) $evento->instituciones_id);
        }

        if ($dupQuery->exists()) {
            return response()->json(['error' => 'Ya existe una inscripción con ese carnet para este evento'], 422);
        }

        $row = Estudianteseventos::create($insert);
        return response()->json(['data' => $row]);
    }
}
