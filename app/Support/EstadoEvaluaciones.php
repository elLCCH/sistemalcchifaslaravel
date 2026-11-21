<?php

namespace App\Support;

class EstadoEvaluaciones
{
    public static function cantidad($valor): ?int
    {
        $texto = strtoupper(trim((string) ($valor ?? '')));
        if (preg_match('/\b(CERO|UN|UNO|UNA|DOS|TRES|CUATRO)\b/u', $texto, $coincidencia)) {
            return ['CERO' => 0, 'UN' => 1, 'UNO' => 1, 'UNA' => 1, 'DOS' => 2, 'TRES' => 3, 'CUATRO' => 4][$coincidencia[1]];
        }
        if (preg_match('/\d+/', $texto, $coincidencia)) {
            $cantidad = (int) $coincidencia[0];
            return $cantidad <= 4 ? $cantidad : null;
        }
        return null;
    }

    public static function habilitadas($estado, int $cantidad): array
    {
        $s = strtoupper(trim((string) ($estado ?? '')));
        if ($s === '' || str_contains($s, 'DESHABIL') || str_contains($s, 'SEGUNDA INSTANCIA') || str_contains($s, 'EVALUACIÓN FINAL') || str_contains($s, 'EVALUACION FINAL')) return [];
        if (str_contains($s, 'HABILIT') || str_contains($s, 'TODAS') || str_contains($s, 'TODO')) {
            return range(1, max(1, min(4, $cantidad)));
        }
        $out = [];
        foreach (['PRIMERA', 'SEGUNDA', 'TERCERA', 'CUARTA'] as $indice => $nombre) {
            $numero = $indice + 1;
            if (str_contains($s, $nombre) || preg_match("/\\b$numero\\b/", $s)) $out[] = $numero;
        }
        return $out;
    }
}
