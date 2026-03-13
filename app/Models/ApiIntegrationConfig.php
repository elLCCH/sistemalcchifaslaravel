<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiIntegrationConfig extends Model
{
    protected $table = 'api_integration_configs';

    protected $fillable = [
        'instituciones_id',
        'nombre',
        'slug',
        'base_url',
        'auth_type',
        'auth_credentials',
        'headers',
        'activo',
        'auto_sync',
        'webhook_secret',
    ];

    protected $casts = [
        'headers' => 'array',
        'activo' => 'boolean',
        'auto_sync' => 'boolean',
    ];

    // auth_credentials se maneja cifrado manualmente, NO se expone nunca
    protected $hidden = ['auth_credentials', 'webhook_secret'];

    public function logs()
    {
        return $this->hasMany(ApiIntegrationLog::class, 'config_id');
    }

    public function institucion()
    {
        return $this->belongsTo(Instituciones::class, 'instituciones_id');
    }

    /** Obtener credenciales descifradas */
    public function getCredentials(): array
    {
        if (!$this->auth_credentials) return [];
        $decrypted = decrypt($this->auth_credentials);
        return is_array($decrypted) ? $decrypted : (json_decode($decrypted, true) ?? []);
    }

    /** Guardar credenciales cifradas */
    public function setCredentials(array $credentials): void
    {
        $this->auth_credentials = encrypt($credentials);
        $this->save();
    }
}
