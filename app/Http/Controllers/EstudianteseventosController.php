<?php

namespace App\Http\Controllers;

use App\Http\Middleware\UpdateTokenExpiration;
use App\Models\Diseniocertificadopdfs;
use App\Models\Eventos;
use App\Models\Estudianteseventos;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use setasign\Fpdi\Fpdi;

class EstudianteseventosController extends BaseController
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
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

    private function eventoCategorias(?Eventos $evento): array
    {
        $parametros = $this->parseParametros($evento?->Parametros ?? null);
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

    private function eventoEspecialidades(?Eventos $evento): array
    {
        $especialidades = $this->parseParametros($evento?->Especialidades ?? null);
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

    private function eventoColumnas(?Eventos $evento): array
    {
        $columnas = $this->parseParametros($evento?->Columnas ?? null);
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

    private function validarCategoriaParaEvento(?Eventos $evento, $categoriaSeleccionada): array
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

    private function validarEspecialidadParaEvento(?Eventos $evento, $especialidadSeleccionada): array
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
                foreach ($phases as $phase) {
                    $fields = $phase['fields'] ?? [];
                    if (!is_array($fields)) continue;
                    foreach ($fields as $field) {
                        if (!is_array($field)) continue;
                        foreach ($this->requiredKeysForField($field, $columnas) as $key) {
                            $required[] = $key;
                        }
                    }
                }
                continue;
            }

            $fields = $ctx['fields'] ?? [];
            if (!is_array($fields)) continue;
            foreach ($fields as $field) {
                if (!is_array($field)) continue;
                foreach ($this->requiredKeysForField($field, $columnas) as $key) {
                    $required[] = $key;
                }
            }
        }

        return array_values(array_unique($required));
    }

    private function parseDatosEspeciales($raw, ?bool &$invalid = false): array
    {
        $invalid = false;
        if (is_array($raw)) return $raw;

        $txt = trim((string) ($raw ?? ''));
        if ($txt === '') return [];

        try {
            $decoded = json_decode($txt, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable $e) {
            $invalid = true;
            return [];
        }
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $isSuperAdmin = empty($user?->instituciones_id);

        $eventoId = $request->query('eventos_id');
        $search = trim((string) $request->query('search', ''));

        $query = Estudianteseventos::query()
            ->join('eventos', 'estudianteseventos.eventos_id', '=', 'eventos.id')
            ->leftJoin('estudiantesifas', 'estudianteseventos.estudiantesifas_id', '=', 'estudiantesifas.id')
            ->leftJoin('instituciones', 'estudianteseventos.instituciones_id', '=', 'instituciones.id')
            ->addSelect(
                'estudianteseventos.*',
                'eventos.NombreEvento as EventoNombre',
                'eventos.Anio as EventoAnio',
                $isSuperAdmin ? 'instituciones.Nombre as NombreInstitucion' : DB::raw('NULL as NombreInstitucion')
            )
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('estudianteseventos.instituciones_id', (int) $user->instituciones_id);
            })
            ->when($eventoId !== null && $eventoId !== '' && (int) $eventoId > 0, function ($q) use ($eventoId) {
                $q->where('estudianteseventos.eventos_id', (int) $eventoId);
            });

        if ($search !== '') {
            $q = '%' . $search . '%';
            $query->where(function ($sub) use ($q) {
                $sub
                    ->where('estudianteseventos.Ap_Paterno', 'like', $q)
                    ->orWhere('estudianteseventos.Ap_Materno', 'like', $q)
                    ->orWhere('estudianteseventos.Nombres', 'like', $q)
                    ->orWhere('estudianteseventos.Carnet', 'like', $q)
                    ->orWhere('estudianteseventos.EstadoInscripcion', 'like', $q)
                    ->orWhere('estudianteseventos.EstadoPago', 'like', $q);
            });
        }

        $items = $query->orderByDesc('estudianteseventos.id')->get();
        return response()->json(['data' => $items]);
    }

    public function create() {}

    public function store(Request $request)
    {
        $user = $request->user();
        $data = $request->all();

        if (!empty($user?->instituciones_id)) {
            $data['instituciones_id'] = (int) $user->instituciones_id;
        }

        // Defaults
        if (!isset($data['TienePago']) || $data['TienePago'] === '') $data['TienePago'] = 0;
        if (!isset($data['EstadoInscripcion']) || $data['EstadoInscripcion'] === '') $data['EstadoInscripcion'] = 'PENDIENTE';
        if (($data['TienePago'] ?? 0) == 0) {
            $data['Monto'] = null;
            $data['MetodoPago'] = '';
            $data['FechaPago'] = null;
            $data['ComprobantePago'] = '';
            $data['EstadoPago'] = 'NO_APLICA';
        } else {
            if (!isset($data['EstadoPago']) || $data['EstadoPago'] === '') $data['EstadoPago'] = 'PENDIENTE';
            if (array_key_exists('Monto', $data) && $data['Monto'] === '') $data['Monto'] = null;
            if (array_key_exists('FechaPago', $data) && $data['FechaPago'] === '') $data['FechaPago'] = null;
        }

        // Evitar duplicados simples por evento + carnet (si viene)
        $carnet = trim((string) ($data['Carnet'] ?? ''));
        $eventoId = (int) ($data['eventos_id'] ?? 0);

        $evento = Eventos::query()
            ->where('id', '=', $eventoId)
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('instituciones_id', (int) $user->instituciones_id);
            })
            ->first();

        if (!$evento) {
            return response()->json(['error' => 'Evento no válido'], 422);
        }

        $categoriaValidada = $this->validarCategoriaParaEvento($evento, $data['Categoria'] ?? '');
        if (!($categoriaValidada['ok'] ?? false)) {
            return response()->json(['error' => $categoriaValidada['error'] ?? 'Categoría inválida'], 422);
        }
        $data['Categoria'] = $categoriaValidada['value'] ?? '';

        $especialidadValidada = $this->validarEspecialidadParaEvento($evento, $data['Especialidad'] ?? '');
        if (!($especialidadValidada['ok'] ?? false)) {
            return response()->json(['error' => $especialidadValidada['error'] ?? 'Especialidad inválida'], 422);
        }
        $data['Especialidad'] = $especialidadValidada['value'] ?? '';

        $datosInvalidos = false;
        $datosEspeciales = $this->parseDatosEspeciales($data['DatosEspeciales'] ?? null, $datosInvalidos);
        if ($datosInvalidos) {
            return response()->json(['error' => 'DatosEspeciales JSON inválido'], 422);
        }

        $requiredKeys = $this->schemaRequiredKeysForSelection(
            $this->parseSchema($evento->InputsEspecial ?? null),
            (string) $data['Categoria'],
            (string) $data['Especialidad'],
            $this->eventoColumnas($evento)
        );
        foreach ($requiredKeys as $key) {
            $value = trim((string) ($datosEspeciales[$key] ?? ''));
            if ($value === '') {
                return response()->json(['error' => 'Falta completar: ' . $key], 422);
            }
        }

        $data['DatosEspeciales'] = json_encode($datosEspeciales, JSON_UNESCAPED_UNICODE);

        if ($eventoId > 0 && $carnet !== '') {
            $exists = Estudianteseventos::query()
                ->where('eventos_id', $eventoId)
                ->where('Carnet', $carnet)
                ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                    $q->where('instituciones_id', (int) $user->instituciones_id);
                })
                ->exists();
            if ($exists) {
                return response()->json(['error' => 'Ya existe una inscripción con ese carnet para este evento'], 422);
            }
        }

        $row = Estudianteseventos::create($data);
        if (!empty($user?->instituciones_id)) {
            $row->NombreInstitucion = null;
        }
        return response()->json(['data' => $row]);
    }

    public function show($id, Request $request)
    {
        $user = $request->user();

        $row = Estudianteseventos::query()
            ->where('id', '=', (int) $id)
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('instituciones_id', (int) $user->instituciones_id);
            })
            ->firstOrFail();

        return response()->json(['data' => $row]);
    }

    public function edit(Estudianteseventos $estudianteseventos) {}

    public function update(Request $request)
    {
        $user = $request->user();

        $row = Estudianteseventos::query()
            ->where('id', '=', (int) $request->id)
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('instituciones_id', (int) $user->instituciones_id);
            })
            ->firstOrFail();

        $data = $request->all();

        if (!empty($user?->instituciones_id)) {
            $data['instituciones_id'] = (int) $user->instituciones_id;
        }

        $eventoId = (int) ($data['eventos_id'] ?? $row->eventos_id ?? 0);
        $evento = Eventos::query()
            ->where('id', '=', $eventoId)
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('instituciones_id', (int) $user->instituciones_id);
            })
            ->first();

        if (!$evento) {
            return response()->json(['error' => 'Evento no válido'], 422);
        }

        $categoriaValidada = $this->validarCategoriaParaEvento($evento, $data['Categoria'] ?? $row->Categoria ?? '');
        if (!($categoriaValidada['ok'] ?? false)) {
            return response()->json(['error' => $categoriaValidada['error'] ?? 'Categoría inválida'], 422);
        }
        $data['Categoria'] = $categoriaValidada['value'] ?? '';

        $especialidadValidada = $this->validarEspecialidadParaEvento($evento, $data['Especialidad'] ?? $row->Especialidad ?? '');
        if (!($especialidadValidada['ok'] ?? false)) {
            return response()->json(['error' => $especialidadValidada['error'] ?? 'Especialidad inválida'], 422);
        }
        $data['Especialidad'] = $especialidadValidada['value'] ?? '';

        $datosInvalidos = false;
        $datosEspeciales = $this->parseDatosEspeciales($data['DatosEspeciales'] ?? $row->DatosEspeciales ?? null, $datosInvalidos);
        if ($datosInvalidos) {
            return response()->json(['error' => 'DatosEspeciales JSON inválido'], 422);
        }

        $requiredKeys = $this->schemaRequiredKeysForSelection(
            $this->parseSchema($evento->InputsEspecial ?? null),
            (string) $data['Categoria'],
            (string) $data['Especialidad'],
            $this->eventoColumnas($evento)
        );
        foreach ($requiredKeys as $key) {
            $value = trim((string) ($datosEspeciales[$key] ?? ''));
            if ($value === '') {
                return response()->json(['error' => 'Falta completar: ' . $key], 422);
            }
        }
        $data['DatosEspeciales'] = json_encode($datosEspeciales, JSON_UNESCAPED_UNICODE);

        if (array_key_exists('TienePago', $data) && ($data['TienePago'] == 0 || $data['TienePago'] === '0' || $data['TienePago'] === false)) {
            $data['Monto'] = null;
            $data['MetodoPago'] = '';
            $data['FechaPago'] = null;
            $data['ComprobantePago'] = '';
            $data['EstadoPago'] = 'NO_APLICA';
        } else {
            if (array_key_exists('Monto', $data) && $data['Monto'] === '') $data['Monto'] = null;
            if (array_key_exists('FechaPago', $data) && $data['FechaPago'] === '') $data['FechaPago'] = null;
            if (array_key_exists('EstadoPago', $data) && ($data['EstadoPago'] === '' || $data['EstadoPago'] === null)) {
                $data['EstadoPago'] = 'PENDIENTE';
            }
        }

        $row->update($data);
        return response()->json(['data' => $row]);
    }

    public function destroy($id, Request $request)
    {
        $user = $request->user();

        $row = Estudianteseventos::query()
            ->where('id', '=', (int) $id)
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('instituciones_id', (int) $user->instituciones_id);
            })
            ->firstOrFail();

        $row->delete();
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }

    public function byEvento(int $eventoId, Request $request)
    {
        $request->merge(['eventos_id' => $eventoId]);
        return $this->index($request);
    }

    public function generarCertificado($id, Request $request)
    {
        $user = $request->user();

        $insc = Estudianteseventos::query()
            ->where('id', '=', (int) $id)
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('instituciones_id', (int) $user->instituciones_id);
            })
            ->firstOrFail();

        $evento = Eventos::query()
            ->where('id', '=', (int) $insc->eventos_id)
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('instituciones_id', (int) $user->instituciones_id);
            })
            ->firstOrFail();

        $designId = (int) ($evento->diseniocertificadopdfs_id ?? 0);
        if ($designId <= 0) {
            return response()->json(['error' => 'El evento no tiene un diseño de certificado asignado'], 422);
        }

        $design = Diseniocertificadopdfs::query()
            ->where('id', '=', $designId)
            ->when(!empty($user?->instituciones_id), function ($q) use ($user) {
                $q->where('instituciones_id', (int) $user->instituciones_id);
            })
            ->firstOrFail();

        $templatePath = trim((string) ($design->ArchivoPdf ?? ''));
        if ($templatePath === '') {
            return response()->json(['error' => 'El diseño no tiene ArchivoPdf'], 422);
        }

        $templateFullPath = public_path($templatePath);
        if (!File::exists($templateFullPath)) {
            return response()->json(['error' => 'No se encontró el PDF de plantilla en el servidor'], 422);
        }

        $configRaw = $evento->CertificadoConfig ?? $design->Parametros ?? '';
        $config = null;
        if (is_string($configRaw) && trim($configRaw) !== '') {
            try {
                $config = json_decode($configRaw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable $e) {
                return response()->json(['error' => 'CertificadoConfig/Parametros: JSON inválido'], 422);
            }
        }
        if (!$config || !is_array($config)) {
            return response()->json(['error' => 'No hay configuración de posiciones para el certificado (CertificadoConfig)'], 422);
        }

        $page = (int) ($config['page'] ?? 1);
        if ($page <= 0) $page = 1;
        $fields = $config['fields'] ?? [];
        if (!is_array($fields) || count($fields) === 0) {
            return response()->json(['error' => 'CertificadoConfig: falta fields[]'], 422);
        }

        $datosEspeciales = [];
        $rawDatos = $insc->DatosEspeciales ?? null;
        if ($rawDatos) {
            try {
                $datosEspeciales = is_string($rawDatos) ? (json_decode($rawDatos, true) ?: []) : (is_array($rawDatos) ? $rawDatos : []);
            } catch (\Throwable $e) {
                $datosEspeciales = [];
            }
        }

        $resolver = function (string $key) use ($insc, $evento, $datosEspeciales): string {
            $k = strtoupper(trim($key));
            $nombre = trim((string) ($insc->Nombres ?? ''));
            $apP = trim((string) ($insc->Ap_Paterno ?? ''));
            $apM = trim((string) ($insc->Ap_Materno ?? ''));
            $nombreCompleto = trim($nombre . ' ' . $apP . ' ' . $apM);

            if ($k === 'NOMBRE' || $k === 'NOMBRE_COMPLETO') return $nombreCompleto;
            if ($k === 'CARNET') return trim((string) ($insc->Carnet ?? ''));
            if ($k === 'EVENTO') return trim((string) ($evento->NombreEvento ?? ''));
            if ($k === 'ANIO') return trim((string) ($evento->Anio ?? ''));
            if ($k === 'FECHA') return date('d/m/Y');

            // Permitir que el diseño pida un dato del formulario especial
            foreach ($datosEspeciales as $dk => $dv) {
                if (strtoupper((string) $dk) === $k) {
                    if (is_scalar($dv)) return (string) $dv;
                    return json_encode($dv);
                }
            }

            return '';
        };

        $pdf = new Fpdi();
        $pageCount = $pdf->setSourceFile($templateFullPath);
        $pageNum = min(max(1, $page), $pageCount);

        $tplId = $pdf->importPage($pageNum);
        $size = $pdf->getTemplateSize($tplId);
        $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
        $pdf->useTemplate($tplId);

        foreach ($fields as $f) {
            if (!is_array($f)) continue;
            $key = (string) ($f['key'] ?? '');
            $x = $f['x'] ?? null;
            $y = $f['y'] ?? null;
            if ($key === '' || $x === null || $y === null) continue;

            $value = trim((string) ($f['value'] ?? ''));
            if ($value === '') {
                $value = $resolver($key);
            }
            if ($value === '') continue;

            $font = (string) ($f['font'] ?? 'Helvetica');
            $fontSize = (float) ($f['fontSize'] ?? 18);
            $align = strtoupper((string) ($f['align'] ?? 'L'));
            $maxWidth = $f['w'] ?? null;

            $pdf->SetFont($font, '', $fontSize);

            // Color en hex opcional
            $color = (string) ($f['color'] ?? '#000000');
            if (preg_match('/^#?[0-9A-Fa-f]{6}$/', $color)) {
                $hex = ltrim($color, '#');
                $r = hexdec(substr($hex, 0, 2));
                $g = hexdec(substr($hex, 2, 2));
                $b = hexdec(substr($hex, 4, 2));
                $pdf->SetTextColor($r, $g, $b);
            } else {
                $pdf->SetTextColor(0, 0, 0);
            }

            $pdf->SetXY((float) $x, (float) $y);

            if ($maxWidth !== null && is_numeric($maxWidth) && (float) $maxWidth > 0) {
                $pdf->Cell((float) $maxWidth, 0, $value, 0, 0, in_array($align, ['L', 'C', 'R']) ? $align : 'L');
            } else {
                $pdf->Write(0, $value);
            }
        }

        $institucionId = (int) ($insc->instituciones_id ?? ($user?->instituciones_id ?? 0));
        $outDir = 'archivos/institucion' . $institucionId . '/eventos/certificados/' . (int) $evento->id;
        if (!File::exists(public_path($outDir))) {
            File::makeDirectory(public_path($outDir), 0755, true, true);
        }

        $outName = 'cert_' . (int) $insc->id . '_' . time() . '.pdf';
        $outPath = $outDir . '/' . $outName;
        $pdf->Output('F', public_path($outPath));

        $insc->update([
            'CertificadoPdf' => $outPath,
            'CertificadoGeneradoAt' => date('Y-m-d H:i:s'),
        ]);

        return response()->json([
            'data' => [
                'filePath' => $outPath,
                'url' => url($outPath),
            ],
        ]);
    }
}
