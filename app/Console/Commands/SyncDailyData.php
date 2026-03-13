<?php

namespace App\Console\Commands;

use App\Models\ApiIntegrationConfig;
use App\Services\IntegrationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncDailyData extends Command
{
    protected $signature = 'integration:sync-daily
                            {--config= : ID específico de configuración a sincronizar}
                            {--dry-run : Solo mostrar datos sin enviar}';

    protected $description = 'Envía automáticamente los cambios diarios de asistencia a los endpoints externos configurados.';

    public function handle(IntegrationService $service): int
    {
        $configId = $this->option('config');
        $dryRun = $this->option('dry-run');

        $configs = $configId
            ? ApiIntegrationConfig::where('id', $configId)->where('activo', true)->get()
            : ApiIntegrationConfig::where('activo', true)->where('auto_sync', true)->get();

        if ($configs->isEmpty()) {
            $this->info('No hay integraciones activas con sincronización automática.');
            return self::SUCCESS;
        }

        $this->info("Procesando {$configs->count()} integración(es)...");

        foreach ($configs as $config) {
            $this->line("─ [{$config->nombre}] ({$config->slug})");

            $changes = $service->getDailyChanges($config->instituciones_id);
            $total = $changes['total_registros'];

            if ($total === 0) {
                $this->info("  Sin cambios para hoy. Saltando.");
                continue;
            }

            $this->info("  {$total} registros para enviar.");

            if ($dryRun) {
                $this->table(
                    ['CI', 'Nombre', 'Fecha', 'Asistencia', 'Estrellas'],
                    array_map(fn($r) => [
                        $r->ci ?? '—',
                        mb_substr($r->nombre_completo ?? '', 0, 30),
                        $r->fecha ?? '—',
                        $r->asistencia ?? '—',
                        $r->estrellas ?? '—',
                    ], array_slice($changes['registros'], 0, 20))
                );
                continue;
            }

            $log = $service->sendToExternal($config, '/api/daily-changes', [
                'sistema'   => 'SISTEMALCCHIFAS',
                'timestamp' => now()->toIso8601String(),
                ...$changes,
            ], 'POST', 'artisan:sync-daily');

            if ($log->exitoso) {
                $this->info("  ✓ Enviado exitosamente (HTTP {$log->status_code})");
            } else {
                $this->error("  ✗ Error: {$log->error_mensaje}");
                Log::error("SyncDailyData: Fallo en [{$config->slug}]", [
                    'status' => $log->status_code,
                    'error'  => $log->error_mensaje,
                ]);
            }
        }

        $this->newLine();
        $this->info('Sincronización diaria completada.');

        return self::SUCCESS;
    }
}
