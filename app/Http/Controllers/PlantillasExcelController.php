<?php

namespace App\Http\Controllers;

use App\Http\Middleware\UpdateTokenExpiration;
use App\Models\Controles;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use Illuminate\Support\Facades\DB;
use ZipArchive;

class PlantillasExcelController extends Controller
{
    private const TEMPLATE_REF_COUNT = 18;

    /**
     * CRUD independiente para Plantillas Excel, usando la tabla `controles`.
     *
     * Mapeo de columnas:
     * - Categoria   => MODO (ej: "MODO INSTRUMENTOS DE ESPECIALIDAD")
     * - ParaI       => Título de la plantilla
     * - Edades      => Ruta del archivo Excel (NO se normaliza a mayúsculas)
    * - NivelCurso  => Lista ordenada de refs Excel (ej: "B10, C3, C4, ...")
     * - Estado / Visibilidad
     */
    public function __construct()
    {
        $this->middleware(['auth:sanctum', UpdateTokenExpiration::class]);
    }

    private function upperTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (function_exists('mb_strtoupper')) {
            return mb_strtoupper($value, 'UTF-8');
        }

        return strtoupper($value);
    }

    private function parseTemplateRefs(?string $value, int $expectedCount = self::TEMPLATE_REF_COUNT): array
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return array_merge(['B10'], array_fill(0, max(0, $expectedCount - 1), ''));
        }

        $parts = array_map(function ($item) {
            return $this->upperTrim((string) $item) ?? '';
        }, explode(',', $raw));

        if ($expectedCount > 0) {
            $parts = array_slice(array_pad($parts, $expectedCount, ''), 0, $expectedCount);
        }

        if (($parts[0] ?? '') === '') {
            $parts[0] = 'B10';
        }

        return $parts;
    }

    private function normalizeExcelRef(string $rawRef): array
    {
        $rawRef = trim((string) $rawRef);
        $wrapParens = false;

        if (preg_match('/^\((.+)\)$/', $rawRef, $pm)) {
            $wrapParens = true;
            $rawRef = trim((string) ($pm[1] ?? ''));
        }

        $rawRef = str_replace('$', '', $rawRef);
        $rawRef = $this->upperTrim($rawRef) ?? '';

        return [$rawRef, $wrapParens];
    }

    private function allowedModos(): array
    {
        return [
            'MODO INSTRUMENTOS DE ESPECIALIDAD',
            'MODO PRACTICA DE CONJUNTOS',
            'MODO INSTRUMENTO COMPLEMENTARIO',
            '1 DOCENTE X MATERIA',
            'MULTIPLES DOCENTES X MATERIA',
        ];
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $modo = $this->upperTrim((string) $request->query('modo', ''));

        $query = Controles::query();

        // Por institución (requisito): siempre filtrar por instituciones_id del usuario,
        // excepto superlcchs que puede filtrar por param instituciones_id.
        $instParam = $request->query('instituciones_id');
        $institucionId = null;

        if ($user instanceof \App\Models\Usuarioslcchs) {
            $institucionId = $instParam !== null ? (int) $instParam : null;
        } else {
            $institucionId = !empty($user?->instituciones_id) ? (int) $user->instituciones_id : null;
        }

        if ($institucionId !== null && $institucionId > 0) {
            $query->where('instituciones_id', '=', $institucionId);
        } else {
            // sin institución: devolver vacío por seguridad
            return response()->json(['data' => []]);
        }

        // Filtrar solo categorías permitidas
        $query->whereIn('Categoria', $this->allowedModos());

        if ($modo !== '') {
            $query->where('Categoria', '=', $modo);
        }

        // Por defecto: solo visibles/activos para consumo (paralelos)
        // Para el CRUD se puede pedir include_inactivos=1
        $includeInactivos = (string) $request->query('include_inactivos', '0');
        if ($includeInactivos !== '1') {
            $query->where('Estado', '=', 'ACTIVO')
                ->where('Visibilidad', '=', 'VISIBLE');
        }

        $rows = $query->orderBy('Categoria')->orderBy('ParaI')->orderBy('id', 'desc')->get();
        return response()->json(['data' => $rows]);
    }

    public function show($id)
    {
        $user = request()->user();
        $row = Controles::where('id', '=', $id)->firstOrFail();

        $inst = (int) ($row->instituciones_id ?? 0);
        $userInst = (int) ($user?->instituciones_id ?? 0);

        if (!($user instanceof \App\Models\Usuarioslcchs) && $userInst > 0 && $inst !== $userInst) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        return response()->json(['data' => $row]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $payload = $request->all();

        $payload['Categoria'] = $this->upperTrim((string) ($payload['Categoria'] ?? ''));
        $payload['ParaI'] = $this->upperTrim((string) ($payload['ParaI'] ?? ''));
        $payload['NivelCurso'] = $this->upperTrim((string) ($payload['NivelCurso'] ?? ''));
        $payload['Estado'] = $this->upperTrim((string) ($payload['Estado'] ?? 'ACTIVO'));
        $payload['Visibilidad'] = $this->upperTrim((string) ($payload['Visibilidad'] ?? 'VISIBLE'));

        // Edades = ruta de archivo (NO upper)
        if (array_key_exists('Edades', $payload) && $payload['Edades'] !== null) {
            $payload['Edades'] = trim((string) $payload['Edades']);
        }

        if (!in_array($payload['Categoria'], $this->allowedModos(), true)) {
            return response()->json(['error' => 'Modo/Categoría inválido'], 422);
        }

        if (empty($payload['ParaI'])) {
            return response()->json(['error' => 'Título requerido'], 422);
        }

        if (empty($payload['Edades'])) {
            return response()->json(['error' => 'Archivo requerido'], 422);
        }

        // Institución
        if (!($user instanceof \App\Models\Usuarioslcchs)) {
            $payload['instituciones_id'] = (int) ($user?->instituciones_id ?? 0);
            if (empty($payload['instituciones_id'])) {
                return response()->json(['error' => 'Usuario sin institución'], 422);
            }
        } else {
            // super: puede enviar instituciones_id
            if (empty($payload['instituciones_id'])) {
                return response()->json(['error' => 'instituciones_id requerido'], 422);
            }
        }

        $id = Controles::insertGetId([
            'instituciones_id' => $payload['instituciones_id'],
            'Estado' => $payload['Estado'] ?: 'ACTIVO',
            'Visibilidad' => $payload['Visibilidad'] ?: 'VISIBLE',
            'Categoria' => $payload['Categoria'],
            'ParaI' => $payload['ParaI'],
            'Edades' => $payload['Edades'],
            'NivelCurso' => $payload['NivelCurso'],
        ]);

        $row = Controles::where('id', '=', $id)->first();
        return response()->json(['data' => $row]);
    }

    public function update(Request $request, $id)
    {
        $user = $request->user();
        $row = Controles::where('id', '=', $id)->firstOrFail();

        $inst = (int) ($row->instituciones_id ?? 0);
        $userInst = (int) ($user?->instituciones_id ?? 0);
        if (!($user instanceof \App\Models\Usuarioslcchs) && $userInst > 0 && $inst !== $userInst) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $payload = $request->all();

        $update = [];
        if (array_key_exists('Categoria', $payload)) {
            $cat = $this->upperTrim((string) $payload['Categoria']);
            if (!in_array($cat, $this->allowedModos(), true)) {
                return response()->json(['error' => 'Modo/Categoría inválido'], 422);
            }
            $update['Categoria'] = $cat;
        }
        if (array_key_exists('ParaI', $payload)) {
            $update['ParaI'] = $this->upperTrim((string) $payload['ParaI']);
        }
        if (array_key_exists('NivelCurso', $payload)) {
            $update['NivelCurso'] = $this->upperTrim((string) $payload['NivelCurso']);
        }
        if (array_key_exists('Estado', $payload)) {
            $update['Estado'] = $this->upperTrim((string) $payload['Estado']);
        }
        if (array_key_exists('Visibilidad', $payload)) {
            $update['Visibilidad'] = $this->upperTrim((string) $payload['Visibilidad']);
        }
        if (array_key_exists('Edades', $payload)) {
            // ruta (no upper)
            $update['Edades'] = trim((string) $payload['Edades']);
        }

        Controles::where('id', '=', $id)->update($update);
        $fresh = Controles::where('id', '=', $id)->first();
        return response()->json(['data' => $fresh]);
    }

    public function destroy($id)
    {
        $user = request()->user();
        $row = Controles::where('id', '=', $id)->firstOrFail();

        $inst = (int) ($row->instituciones_id ?? 0);
        $userInst = (int) ($user?->instituciones_id ?? 0);
        if (!($user instanceof \App\Models\Usuarioslcchs) && $userInst > 0 && $inst !== $userInst) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        Controles::destroy($id);
        return response()->json(['data' => 'ELIMINADO EXITOSAMENTE']);
    }

    public function generar(Request $request, $id)
    {
        $user = $request->user();
        $row = Controles::where('id', '=', $id)->firstOrFail();

        $inst = (int) ($row->instituciones_id ?? 0);
        $userInst = (int) ($user?->instituciones_id ?? 0);
        if (!($user instanceof \App\Models\Usuarioslcchs) && $userInst > 0 && $inst !== $userInst) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $path = trim((string) ($row->Edades ?? ''));
        $path = ltrim($path, '/');
        if ($path === '') {
            return response()->json(['error' => 'Plantilla sin archivo'], 422);
        }

        $fullPath = public_path($path);
        if (!is_file($fullPath)) {
            return response()->json(['error' => 'Archivo de plantilla no encontrado'], 404);
        }

        $payload = $request->all();
        $rows = $payload['rows'] ?? null;
        if (!is_array($rows)) {
            return response()->json(['error' => 'rows requerido (array)'], 422);
        }

        $refs = $this->parseTemplateRefs((string) ($row->NivelCurso ?? ''));
        [$startCell, ] = $this->normalizeExcelRef((string) ($refs[0] ?? ''));

        // Nombre de descarga
        try {
            [$colLetters, $rowNum] = Coordinate::coordinateFromString($startCell);
            $startCol = Coordinate::columnIndexFromString($colLetters);
            $startRow = (int) $rowNum;
            if ($startRow <= 0 || $startCol <= 0) {
                throw new \RuntimeException('Celda inválida');
            }
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Celda inicial inválida (NivelCurso). Use formato tipo B10.'], 422);
        }
        $safeName = trim((string) ($payload['filename'] ?? 'registro'));
        $safeName = preg_replace('/[^a-zA-Z0-9_\- ]/', '', $safeName);
        $safeName = $safeName ?: 'registro';

        $ext = strtolower((string) pathinfo($fullPath, PATHINFO_EXTENSION));
        $downloadExt = $ext === 'xlsm' ? 'xlsm' : 'xlsx';
        $downloadName = $safeName . '.' . $downloadExt;

        // Auto-lookup: si se envía materiaId, buscar datos de carrera/plandeestudios/instituciones
        // para rellenar campos que el frontend no envíe explícitamente.
        $materiaId = !empty($payload['materiaId']) ? (int) $payload['materiaId'] : 0;
        if ($materiaId > 0) {
            $meta = DB::table('materias')
                ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
                ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
                ->join('instituciones', 'carreras.instituciones_id', '=', 'instituciones.id')
                ->where('materias.id', '=', $materiaId)
                ->select([
                    'carreras.NombreCarrera',
                    'carreras.Nivel',
                    'carreras.Area',
                    'carreras.Mencion',
                    'carreras.Resolucion',
                    'carreras.HorasTotales',
                    'carreras.TituloOficial',
                    'plandeestudios.SiglaMateria',
                    'materias.Turno',
                    'instituciones.Nombre as NombreInstitucion',
                ])
                ->first();

            if ($meta) {
                // Solo rellenar si el frontend NO envió un valor explícito
                $autoFill = [
                    'carrera'      => $meta->NombreCarrera,
                    'nivel'        => $meta->Nivel,
                    'sigla'        => $meta->SiglaMateria,
                    'turno'        => $meta->Turno,
                    'area'         => $meta->Area,
                    'institucion'  => $meta->NombreInstitucion,
                    'resolucion'   => $meta->Resolucion,
                    'horasTotales' => $meta->HorasTotales,
                    'mencion'      => $meta->Mencion,
                    'tituloOficial' => $meta->TituloOficial,
                ];
                foreach ($autoFill as $key => $dbVal) {
                    if (empty($payload[$key]) && !empty($dbVal)) {
                        $payload[$key] = (string) $dbVal;
                    }
                }
            }
        }

        // Celdas adicionales (metadatos)
        $cellUpdates = $this->buildCellUpdates($refs, $payload);

        // se parchea el ZIP (xlsx/xlsm) y se actualiza SOLO la hoja INSC.
        try {
            return $this->generarParcheandoZip($fullPath, 'INSC.', $startCol, $startRow, $rows, $downloadName, $cellUpdates);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'No se pudo generar el Excel'], 500);
        }
    }

    /**
     * ACTUALIZAR DATOS: el usuario sube su archivo Excel (parcial) y recibe
     * el mismo archivo con los datos de estudiantes y metadatos actualizados.
     * Usa la configuración de celdas de la plantilla indicada por {id}.
     */
    public function actualizar(Request $request, $id)
    {
        $user = $request->user();
        $row = Controles::where('id', '=', $id)->firstOrFail();

        $inst = (int) ($row->instituciones_id ?? 0);
        $userInst = (int) ($user?->instituciones_id ?? 0);
        if (!($user instanceof \App\Models\Usuarioslcchs) && $userInst > 0 && $inst !== $userInst) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        // Validar que se subió un archivo
        if (!$request->hasFile('archivo')) {
            return response()->json(['error' => 'Debe subir un archivo Excel (campo "archivo")'], 422);
        }

        $file = $request->file('archivo');
        $fileExt = strtolower((string) $file->getClientOriginalExtension());
        if (!in_array($fileExt, ['xlsx', 'xlsm', 'xltx'], true)) {
            return response()->json(['error' => 'Solo se permiten archivos .xlsx, .xlsm o .xltx'], 422);
        }

        $uploadedPath = $file->getRealPath();
        if (!$uploadedPath || !is_file($uploadedPath)) {
            return response()->json(['error' => 'No se pudo leer el archivo subido'], 422);
        }

        // Payload JSON viene como campos adicionales del multipart
        $rows = json_decode((string) ($request->input('rows', '[]')), true);
        if (!is_array($rows)) {
            return response()->json(['error' => 'rows requerido (JSON array)'], 422);
        }

        // Reusar la misma lógica de refs de la plantilla
        $refs = $this->parseTemplateRefs((string) ($row->NivelCurso ?? ''));
        [$startCell, ] = $this->normalizeExcelRef((string) ($refs[0] ?? ''));

        try {
            [$colLetters, $rowNum] = Coordinate::coordinateFromString($startCell);
            $startCol = Coordinate::columnIndexFromString($colLetters);
            $startRow = (int) $rowNum;
            if ($startRow <= 0 || $startCol <= 0) {
                throw new \RuntimeException('Celda inválida');
            }
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Celda inicial inválida en la plantilla.'], 422);
        }

        $safeName = trim((string) ($request->input('filename', 'registro_actualizado')));
        $safeName = preg_replace('/[^a-zA-Z0-9_\- ]/', '', $safeName);
        $safeName = $safeName ?: 'registro_actualizado';

        $downloadExt = $fileExt === 'xlsm' ? 'xlsm' : 'xlsx';
        $downloadName = $safeName . '.' . $downloadExt;

        // Construir payload desde input (multipart fields)
        $payload = $request->all();

        // Auto-lookup desde materiaId (igual que generar)
        $materiaId = !empty($payload['materiaId']) ? (int) $payload['materiaId'] : 0;
        if ($materiaId > 0) {
            $meta = DB::table('materias')
                ->join('plandeestudios', 'materias.plandeestudios_id', '=', 'plandeestudios.id')
                ->join('carreras', 'plandeestudios.carreras_id', '=', 'carreras.id')
                ->join('instituciones', 'carreras.instituciones_id', '=', 'instituciones.id')
                ->where('materias.id', '=', $materiaId)
                ->select([
                    'carreras.NombreCarrera',
                    'carreras.Nivel',
                    'carreras.Area',
                    'carreras.Mencion',
                    'carreras.Resolucion',
                    'carreras.HorasTotales',
                    'carreras.TituloOficial',
                    'plandeestudios.SiglaMateria',
                    'materias.Turno',
                    'instituciones.Nombre as NombreInstitucion',
                ])
                ->first();

            if ($meta) {
                $autoFill = [
                    'carrera'      => $meta->NombreCarrera,
                    'nivel'        => $meta->Nivel,
                    'sigla'        => $meta->SiglaMateria,
                    'turno'        => $meta->Turno,
                    'area'         => $meta->Area,
                    'institucion'  => $meta->NombreInstitucion,
                    'resolucion'   => $meta->Resolucion,
                    'horasTotales' => $meta->HorasTotales,
                    'mencion'      => $meta->Mencion,
                    'tituloOficial' => $meta->TituloOficial,
                ];
                foreach ($autoFill as $key => $dbVal) {
                    if (empty($payload[$key]) && !empty($dbVal)) {
                        $payload[$key] = (string) $dbVal;
                    }
                }
            }
        }

        // Construir cellUpdates (misma lógica que generar)
        $cellUpdates = $this->buildCellUpdates($refs, $payload);

        try {
            return $this->generarParcheandoZip($uploadedPath, 'INSC.', $startCol, $startRow, $rows, $downloadName, $cellUpdates);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'No se pudo actualizar el Excel: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Construir el array de actualizaciones de celdas (metadatos) a partir
     * de las refs de la plantilla y el payload.
     */
    private function buildCellUpdates(array $refs, array $payload): array
    {
        $cellUpdates = [];
        $valuesByOrder = [
            (string) ($payload['docente'] ?? ''),
            (string) ($payload['materia'] ?? ''),
            (string) ($payload['curso'] ?? ''),
            (string) ($payload['gestion'] ?? ''),
            (string) ($payload['carrera'] ?? ''),
            (string) ($payload['nivel'] ?? ''),
            (string) ($payload['sigla'] ?? ''),
            (string) ($payload['turno'] ?? ''),
            (string) ($payload['area'] ?? ''),
            (string) ($payload['institucion'] ?? ''),
            (string) ($payload['resolucion'] ?? ''),
            (string) ($payload['horasTotales'] ?? ''),
            (string) ($payload['mencion'] ?? ''),
            (string) ($payload['tituloOficial'] ?? ''),
            (string) ($payload['instrumentoPrincipal'] ?? $payload['InstrumentoMusical'] ?? ''),
            (string) ($payload['instrumentoSecundario'] ?? $payload['InstrumentoMusicalSecundario'] ?? ''),
            (string) ($payload['paralelo'] ?? ''),
        ];

        $metaRefs = array_slice($refs, 1);
        for ($i = 0; $i < count($valuesByOrder); $i++) {
            if (!isset($metaRefs[$i])) {
                continue;
            }
            $rawRef = trim((string) $metaRefs[$i]);
            if ($rawRef === '') {
                continue;
            }

            [$ref, $wrapParens] = $this->normalizeExcelRef($rawRef);

            try {
                [$cLetters, $rNum] = Coordinate::coordinateFromString($ref);
                $addr = strtoupper($cLetters) . (int) $rNum;
                $val = $valuesByOrder[$i];
                if ($wrapParens && $val !== '') {
                    $val = '(' . $val . ')';
                }
                $cellUpdates[$addr] = $val;
            } catch (\Throwable $e) {
                // ignorar refs inválidas
            }
        }

        return $cellUpdates;
    }

    private function generarParcheandoZip(string $templatePath, string $sheetName, int $startCol, int $startRow, array $rows, string $downloadName, array $cellUpdates = [])
    {
        if (!class_exists('ZipArchive')) {
            return response()->json(['error' => 'Falta habilitar la extensión ZIP de PHP (ZipArchive).'], 500);
        }
        if (!class_exists('DOMDocument')) {
            return response()->json(['error' => 'Falta habilitar la extensión DOM de PHP (DOMDocument).'], 500);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'excel_');
        if ($tmp === false) {
            return response()->json(['error' => 'No se pudo crear archivo temporal'], 500);
        }

        $ext = strtolower((string) pathinfo($templatePath, PATHINFO_EXTENSION));
        $downloadExt = $ext === 'xlsm' ? 'xlsm' : 'xlsx';
        $tmpFile = $tmp . '.' . $downloadExt;
        if (!@copy($templatePath, $tmpFile)) {
            @unlink($tmp);
            return response()->json(['error' => 'No se pudo copiar la plantilla'], 500);
        }

        $zip = new ZipArchive();
        $open = $zip->open($tmpFile);
        if ($open !== true) {
            @unlink($tmp);
            @unlink($tmpFile);
            return response()->json(['error' => 'No se pudo abrir la plantilla'], 500);
        }

        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookXml === false || $relsXml === false) {
            $zip->close();
            @unlink($tmp);
            @unlink($tmpFile);
            return response()->json(['error' => 'Plantilla inválida (workbook)'], 422);
        }

        $sheetPath = $this->resolverRutaHojaDesdeWorkbook($workbookXml, $relsXml, $sheetName);
        if ($sheetPath === null) {
            $zip->close();
            @unlink($tmp);
            @unlink($tmpFile);
            return response()->json(['error' => 'La plantilla no contiene la hoja INSC.'], 422);
        }

        $sheetXml = $zip->getFromName($sheetPath);
        if ($sheetXml === false) {
            $zip->close();
            @unlink($tmp);
            @unlink($tmpFile);
            return response()->json(['error' => 'No se pudo leer la hoja INSC.'], 422);
        }

        $patched = $this->parchearSheetXml($sheetXml, $startCol, $startRow, $rows, $cellUpdates);
        $zip->addFromString($sheetPath, $patched);

        // ── Compatibilidad y recálculo de fórmulas ──
        // 1) Excel antiguos muestran "=_xlfn.CONCAT(...)" y no calculan CONCAT.
        //    Convertimos CONCAT/_xlfn.CONCAT a CONCATENATE.
        // 2) En algunos visores móviles no se recalculan fórmulas al abrir,
        //    por lo que necesitamos escribir valores cacheados <v> para fórmulas
        //    simples que dependen de INSC.
        // 3) Además, forzamos recálculo completo en Excel de escritorio.
        $workbookXml = $this->forzarFullCalcOnLoad($workbookXml);
        $zip->addFromString('xl/workbook.xml', $workbookXml);

        // Compatibilizar fórmulas y refrescar caches (sin borrar todo)
        $inscValueMap = $this->buildInscValueMap($startCol, $startRow, $rows, $cellUpdates);
        $this->compatibilizarFormulasYCacheOtrasHojas($zip, $relsXml, $sheetPath, $inscValueMap);

        $zip->close();
        @unlink($tmp);

        $mime = $downloadExt === 'xlsm'
            ? 'application/vnd.ms-excel.sheet.macroEnabled.12'
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        return response()->download($tmpFile, $downloadName, [
            'Content-Type' => $mime,
        ])->deleteFileAfterSend(true);
    }

    /**
     * Construye un mapa de valores escritos en la hoja INSC. para poder
     * resolver fórmulas de otras hojas (especialmente CONCAT/CONCATENATE).
     */
    private function buildInscValueMap(int $startCol, int $startRow, array $rows, array $cellUpdates): array
    {
        $map = [];

        // Filas de estudiantes (tabla)
        $r = $startRow;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                $r++;
                continue;
            }
            $c = $startCol;
            foreach ($row as $val) {
                $addr = strtoupper(Coordinate::stringFromColumnIndex($c)) . (int) $r;
                $map[$addr] = (string) ($val ?? '');
                $c++;
            }
            $r++;
        }

        // Metadatos adicionales
        foreach ($cellUpdates as $addr => $val) {
            $addrNorm = strtoupper(trim((string) $addr));
            if ($addrNorm === '') continue;
            $map[$addrNorm] = (string) ($val ?? '');
        }

        return $map;
    }

    /**
     * Recorre hojas (excepto la parcheada) para:
     * - Convertir CONCAT/_xlfn.CONCAT a CONCATENATE (compatibilidad).
     * - Escribir <v> cacheado para fórmulas simples que dependen de INSC.
     */
    private function compatibilizarFormulasYCacheOtrasHojas(ZipArchive $zip, string $relsXml, string $patchedSheetPath, array $inscValueMap): void
    {
        $rels = new \DOMDocument();
        $rels->preserveWhiteSpace = false;
        $rels->loadXML($relsXml);

        $relsNodes = $rels->getElementsByTagName('Relationship');
        $sheetPaths = [];
        foreach ($relsNodes as $rel) {
            $type = $rel->getAttribute('Type');
            if (
                strpos($type, '/worksheet') === false &&
                strpos($type, '/chartsheet') === false
            ) {
                continue;
            }
            $target = $rel->getAttribute('Target');
            if (!$target) continue;
            $target = ltrim($target, '/');
            $fullPath = 'xl/' . $target;
            if ($fullPath === $patchedSheetPath) continue;
            $sheetPaths[] = $fullPath;
        }

        foreach ($sheetPaths as $sp) {
            $xml = $zip->getFromName($sp);
            if ($xml === false) continue;

            $patched = $this->parchearCompatFormulasYCachesEnSheet($xml, $inscValueMap);
            if ($patched !== null) {
                $zip->addFromString($sp, $patched);
            }
        }
    }

    /**
     * En una hoja XML:
     * - Reemplaza CONCAT/_xlfn.CONCAT por CONCATENATE.
     * - Actualiza el <v> (valor cacheado) para fórmulas simples que referencian INSC.
     * Devuelve null si no hubo cambios.
     */
    private function parchearCompatFormulasYCachesEnSheet(string $sheetXml, array $inscValueMap): ?string
    {
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput = false;
        $dom->loadXML($sheetXml);

        $changed = false;
        $cells = $dom->getElementsByTagName('c');
        foreach ($cells as $cell) {
            $fNode = null;
            $vNode = null;
            foreach ($cell->childNodes as $child) {
                if (!($child instanceof \DOMElement)) continue;
                if ($child->localName === 'f') {
                    $fNode = $child;
                }
                if ($child->localName === 'v') {
                    $vNode = $child;
                }
            }
            if (!$fNode) continue;

            $formula = (string) ($fNode->textContent ?? '');
            $formulaTrim = ltrim($formula);
            if (str_starts_with($formulaTrim, '=')) {
                $formulaTrim = ltrim(substr($formulaTrim, 1));
            }

            // Compat: CONCAT -> CONCATENATE
            $newFormulaTrim = preg_replace('/(?:_xlfn\.)?CONCAT\(/i', 'CONCATENATE(', $formulaTrim);
            if ($newFormulaTrim !== null && $newFormulaTrim !== $formulaTrim) {
                $fNode->nodeValue = $newFormulaTrim;
                $formulaTrim = $newFormulaTrim;
                $changed = true;
            }

            // Cache: si podemos calcular el resultado, setear <v>
            $computed = $this->tryComputeFormulaValueFromInsc($formulaTrim, $inscValueMap);
            if ($computed !== null) {
                if (!$vNode) {
                    $vNode = $dom->createElement('v');
                    $cell->appendChild($vNode);
                }
                $vNode->nodeValue = $computed;
                // Resultado string
                $cell->setAttribute('t', 'str');
                $changed = true;
            }
        }

        if (!$changed) {
            return null;
        }

        $xml = $dom->saveXML();
        $xml = preg_replace(
            '/^<\?xml [^?]*\?>/',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
            $xml
        );
        return $xml;
    }

    /**
     * Intenta calcular el valor de una fórmula simple basada en INSC.
     * Soporta:
     * - Referencia directa: INSC.!B2
     * - CONCATENATE/CONCAT: CONCATENATE(INSC.!B2," ",INSC.!C2)
     */
    private function tryComputeFormulaValueFromInsc(string $formula, array $inscValueMap): ?string
    {
        $f = trim($formula);
        if ($f === '') return null;

        // Referencia directa a INSC
        $direct = $this->parseInscCellRef($f);
        if ($direct !== null) {
            return (string) ($inscValueMap[$direct] ?? '');
        }

        // CONCATENATE(...)
        if (preg_match('/^CONCATENATE\((.*)\)$/i', $f, $m)) {
            $args = $this->splitFormulaArgs($m[1]);
            if ($args === null) return null;
            $out = '';
            foreach ($args as $arg) {
                $arg = trim($arg);
                if ($arg === '') continue;

                // string literal
                if (preg_match('/^"(.*)"$/s', $arg, $sm)) {
                    $lit = str_replace('""', '"', $sm[1]);
                    $out .= $lit;
                    continue;
                }

                $ref = $this->parseInscCellRef($arg);
                if ($ref !== null) {
                    $out .= (string) ($inscValueMap[$ref] ?? '');
                    continue;
                }

                // No soportado
                return null;
            }
            return $out;
        }

        return null;
    }

    /**
     * Divide argumentos de una función, soportando separadores ',' o ';'
     * y respetando comillas.
     */
    private function splitFormulaArgs(string $inside): ?array
    {
        $args = [];
        $buf = '';
        $inQuotes = false;
        $depth = 0;
        $len = strlen($inside);
        for ($i = 0; $i < $len; $i++) {
            $ch = $inside[$i];
            if ($ch === '"') {
                // manejar comillas escapadas ""
                $next = $i + 1 < $len ? $inside[$i + 1] : '';
                if ($inQuotes && $next === '"') {
                    $buf .= '""';
                    $i++;
                    continue;
                }
                $inQuotes = !$inQuotes;
                $buf .= $ch;
                continue;
            }

            if (!$inQuotes) {
                if ($ch === '(') $depth++;
                if ($ch === ')' && $depth > 0) $depth--;

                if ($depth === 0 && ($ch === ',' || $ch === ';')) {
                    $args[] = $buf;
                    $buf = '';
                    continue;
                }
            }

            $buf .= $ch;
        }

        if ($inQuotes) {
            return null;
        }

        $args[] = $buf;
        return $args;
    }

    /**
     * Si el string representa una referencia a una celda de INSC, devuelve el addr (ej: B2).
     * Acepta variantes: INSC.!B2, 'INSC.'!$B$2, INSC!B2
     */
    private function parseInscCellRef(string $expr): ?string
    {
        $raw = trim($expr);
        if ($raw === '') return null;
        $raw = str_replace('$', '', $raw);

        // separar por '!'
        if (strpos($raw, '!') !== false) {
            [$sheet, $ref] = explode('!', $raw, 2);
            $sheet = trim($sheet, "'\"");
            $sheet = rtrim($sheet);
            $sheetNorm = strtoupper(rtrim($sheet, '.'));
            if ($sheetNorm !== 'INSC') return null;
            $ref = trim($ref);
            if (preg_match('/^([A-Z]{1,3})(\d+)$/i', $ref, $m)) {
                return strtoupper($m[1]) . (int) $m[2];
            }
            return null;
        }

        // variante sin '!': INSC.B2 o INSCB2 no se soporta; INSCB2 ambiguo.
        // Variante: INSC.B2 no estándar, ignorar.
        if (preg_match('/^(?:\'INSC\.\'|INSC\.)\s*([A-Z]{1,3})(\d+)$/i', $raw, $m)) {
            return strtoupper($m[1]) . (int) $m[2];
        }

        return null;
    }

    private function resolverRutaHojaDesdeWorkbook(string $workbookXml, string $relsXml, string $sheetName): ?string
    {
        $wb = new \DOMDocument();
        $wb->preserveWhiteSpace = false;
        $wb->loadXML($workbookXml);

        $targetRid = null;
        $sheets = $wb->getElementsByTagName('sheet');
        foreach ($sheets as $sheet) {
            $name = $sheet->getAttribute('name');
            if (strtoupper($name) === strtoupper($sheetName)) {
                // atributo r:id está en namespace, pero DOM permite obtenerlo con getAttribute('r:id')
                $rid = $sheet->getAttribute('r:id');
                if ($rid) {
                    $targetRid = $rid;
                    break;
                }
            }
        }
        if ($targetRid === null) {
            return null;
        }

        $rels = new \DOMDocument();
        $rels->preserveWhiteSpace = false;
        $rels->loadXML($relsXml);

        $relsNodes = $rels->getElementsByTagName('Relationship');
        foreach ($relsNodes as $rel) {
            if ($rel->getAttribute('Id') === $targetRid) {
                $target = $rel->getAttribute('Target');
                if (!$target) return null;
                $target = ltrim($target, '/');
                return 'xl/' . $target;
            }
        }

        return null;
    }

    /**
     * Modificar workbook.xml para forzar recálculo completo al abrir.
     * Añade/actualiza fullCalcOnLoad="1" en <calcPr>.
     */
    private function forzarFullCalcOnLoad(string $workbookXml): string
    {
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput = false;
        $dom->loadXML($workbookXml);

        $nsUri = $dom->documentElement->namespaceURI
              ?? 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

        $calcPrList = $dom->getElementsByTagNameNS($nsUri, 'calcPr');
        if ($calcPrList->length > 0) {
            $calcPr = $calcPrList->item(0);
        } else {
            // No existe <calcPr>: crearlo como hijo de <workbook>
            $calcPr = $dom->createElementNS($nsUri, 'calcPr');
            $dom->documentElement->appendChild($calcPr);
        }

        $calcPr->setAttribute('fullCalcOnLoad', '1');
        $calcPr->setAttribute('forceFullCalc', '1');

        $xml = $dom->saveXML();
        $xml = preg_replace(
            '/^<\?xml [^?]*\?>/',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
            $xml
        );

        return $xml;
    }

    /**
     * Recorrer TODAS las hojas del workbook excepto la hoja parcheada (INSC.)
     * y eliminar los nodos <v> de cualquier celda que contenga <f> (fórmula).
     * Esto obliga a Excel a recalcular esas fórmulas al abrir.
     */
    private function limpiarCachesFormulasOtrasHojas(ZipArchive $zip, string $relsXml, string $patchedSheetPath): void
    {
        $rels = new \DOMDocument();
        $rels->preserveWhiteSpace = false;
        $rels->loadXML($relsXml);

        $relsNodes = $rels->getElementsByTagName('Relationship');
        $sheetPaths = [];
        foreach ($relsNodes as $rel) {
            $type = $rel->getAttribute('Type');
            // Solo hojas de cálculo
            if (
                strpos($type, '/worksheet') === false &&
                strpos($type, '/chartsheet') === false
            ) {
                continue;
            }
            $target = $rel->getAttribute('Target');
            if (!$target) continue;
            $target = ltrim($target, '/');
            $fullPath = 'xl/' . $target;
            // Excluir la hoja que ya parcheamos (INSC.)
            if ($fullPath === $patchedSheetPath) continue;
            $sheetPaths[] = $fullPath;
        }

        foreach ($sheetPaths as $sp) {
            $xml = $zip->getFromName($sp);
            if ($xml === false) continue;

            $cleaned = $this->limpiarCacheFormulasEnSheet($xml);
            if ($cleaned !== null) {
                $zip->addFromString($sp, $cleaned);
            }
        }
    }

    /**
     * En una hoja XML, para cada celda <c> que tenga un hijo <f> (fórmula),
     * eliminar los hijos <v> (valor cacheado) para forzar recálculo.
     * Devuelve null si no hubo cambios (optimización).
     */
    private function limpiarCacheFormulasEnSheet(string $sheetXml): ?string
    {
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput = false;
        $dom->loadXML($sheetXml);

        $changed = false;
        $cells = $dom->getElementsByTagName('c');
        foreach ($cells as $cell) {
            $hasFormula = false;
            $cachedValues = [];
            foreach ($cell->childNodes as $child) {
                if ($child instanceof \DOMElement) {
                    if ($child->localName === 'f') {
                        $hasFormula = true;
                    }
                    if ($child->localName === 'v') {
                        $cachedValues[] = $child;
                    }
                }
            }
            if ($hasFormula && count($cachedValues) > 0) {
                foreach ($cachedValues as $v) {
                    $cell->removeChild($v);
                }
                $changed = true;
            }
        }

        if (!$changed) {
            return null;
        }

        $xml = $dom->saveXML();
        $xml = preg_replace(
            '/^<\?xml [^?]*\?>/',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
            $xml
        );
        return $xml;
    }

    private function parchearSheetXml(string $sheetXml, int $startCol, int $startRow, array $rows, array $cellUpdates = []): string
    {
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput = false;
        $dom->loadXML($sheetXml);

        // Detectar el namespace principal de la hoja (spreadsheetml)
        $nsUri = $dom->documentElement->namespaceURI ?? 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

        $sheetData = $dom->getElementsByTagNameNS($nsUri, 'sheetData')->item(0)
                  ?? $dom->getElementsByTagName('sheetData')->item(0);
        if (!$sheetData) {
            return $sheetXml;
        }

        // Índice rápido de filas existentes
        $rowIndex = [];
        foreach ($sheetData->getElementsByTagName('row') as $rowEl) {
            $rAttr = $rowEl->getAttribute('r');
            if ($rAttr !== '') {
                $rowIndex[(int) $rAttr] = $rowEl;
            }
        }

        $setInlineStrCell = function (string $addr, string $value) use (&$dom, $sheetData, &$rowIndex, $nsUri) {
            try {
                [$colLetters, $rowNum] = Coordinate::coordinateFromString($addr);
                $r = (int) $rowNum;
                if ($r <= 0) return;
                $addrNorm = strtoupper($colLetters) . $r;

                $rowEl = $rowIndex[$r] ?? null;
                if (!$rowEl) {
                    $rowEl = $dom->createElementNS($nsUri, 'row');
                    $rowEl->setAttribute('r', (string) $r);
                    $sheetData->appendChild($rowEl);
                    $rowIndex[$r] = $rowEl;
                }

                $cell = null;
                foreach ($rowEl->getElementsByTagName('c') as $c) {
                    if ($c->getAttribute('r') === $addrNorm) {
                        $cell = $c;
                        break;
                    }
                }
                if (!$cell) {
                    $cell = $dom->createElementNS($nsUri, 'c');
                    $cell->setAttribute('r', $addrNorm);
                    $rowEl->appendChild($cell);
                }

                // No sobrescribir fórmulas si existen
                foreach ($cell->childNodes as $child) {
                    if ($child instanceof \DOMElement && $child->localName === 'f') {
                        return;
                    }
                }

                // Limpiar nodos de valor anteriores (v/is)
                $toRemove = [];
                foreach ($cell->childNodes as $child) {
                    if ($child instanceof \DOMElement && ($child->localName === 'v' || $child->localName === 'is')) {
                        $toRemove[] = $child;
                    }
                }
                foreach ($toRemove as $n) {
                    $cell->removeChild($n);
                }

                $cell->setAttribute('t', 'inlineStr');
                $is = $dom->createElementNS($nsUri, 'is');
                $t = $dom->createElementNS($nsUri, 't');
                $t->setAttribute('xml:space', 'preserve');
                $t->appendChild($dom->createTextNode($value));
                $is->appendChild($t);
                $cell->appendChild($is);
            } catch (\Throwable $e) {
                return;
            }
        };

        // Aplicar celdas adicionales (cabeceras) antes de la tabla
        if (!empty($cellUpdates)) {
            foreach ($cellUpdates as $addr => $value) {
                $setInlineStrCell((string) $addr, (string) $value);
            }
        }

        $r = $startRow;
        foreach ($rows as $vals) {
            if (!is_array($vals)) {
                $r++;
                continue;
            }
            $vals = array_values($vals);
            // Compatibilidad: históricamente se pegaban 5 columnas.
            // Ahora se soporta pegar N columnas (mínimo 5) según el payload.
            $colCount = max(5, count($vals));
            // Límite defensivo para evitar archivos enormes por error de payload.
            $colCount = min($colCount, 50);

            $rowEl = $rowIndex[$r] ?? null;
            if (!$rowEl) {
                $rowEl = $dom->createElementNS($nsUri, 'row');
                $rowEl->setAttribute('r', (string) $r);
                $sheetData->appendChild($rowEl);
                $rowIndex[$r] = $rowEl;
            }

            // Índice rápido de celdas existentes en la fila
            $cellIndex = [];
            foreach ($rowEl->getElementsByTagName('c') as $c) {
                $ref = $c->getAttribute('r');
                if ($ref !== '') {
                    $cellIndex[$ref] = $c;
                }
            }

            for ($i = 0; $i < $colCount; $i++) {
                $addr = Coordinate::stringFromColumnIndex($startCol + $i) . $r;
                $cell = $cellIndex[$addr] ?? null;
                if (!$cell) {
                    $cell = $dom->createElementNS($nsUri, 'c');
                    $cell->setAttribute('r', $addr);
                    $rowEl->appendChild($cell);
                    $cellIndex[$addr] = $cell;
                }

                // No sobrescribir fórmulas si existen
                $hasFormula = false;
                foreach ($cell->childNodes as $child) {
                    if ($child instanceof \DOMElement && $child->localName === 'f') {
                        $hasFormula = true;
                        break;
                    }
                }
                if ($hasFormula) {
                    continue;
                }

                // Limpiar nodos de valor anteriores (v/is)
                $toRemove = [];
                foreach ($cell->childNodes as $child) {
                    if ($child instanceof \DOMElement && ($child->localName === 'v' || $child->localName === 'is')) {
                        $toRemove[] = $child;
                    }
                }
                foreach ($toRemove as $n) {
                    $cell->removeChild($n);
                }

                // Escribir como inlineStr para no tocar sharedStrings
                $cell->setAttribute('t', 'inlineStr');
                $is = $dom->createElementNS($nsUri, 'is');
                $t = $dom->createElementNS($nsUri, 't');
                $t->setAttribute('xml:space', 'preserve');
                $t->appendChild($dom->createTextNode((string) ($vals[$i] ?? '')));
                $is->appendChild($t);
                $cell->appendChild($is);
            }

            $r++;
        }

        // ─── ORDENAR filas y celdas para cumplir con el formato xlsx ───
        // Excel exige que <row> estén ordenadas ascendentemente por atributo "r"
        // y <c> dentro de cada fila estén ordenados por columna ascendente.
        $allRows = [];
        foreach ($sheetData->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === 'row') {
                $allRows[] = $child;
            }
        }

        // Ordenar filas por número de fila
        usort($allRows, function (\DOMElement $a, \DOMElement $b) {
            return (int) $a->getAttribute('r') - (int) $b->getAttribute('r');
        });

        // Reordenar en el DOM
        foreach ($allRows as $rowEl) {
            $sheetData->appendChild($rowEl); // appendChild mueve el nodo existente

            // Ordenar celdas dentro de la fila por columna
            $cells = [];
            foreach ($rowEl->childNodes as $child) {
                if ($child instanceof \DOMElement && $child->localName === 'c') {
                    $cells[] = $child;
                }
            }
            if (count($cells) > 1) {
                usort($cells, function (\DOMElement $a, \DOMElement $b) {
                    try {
                        $colA = Coordinate::columnIndexFromString(
                            Coordinate::coordinateFromString($a->getAttribute('r'))[0]
                        );
                        $colB = Coordinate::columnIndexFromString(
                            Coordinate::coordinateFromString($b->getAttribute('r'))[0]
                        );
                        return $colA - $colB;
                    } catch (\Throwable $e) {
                        return 0;
                    }
                });
                foreach ($cells as $cellEl) {
                    $rowEl->appendChild($cellEl);
                }
            }
        }

        // Preservar la declaración XML original del sheet (encoding + standalone)
        $xml = $dom->saveXML();
        $xml = preg_replace(
            '/^<\?xml [^?]*\?>/',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
            $xml
        );

        return $xml;
    }
}
