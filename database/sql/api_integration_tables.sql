-- ╔══════════════════════════════════════════════════════════════╗
-- ║       MÓDULO DE INTEGRACIÓN API — Tablas MySQL             ║
-- ║       Ejecutar en la base de datos del sistema LCCHIFAS    ║
-- ╚══════════════════════════════════════════════════════════════╝

-- 1) Configuración de endpoints externos (Ministerio, etc.)
CREATE TABLE IF NOT EXISTS `api_integration_configs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `instituciones_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `nombre` VARCHAR(100) NOT NULL COMMENT 'Ej: Ministerio de Educación',
    `slug` VARCHAR(60) NOT NULL COMMENT 'Ej: ministerio-educacion',
    `base_url` VARCHAR(500) NULL DEFAULT NULL COMMENT 'URL del endpoint externo',
    `auth_type` VARCHAR(30) NOT NULL DEFAULT 'bearer_token' COMMENT 'bearer_token | api_key | oauth2 | none',
    `auth_credentials` TEXT NULL DEFAULT NULL COMMENT 'JSON cifrado con Laravel encrypt: {token, client_id, client_secret, ...}',
    `headers` JSON NULL DEFAULT NULL COMMENT 'Headers HTTP adicionales',
    `activo` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Activar/desactivar integración',
    `auto_sync` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Sincronización automática diaria',
    `webhook_secret` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Secreto para validar webhooks entrantes',
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `api_integration_configs_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Log de intentos de integración (envíos y recepciones)
CREATE TABLE IF NOT EXISTS `api_integration_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `config_id` BIGINT UNSIGNED NOT NULL,
    `tipo` VARCHAR(30) NOT NULL COMMENT 'ENVIO | RECEPCION | WEBHOOK',
    `endpoint` VARCHAR(500) NOT NULL,
    `metodo` VARCHAR(10) NOT NULL COMMENT 'GET | POST | PUT | DELETE',
    `payload_enviado` JSON NULL DEFAULT NULL,
    `status_code` INT NULL DEFAULT NULL,
    `respuesta` TEXT NULL DEFAULT NULL,
    `exitoso` TINYINT(1) NOT NULL DEFAULT 0,
    `error_mensaje` VARCHAR(1000) NULL DEFAULT NULL,
    `iniciado_por` VARCHAR(100) NULL DEFAULT NULL COMMENT 'artisan:sync-daily, manual:admin@1, etc.',
    `registros_enviados` INT NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `api_integration_logs_config_created` (`config_id`, `created_at`),
    KEY `api_integration_logs_tipo_index` (`tipo`),
    CONSTRAINT `api_integration_logs_config_fk` FOREIGN KEY (`config_id`) REFERENCES `api_integration_configs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
