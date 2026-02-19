<?php

namespace App\Http\Controllers;

use App\Http\Middleware\UpdateTokenExpiration;
use App\Models\Controles;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use ZipArchive;

class PlantillasExcelController extends Controller
{
    /**
     * CRUD independiente para Plantillas Excel, usando la tabla `controles`.
     *
     * Mapeo de columnas:
     * - Categoria   => MODO (ej: "MODO INSTRUMENTOS DE ESPECIALIDAD")
     * - ParaI       => Título de la plantilla
     * - Edades      => Ruta del archivo Excel (NO se normaliza a mayúsculas)
     * - NivelCurso  => Celda inicial (ej: "B10")
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

        $nivelCursoRaw = trim((string) ($row->NivelCurso ?? 'B10'));
        if ($nivelCursoRaw === '') {
            $nivelCursoRaw = 'B10';
        }

        $refs = array_values(array_filter(array_map(function ($x) {
            return trim((string) $x);
        }, preg_split('/\s*,\s*/', $nivelCursoRaw) ?: []), function ($x) {
            return $x !== '';
        }));
        if (count($refs) === 0) {
            $refs = ['B10'];
        }

        $startCell = $refs[0];

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

        // Celdas adicionales (en orden):
        // 1) Docente, 2) Materia, 3) Curso, 4) Gestión
        $cellUpdates = [];
        $valuesByOrder = [
            (string) ($payload['docente'] ?? ''),
            (string) ($payload['materia'] ?? ''),
            (string) ($payload['curso'] ?? ''),
            (string) ($payload['gestion'] ?? ''),
        ];

        $metaRefs = array_slice($refs, 1);
        for ($i = 0; $i < 4; $i++) {
            if (!isset($metaRefs[$i])) {
                continue;
            }
            $ref = trim((string) $metaRefs[$i]);
            if ($ref === '') {
                continue;
            }
            try {
                [$cLetters, $rNum] = Coordinate::coordinateFromString($ref);
                $addr = strtoupper($cLetters) . (int) $rNum;
                $cellUpdates[$addr] = $valuesByOrder[$i];
            } catch (\Throwable $e) {
                // ignorar referencias inválidas
            }
        }

        // Generación preservando 100% formato/hojas/imagenes:
        // se parchea el ZIP (xlsx/xlsm) y se actualiza SOLO la hoja INSC.
        try {
            return $this->generarParcheandoZip($fullPath, 'INSC.', $startCol, $startRow, $rows, $downloadName, $cellUpdates);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'No se pudo generar el Excel'], 500);
        }
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
        $zip->close();
        @unlink($tmp);

        return response()->download($tmpFile, $downloadName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
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

    private function parchearSheetXml(string $sheetXml, int $startCol, int $startRow, array $rows, array $cellUpdates = []): string
    {
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;
        $dom->loadXML($sheetXml);

        $sheetData = $dom->getElementsByTagName('sheetData')->item(0);
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

        $setInlineStrCell = function (string $addr, string $value) use (&$dom, $sheetData, &$rowIndex) {
            try {
                [$colLetters, $rowNum] = Coordinate::coordinateFromString($addr);
                $r = (int) $rowNum;
                if ($r <= 0) return;
                $addrNorm = strtoupper($colLetters) . $r;

                $rowEl = $rowIndex[$r] ?? null;
                if (!$rowEl) {
                    $rowEl = $dom->createElement('row');
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
                    $cell = $dom->createElement('c');
                    $cell->setAttribute('r', $addrNorm);
                    $rowEl->appendChild($cell);
                }

                // No sobrescribir fórmulas si existen
                foreach ($cell->childNodes as $child) {
                    if ($child instanceof \DOMElement && $child->tagName === 'f') {
                        return;
                    }
                }

                // Limpiar nodos de valor anteriores (v/is)
                $toRemove = [];
                foreach ($cell->childNodes as $child) {
                    if ($child instanceof \DOMElement && ($child->tagName === 'v' || $child->tagName === 'is')) {
                        $toRemove[] = $child;
                    }
                }
                foreach ($toRemove as $n) {
                    $cell->removeChild($n);
                }

                $cell->setAttribute('t', 'inlineStr');
                $is = $dom->createElement('is');
                $t = $dom->createElement('t');
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
                $rowEl = $dom->createElement('row');
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
                    $cell = $dom->createElement('c');
                    $cell->setAttribute('r', $addr);
                    $rowEl->appendChild($cell);
                    $cellIndex[$addr] = $cell;
                }

                // No sobrescribir fórmulas si existen
                $hasFormula = false;
                foreach ($cell->childNodes as $child) {
                    if ($child instanceof \DOMElement && $child->tagName === 'f') {
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
                    if ($child instanceof \DOMElement && ($child->tagName === 'v' || $child->tagName === 'is')) {
                        $toRemove[] = $child;
                    }
                }
                foreach ($toRemove as $n) {
                    $cell->removeChild($n);
                }

                // Escribir como inlineStr para no tocar sharedStrings
                $cell->setAttribute('t', 'inlineStr');
                $is = $dom->createElement('is');
                $t = $dom->createElement('t');
                $t->setAttribute('xml:space', 'preserve');
                $t->appendChild($dom->createTextNode((string) ($vals[$i] ?? '')));
                $is->appendChild($t);
                $cell->appendChild($is);
            }

            $r++;
        }

        return $dom->saveXML();
    }
}
