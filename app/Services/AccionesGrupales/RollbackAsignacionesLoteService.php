<?php

namespace App\Services\AccionesGrupales;

use App\Models\Calificaciones;
use App\Models\Infoestudiantesifas;
use Illuminate\Support\Facades\DB;

class RollbackAsignacionesLoteService
{
    private function isSuperAdmin($user): bool
    {
        return !empty($user) && empty($user->instituciones_id);
    }

    public function ejecutar(array $payload, $user): array
    {
        $uuid = trim((string) ($payload['uuid'] ?? ''));

        $lote = null;
        if ($uuid !== '') {
            $lote = DB::table('asignaciones_lotes')->where('uuid', $uuid)->first();
        } else {
            // fallback: último lote del usuario (no revertido)
            $q = DB::table('asignaciones_lotes')->whereNull('rolled_back_at')->orderByDesc('id');
            if (!$this->isSuperAdmin($user)) {
                $q->where('actor_id', $user?->id ?? null);
                $q->where('actor_type', $user ? get_class($user) : null);
                $q->where('instituciones_id', (int) ($user?->instituciones_id ?? 0));
            }
            $lote = $q->first();
        }

        if (!$lote) {
            return ['ok' => false, 'message' => 'No se encontró un lote para revertir'];
        }

        if (!empty($lote->rolled_back_at)) {
            return ['ok' => false, 'message' => 'Este lote ya fue revertido'];
        }

        $actorType = (string) ($lote->actor_type ?? '');
        $actorId = (int) ($lote->actor_id ?? 0);

        if (!$this->isSuperAdmin($user)) {
            if ($actorType !== ($user ? get_class($user) : '') || $actorId !== (int) ($user?->id ?? 0)) {
                return ['ok' => false, 'message' => 'Solo el usuario que ejecutó la acción puede revertir este lote'];
            }

            $userInstId = (int) ($user?->instituciones_id ?? 0);
            $loteInstId = (int) ($lote->instituciones_id ?? 0);
            if ($userInstId > 0 && $loteInstId > 0 && $userInstId !== $loteInstId) {
                return ['ok' => false, 'message' => 'Este lote no pertenece a su institución'];
            }
        }

        DB::beginTransaction();
        try {
            $items = DB::table('asignaciones_lote_items')
                ->where('lote_uuid', $lote->uuid)
                ->orderBy('id')
                ->get();

            $calIds = [];
            $infoIds = [];

            foreach ($items as $it) {
                if (!empty($it->infoestudiantesifas_id)) {
                    $infoIds[(int) $it->infoestudiantesifas_id] = true;
                }
                if (($it->action ?? '') === 'ASSIGN' && !empty($it->calificaciones_id)) {
                    $calIds[] = (int) $it->calificaciones_id;
                }
            }

            $deleted = 0;
            if (count($calIds) > 0) {
                $deleted = Calificaciones::query()->whereIn('id', $calIds)->delete();
            }

            // Restaurar Notas/Verificacion (solo de items NOTE)
            foreach ($items as $it) {
                if (($it->action ?? '') !== 'NOTE') continue;
                $infoId = (int) ($it->infoestudiantesifas_id ?? 0);
                if ($infoId <= 0) continue;

                Infoestudiantesifas::query()->where('id', $infoId)->update([
                    'Notas' => $it->prev_notas,
                    'Verificacion' => $it->prev_verificacion,
                ]);
            }

            // Recalcular contadores
            foreach (array_keys($infoIds) as $infoId) {
                $count = Calificaciones::query()->where('infoestudiantesifas_id', (int) $infoId)->count();
                Infoestudiantesifas::query()->where('id', (int) $infoId)->update(['CantidadMateriasAsignadas' => $count]);
            }

            DB::table('asignaciones_lotes')
                ->where('uuid', $lote->uuid)
                ->update([
                    'rolled_back_at' => now(),
                    'rolled_back_by_type' => $user ? get_class($user) : null,
                    'rolled_back_by_id' => $user?->id ?? null,
                ]);

            DB::commit();

            return [
                'ok' => true,
                'uuid' => $lote->uuid,
                'deleted_calificaciones' => $deleted,
                'affected_infos' => count($infoIds),
            ];
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
