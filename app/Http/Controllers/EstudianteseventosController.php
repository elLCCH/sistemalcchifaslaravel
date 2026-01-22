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
            $data['FechaPago'] = '';
            $data['ComprobantePago'] = '';
            $data['EstadoPago'] = 'NO_APLICA';
        } else {
            if (!isset($data['EstadoPago']) || $data['EstadoPago'] === '') $data['EstadoPago'] = 'PENDIENTE';
            if (array_key_exists('Monto', $data) && $data['Monto'] === '') $data['Monto'] = null;
        }

        // Evitar duplicados simples por evento + carnet (si viene)
        $carnet = trim((string) ($data['Carnet'] ?? ''));
        $eventoId = (int) ($data['eventos_id'] ?? 0);
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

        if (array_key_exists('TienePago', $data) && ($data['TienePago'] == 0 || $data['TienePago'] === '0' || $data['TienePago'] === false)) {
            $data['Monto'] = null;
            $data['MetodoPago'] = '';
            $data['FechaPago'] = '';
            $data['ComprobantePago'] = '';
            $data['EstadoPago'] = 'NO_APLICA';
        } else {
            if (array_key_exists('Monto', $data) && $data['Monto'] === '') $data['Monto'] = null;
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
