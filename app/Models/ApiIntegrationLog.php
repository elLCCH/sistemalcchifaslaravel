<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiIntegrationLog extends Model
{
    protected $table = 'api_integration_logs';

    protected $fillable = [
        'config_id',
        'tipo',
        'endpoint',
        'metodo',
        'payload_enviado',
        'status_code',
        'respuesta',
        'exitoso',
        'error_mensaje',
        'iniciado_por',
        'registros_enviados',
    ];

    protected $casts = [
        'payload_enviado' => 'array',
        'exitoso' => 'boolean',
    ];

    public function config()
    {
        return $this->belongsTo(ApiIntegrationConfig::class, 'config_id');
    }
}
