CREATE TABLE IF NOT EXISTS `asignaciones_lotes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,

  `instituciones_id` INT UNSIGNED NULL,
  `anio_id` INT UNSIGNED NULL,
  `resolucion` VARCHAR(100) NULL,
  `nivel` VARCHAR(100) NULL,
  `cursos_json` JSON NULL,

  `actor_type` VARCHAR(255) NULL,
  `actor_id` BIGINT UNSIGNED NULL,

  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  `rolled_back_at` TIMESTAMP NULL DEFAULT NULL,
  `rolled_back_by_type` VARCHAR(255) NULL,
  `rolled_back_by_id` BIGINT UNSIGNED NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `asignaciones_lotes_uuid_unique` (`uuid`),
  KEY `asignaciones_lotes_instituciones_id_index` (`instituciones_id`),
  KEY `asignaciones_lotes_anio_id_index` (`anio_id`),
  KEY `asignaciones_lotes_rolled_back_at_index` (`rolled_back_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `asignaciones_lote_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

  `lote_uuid` CHAR(36) NOT NULL,
  `infoestudiantesifas_id` INT UNSIGNED NULL,

  `calificaciones_id` INT UNSIGNED NULL,
  `materias_id` INT UNSIGNED NULL,

  `action` VARCHAR(20) NOT NULL DEFAULT 'ASSIGN',

  `prev_notas` TEXT NULL,
  `prev_verificacion` VARCHAR(100) NULL,
  `new_notas` TEXT NULL,
  `new_verificacion` VARCHAR(100) NULL,

  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `asignaciones_lote_items_lote_uuid_index` (`lote_uuid`),
  KEY `asignaciones_lote_items_infoestudiantesifas_id_index` (`infoestudiantesifas_id`),
  KEY `asignaciones_lote_items_calificaciones_id_index` (`calificaciones_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;