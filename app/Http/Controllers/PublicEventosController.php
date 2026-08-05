<?php

namespace App\Http\Controllers;

use App\Models\Eventos;
use App\Models\Estudianteseventos;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Http;

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

    private function parseParametros($raw): ?array
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

    private function eventoCategorias(Eventos $evento): array
    {
        $parametros = $this->parseParametros($evento->Parametros ?? null);
        if (!$parametros) return [];

        $rawCategorias = $parametros['categorias'] ?? $parametros['Categorias'] ?? [];
        if (!is_array($rawCategorias)) return [];

        $items = [];
        $seen = [];
        foreach ($rawCategorias as $row) {
            if (!is_array($row)) continue;

            $categoria = trim((string) ($row['Categoria'] ?? $row['categoria'] ?? ''));
            if ($categoria === '') continue;

            $key = mb_strtoupper($categoria, 'UTF-8');
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            $items[] = [
                'Categoria' => $categoria,
                'Edades' => trim((string) ($row['Edades'] ?? $row['edades'] ?? '')),
                'Descripcion' => trim((string) ($row['Descripcion'] ?? $row['descripcion'] ?? '')),
            ];
        }

        return $items;
    }

    private function eventoEspecialidades(Eventos $evento): array
    {
        $especialidades = $this->parseParametros($evento->Especialidades ?? null);
        if (!$especialidades) return [];

        $rawEspecialidades = $especialidades['especialidades'] ?? $especialidades['Especialidades'] ?? [];
        if (!is_array($rawEspecialidades)) return [];

        $items = [];
        $seen = [];
        foreach ($rawEspecialidades as $row) {
            if (!is_array($row)) continue;

            $especialidad = trim((string) ($row['Especialidad'] ?? $row['especialidad'] ?? ''));
            if ($especialidad === '') continue;

            $key = mb_strtoupper($especialidad, 'UTF-8');
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            $items[] = [
                'Especialidad' => $especialidad,
                'Detalle' => trim((string) ($row['Detalle'] ?? $row['detalle'] ?? '')),
                'Dependencia' => trim((string) ($row['Dependencia'] ?? $row['dependencia'] ?? 'DEPENDIENTE')),
            ];
        }

        return $items;
    }

    private function schemaSlug($value): string
    {
        $value = trim((string) $value);
        if ($value === '') return '';

        if (class_exists('\Normalizer')) {
            $normalized = \Normalizer::normalize($value, \Normalizer::FORM_D);
            if (is_string($normalized)) {
                $value = preg_replace('/\pM/u', '', $normalized) ?? $value;
            }
        }

        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace('/[^a-z0-9]+/u', '_', $value) ?? $value;

        return trim($value, '_');
    }

    private function eventoColumnas(Eventos $evento): array
    {
        $columnas = $this->parseParametros($evento->Columnas ?? null);
        if (!$columnas) return [];

        $rawColumnas = $columnas['columnas'] ?? $columnas['Columnas'] ?? [];
        if (!is_array($rawColumnas)) return [];

        $items = [];
        $seen = [];
        foreach ($rawColumnas as $index => $row) {
            if (!is_array($row)) continue;

            $title = trim((string) ($row['Titulo'] ?? $row['titulo'] ?? ''));
            if ($title === '') continue;

            $key = $this->schemaSlug($title);
            if ($key === '') $key = 'col_' . ($index + 1);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            $type = trim((string) ($row['Tipo'] ?? $row['tipo'] ?? 'text'));
            if ($type !== 'number' && $type !== 'date') $type = 'text';

            $items[] = [
                'Titulo' => $title,
                'Tipo' => $type,
                'key' => $key,
            ];
        }

        return $items;
    }

    private function requiredKeysForField(array $field, array $columnas): array
    {
        $key = trim((string) ($field['key'] ?? ''));
        if ($key === '' || empty($field['required'])) return [];
        if (count($columnas) === 0) return [$key];

        $required = [$key];
        foreach ($columnas as $index => $columna) {
            if ($index === 0) continue;
            $columnKey = trim((string) ($columna['key'] ?? ''));
            if ($columnKey === '') continue;
            $required[] = $key . '__' . $columnKey;
        }

        return $required;
    }

    private function validarCategoriaParaEvento(Eventos $evento, $categoriaSeleccionada): array
    {
        $categoriaSeleccionada = trim((string) ($categoriaSeleccionada ?? ''));
        $categorias = $this->eventoCategorias($evento);

        if (count($categorias) === 0) {
            return ['ok' => true, 'value' => $categoriaSeleccionada];
        }

        if ($categoriaSeleccionada === '') {
            return ['ok' => false, 'error' => 'Seleccione una categoría válida para el evento'];
        }

        foreach ($categorias as $item) {
            if (mb_strtoupper((string) $item['Categoria'], 'UTF-8') === mb_strtoupper($categoriaSeleccionada, 'UTF-8')) {
                return ['ok' => true, 'value' => $item['Categoria']];
            }
        }

        return ['ok' => false, 'error' => 'La categoría seleccionada no pertenece al evento'];
    }

    private function validarEspecialidadParaEvento(Eventos $evento, $especialidadSeleccionada): array
    {
        $especialidadSeleccionada = trim((string) ($especialidadSeleccionada ?? ''));
        $especialidades = $this->eventoEspecialidades($evento);

        if (count($especialidades) === 0) {
            return ['ok' => true, 'value' => $especialidadSeleccionada];
        }

        if ($especialidadSeleccionada === '') {
            return ['ok' => false, 'error' => 'Seleccione una especialidad válida para el evento'];
        }

        foreach ($especialidades as $item) {
            if (mb_strtoupper((string) $item['Especialidad'], 'UTF-8') === mb_strtoupper($especialidadSeleccionada, 'UTF-8')) {
                return ['ok' => true, 'value' => $item['Especialidad']];
            }
        }

        return ['ok' => false, 'error' => 'La especialidad seleccionada no pertenece al evento'];
    }

    private function normalizeSchemaContext($raw): ?array
    {
        if (!is_array($raw)) return null;

        $phases = $raw['phases'] ?? [];
        if (is_array($phases) && count($phases) > 0) {
            return ['mode' => 'phases', 'phases' => $phases];
        }

        $fields = $raw['fields'] ?? [];
        if (is_array($fields) && count($fields) > 0) {
            return ['mode' => 'simple', 'fields' => $fields];
        }

        return null;
    }

    private function schemaContextsForSelection(?array $schema, string $categoria, string $especialidad): array
    {
        if (!$schema) return [];

        $isV2 = isset($schema['general']) || isset($schema['categorias']) || isset($schema['especialidades']) || ((int) ($schema['version'] ?? 0) >= 2);
        if (!$isV2) {
            $legacy = $this->normalizeSchemaContext($schema);
            return $legacy ? [$legacy] : [];
        }

        $contexts = [];
        $general = $this->normalizeSchemaContext($schema['general'] ?? null);
        if ($general) $contexts[] = $general;

        if (!empty($schema['byCategorias']) && $categoria !== '') {
            foreach (($schema['categorias'] ?? []) as $item) {
                $name = trim((string) ($item['categoria'] ?? ''));
                if ($name === '' || mb_strtoupper($name, 'UTF-8') !== mb_strtoupper($categoria, 'UTF-8')) continue;

                $ctx = $this->normalizeSchemaContext($item['config'] ?? null);
                if ($ctx) $contexts[] = $ctx;
                break;
            }
        }

        if (!empty($schema['byEspecialidades']) && $especialidad !== '') {
            foreach (($schema['especialidades'] ?? []) as $item) {
                $name = trim((string) ($item['especialidad'] ?? ''));
                if ($name === '' || mb_strtoupper($name, 'UTF-8') !== mb_strtoupper($especialidad, 'UTF-8')) continue;

                $ctx = $this->normalizeSchemaContext($item['config'] ?? null);
                if ($ctx) $contexts[] = $ctx;
                break;
            }
        }

        return $contexts;
    }

    private function schemaRequiredKeysForSelection(?array $schema, string $categoria, string $especialidad, array $columnas = []): array
    {
        $required = [];
        foreach ($this->schemaContextsForSelection($schema, $categoria, $especialidad) as $ctx) {
            $phases = $ctx['phases'] ?? null;
            if (is_array($phases) && count($phases) > 0) {
                foreach ($phases as $ph) {
                    $fields = $ph['fields'] ?? [];
                    if (!is_array($fields)) continue;
                    foreach ($fields as $f) {
                        if (!is_array($f)) continue;
                        foreach ($this->requiredKeysForField($f, $columnas) as $key) {
                            $required[] = $key;
                        }
                    }
                }
            }

            $fields = $ctx['fields'] ?? [];
            if (is_array($fields)) {
                foreach ($fields as $f) {
                    if (!is_array($f)) continue;
                    foreach ($this->requiredKeysForField($f, $columnas) as $key) {
                        $required[] = $key;
                    }
                }
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

        $recaptchaToken = trim((string) ($data['RecaptchaToken'] ?? ''));
        if ($recaptchaToken === '') {
            return response()->json(['error' => 'reCAPTCHA no válido'], 422);
        }

        $recaptchaResult = $this->verifyRecaptcha($recaptchaToken);
        if (!$recaptchaResult['success']) {
            return response()->json(['error' => $recaptchaResult['message'] ?? 'Error validando reCAPTCHA'], 422);
        }

        $apP = trim((string) ($data['Ap_Paterno'] ?? ''));
        $apM = trim((string) ($data['Ap_Materno'] ?? ''));
        $nom = trim((string) ($data['Nombres'] ?? ''));
        $carnet = trim((string) ($data['Carnet'] ?? ''));
        $Fotog = trim((string) ($data['Foto'] ?? ''));

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

        $categoriaValidada = $this->validarCategoriaParaEvento($evento, $data['Categoria'] ?? '');
        if (!($categoriaValidada['ok'] ?? false)) {
            return response()->json(['error' => $categoriaValidada['error'] ?? 'Categoría inválida'], 422);
        }

        $especialidadValidada = $this->validarEspecialidadParaEvento($evento, $data['Especialidad'] ?? '');
        if (!($especialidadValidada['ok'] ?? false)) {
            return response()->json(['error' => $especialidadValidada['error'] ?? 'Especialidad inválida'], 422);
        }

        $schema = $this->parseSchema($evento->InputsEspecial);
        $requiredKeys = $this->schemaRequiredKeysForSelection(
            $schema,
            (string) ($categoriaValidada['value'] ?? ''),
            (string) ($especialidadValidada['value'] ?? ''),
            $this->eventoColumnas($evento)
        );
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

        $certificadoNacimiento = trim((string) ($data['CertificadoNacimiento'] ?? ''));
        $edad = (int) ($data['Edad'] ?? 0);
        $celularTutor = trim((string) ($data['CelularTutor'] ?? ''));
        $departamento = trim((string) ($data['Departamento'] ?? ''));
        $nombreInstitucion = trim((string) ($data['NombreInstitucion'] ?? ''));

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
            'FechaPago' => $tienePago ? (trim((string) ($data['FechaPago'] ?? '')) ?: null) : null,
            'ComprobantePago' => $tienePago ? trim((string) ($data['ComprobantePago'] ?? '')) : '',
            'Foto' => $Fotog,
            'EstadoPago' => $tienePago ? 'PENDIENTE' : 'NO_APLICA',
            'EstadoInscripcion' => 'PENDIENTE',
            'Especialidad' => $especialidadValidada['value'] ?? '',
            'Categoria' => $categoriaValidada['value'] ?? '',
            'Observacion' => trim((string) ($data['Observacion'] ?? '')),
            'Tutor' => trim((string) ($data['Tutor'] ?? '')),
            'FechaNac' => trim((string) ($data['FechaNac'] ?? '')) ?: null,
            'Edad' => $edad > 0 ? $edad : null,
            'CelularTutor' => $celularTutor,
            'Departamento' => $departamento,
            'nombreInstitucion' => $nombreInstitucion,
            'CertificadoNacimiento' => $certificadoNacimiento,
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

    private function verifyRecaptcha(string $token): array
    {
        $secret = env('RECAPTCHA_SECRET', '');
        if ($secret === '') {
            return ['success' => false, 'message' => 'No se configuró la clave reCAPTCHA en el servidor.'];
        }

        try {
            $response = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
                'secret' => $secret,
                'response' => $token,
            ]);

            if (!$response->successful()) {
                return ['success' => false, 'message' => 'No se pudo conectar con el servicio reCAPTCHA.'];
            }

            $body = $response->json();
            if (!is_array($body)) {
                return ['success' => false, 'message' => 'Respuesta reCAPTCHA inválida.'];
            }

            if (!empty($body['success']) && $body['success'] == true) {
                return ['success' => true];
            }

            $message = 'reCAPTCHA inválido.';
            if (!empty($body['error-codes']) && is_array($body['error-codes'])) {
                $message .= ' ' . implode(', ', $body['error-codes']);
            }

            return ['success' => false, 'message' => $message];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Error verificando reCAPTCHA: ' . $e->getMessage()];
        }
    }
}
