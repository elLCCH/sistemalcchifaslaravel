-- ============================================================
-- 1) DISEÑOS DE CERTIFICADO (PDF plantilla + parámetros)
-- ============================================================
CREATE TABLE `diseniocertificadopdfs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `instituciones_id` INT UNSIGNED NULL,

  `Nombre` VARCHAR(150) NOT NULL,
  `ArchivoPdf` VARCHAR(255) NOT NULL,      -- ruta relativa en /public (ej: archivos/institucionX/eventos/certificados/disenos/xxx.pdf)
  `Parametros` LONGTEXT NULL,              -- JSON (posiciones por defecto/plantilla)
  `Activo` TINYINT(1) NOT NULL DEFAULT 1,
  `Observacion` TEXT NULL,

  `created_at` DATETIME NULL,
  `updated_at` DATETIME NULL,

  PRIMARY KEY (`id`),
  KEY `idx_diseniocert_institucion` (`instituciones_id`),
  KEY `idx_diseniocert_activo` (`Activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- 2) EVENTOS (incluye FK al diseño y config de certificado)
-- ============================================================
CREATE TABLE `eventos` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `instituciones_id` INT UNSIGNED NULL,

  `Anio` INT NULL,
  `NombreEvento` VARCHAR(255) NOT NULL,
  `Descripcion` TEXT NULL,
  `Lugar` VARCHAR(255) NULL,
  `FechaInicio` DATE NULL,
  `FechaFin` DATE NULL,

  `ModoInscripcion` VARCHAR(30) NOT NULL DEFAULT 'NORMAL',
  `PublicoWeb` TINYINT(1) NOT NULL DEFAULT 0,
  `Activo` TINYINT(1) NOT NULL DEFAULT 1,

  `Requisitos` TEXT NULL,
  `Parametros` LONGTEXT NULL,              -- JSON opcional
  `InputsEspecial` LONGTEXT NULL,          -- JSON schema dinámico

  `TienePago` TINYINT(1) NOT NULL DEFAULT 0,
  `Monto` DECIMAL(12,2) NULL,
  `Moneda` VARCHAR(10) NULL DEFAULT 'BS',

  `diseniocertificadopdfs_id` INT UNSIGNED NULL,
  `CertificadoConfig` LONGTEXT NULL,       -- JSON (posiciones/override por evento)

  `Observacion` TEXT NULL,

  `created_at` DATETIME NULL,
  `updated_at` DATETIME NULL,

  PRIMARY KEY (`id`),

  KEY `idx_eventos_institucion` (`instituciones_id`),
  KEY `idx_eventos_anio` (`Anio`),
  KEY `idx_eventos_activo` (`Activo`),
  KEY `idx_eventos_publicoweb` (`PublicoWeb`),
  KEY `idx_eventos_diseniocert` (`diseniocertificadopdfs_id`),

  CONSTRAINT `fk_eventos_diseniocert` FOREIGN KEY (`diseniocertificadopdfs_id`)
    REFERENCES `diseniocertificadopdfs` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- 3) INSCRIPCIONES + PAGOS + CERTIFICADO EN UNA SOLA TABLA
-- ============================================================
CREATE TABLE `estudianteseventos` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `instituciones_id` INT UNSIGNED NULL,
  `eventos_id` INT UNSIGNED NOT NULL,

  `estudiantesifas_id` INT UNSIGNED NULL,  -- opcional (si se enlaza con IFAS)

  `Ap_Paterno` VARCHAR(120) NOT NULL,
  `Ap_Materno` VARCHAR(120) NOT NULL,
  `Nombres` VARCHAR(180) NOT NULL,
  `Carnet` VARCHAR(40) NOT NULL,
  `Celular` VARCHAR(30) NULL,
  `Correo` VARCHAR(120) NULL,

  `DatosEspeciales` LONGTEXT NULL,         -- JSON (respuestas a InputsEspecial)

  `TienePago` TINYINT(1) NOT NULL DEFAULT 0,
  `Monto` DECIMAL(12,2) NULL,
  `MetodoPago` VARCHAR(100) NULL,
  `FechaPago` DATE NULL,
  `ComprobantePago` VARCHAR(255) NULL,     -- ruta/URL
  `EstadoPago` VARCHAR(20) NOT NULL DEFAULT 'NO_APLICA',

  `EstadoInscripcion` VARCHAR(20) NOT NULL DEFAULT 'PENDIENTE',
  `Observacion` TEXT NULL,

  `CertificadoPdf` VARCHAR(255) NULL,      -- ruta relativa en /public del certificado generado
  `CertificadoGeneradoAt` DATETIME NULL,

  `created_at` DATETIME NULL,
  `updated_at` DATETIME NULL,

  PRIMARY KEY (`id`),

  KEY `idx_est_ev_institucion` (`instituciones_id`),
  KEY `idx_est_ev_evento` (`eventos_id`),
  KEY `idx_est_ev_carnet` (`Carnet`),
  KEY `idx_est_ev_estado_insc` (`EstadoInscripcion`),
  KEY `idx_est_ev_estado_pago` (`EstadoPago`),

  UNIQUE KEY `uk_est_ev_evento_carnet_inst` (`eventos_id`, `Carnet`, `instituciones_id`),

  CONSTRAINT `fk_est_ev_evento` FOREIGN KEY (`eventos_id`)
    REFERENCES `eventos` (`id`)
    ON UPDATE CASCADE
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE `bibliotecaarchivoslcch` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `institucion_id` INT NOT NULL,

  `categoria` VARCHAR(80) NULL,
  `nombre_documento` VARCHAR(150) NOT NULL,
  `fecha` DATE NULL,
  `archivo` VARCHAR(300) NOT NULL,

  `estado` VARCHAR(15) NULL,
  `visibilidad` VARCHAR(15) NULL,

  `publicado_por` VARCHAR(120) NULL,
  `dirigido` VARCHAR(120) NULL,
  `descripcion` TEXT NULL,

  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  INDEX `idx_biblio_inst_cat` (`institucion_id`, `categoria`),
  INDEX `idx_biblio_inst_vis` (`institucion_id`, `visibilidad`),
  CONSTRAINT `fk_biblio_inst`
    FOREIGN KEY (`institucion_id`) REFERENCES `instituciones`(`id`)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;