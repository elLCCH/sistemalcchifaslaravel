-- ============================================================
-- SISTEMA DE ASISTENCIAS POR QR + GPS (MULTI-INSTITUCIÓN)
-- Reglas:
-- - 1 sesión por día por aula/materia
-- - PRESENTE dentro de tiempo_espera_minutos desde hora_ingreso
-- - ATRASO hasta 30 minutos desde hora_ingreso (minutos_falta)
-- - Después de 30 minutos: el QR “vence” y ya no registra; al cierre
--   automático se crea FALTA/PERMISO a quienes no registraron.
-- - PERMISO lo registra secretaría en permisos_asistencia
-- ============================================================

-- 1) Sesiones de asistencia (1 por día por aula)
CREATE TABLE IF NOT EXISTS asistencias_sesiones (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,

  instituciones_id INT NOT NULL COMMENT 'Institución propietaria (filtro y seguridad)',
  aulas_virtuales_id BIGINT UNSIGNED NOT NULL COMMENT 'Aula/materia donde se pasa asistencia',
  planteldocentes_id INT NOT NULL COMMENT 'Docente que creó la sesión',

  fecha DATE NOT NULL COMMENT 'Fecha de la sesión (solo 1 por día por aula)',
  hora_ingreso DATETIME NOT NULL COMMENT 'Fecha/hora de inicio del control (base para presente/atraso/falta)',

  tiempo_espera_minutos INT NOT NULL DEFAULT 10 COMMENT 'Ventana (min) para marcar PRESENTE desde hora_ingreso',
  minutos_falta INT NOT NULL DEFAULT 30 COMMENT 'Minutos fijos para considerar FALTA (por defecto 30)',

  gps_requerido TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=exige GPS',
  radio_metros INT NOT NULL DEFAULT 150 COMMENT 'Radio permitido desde institucion.UbicacionGps',

  estado VARCHAR(20) NULL COMMENT 'ABIERTA/CERRADA/CANCELADA',
  visibilidad VARCHAR(15) NULL COMMENT 'VISIBLE/OCULTO',

  created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_asist_ses_inst
    FOREIGN KEY (instituciones_id) REFERENCES instituciones(id)
    ON UPDATE CASCADE ON DELETE CASCADE,

  CONSTRAINT fk_asist_ses_aula
    FOREIGN KEY (aulas_virtuales_id) REFERENCES aulas_virtuales(id)
    ON UPDATE CASCADE ON DELETE CASCADE,

  CONSTRAINT fk_asist_ses_docente
    FOREIGN KEY (planteldocentes_id) REFERENCES planteldocentes(id)
    ON UPDATE CASCADE ON DELETE CASCADE,

  UNIQUE KEY uq_asist_sesion_diaria (aulas_virtuales_id, fecha),

  INDEX idx_asist_ses_inst (instituciones_id),
  INDEX idx_asist_ses_aula (aulas_virtuales_id),
  INDEX idx_asist_ses_docente (planteldocentes_id),
  INDEX idx_asist_ses_fecha (fecha),
  INDEX idx_asist_ses_hora (hora_ingreso)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 2) Tokens QR rotativos (expiran rápido).
CREATE TABLE IF NOT EXISTS asistencias_qr_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,

  asistencias_sesiones_id BIGINT UNSIGNED NOT NULL COMMENT 'Sesión a la que pertenece el QR',
  token VARCHAR(128) NOT NULL COMMENT 'Token aleatorio para el QR',

  expires_at DATETIME NOT NULL COMMENT 'Expiración del token (ej: ahora + 5 segundos)',
  created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,

  CONSTRAINT fk_asist_qr_sesion
    FOREIGN KEY (asistencias_sesiones_id) REFERENCES asistencias_sesiones(id)
    ON UPDATE CASCADE ON DELETE CASCADE,

  UNIQUE KEY uq_asist_qr_token (token),
  INDEX idx_asist_qr_sesion (asistencias_sesiones_id),
  INDEX idx_asist_qr_exp (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 3) Permisos (secretaría)
CREATE TABLE IF NOT EXISTS permisos_asistencia (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,

  instituciones_id INT NOT NULL COMMENT 'Institución',
  infoestudiantesifas_id INT NOT NULL COMMENT 'Estudiante inscrito',

  fecha_inicio DATE NOT NULL COMMENT 'Inicio del permiso',
  fecha_fin DATE NOT NULL COMMENT 'Fin del permiso (inclusive)',

  aulas_virtuales_id BIGINT UNSIGNED NULL COMMENT 'NULL=permiso general; con valor=permiso para un aula específica',

  motivo VARCHAR(255) NULL COMMENT 'Motivo del permiso',
  registrado_por VARCHAR(80) NULL COMMENT 'Nombre o referencia del administrativo/secretaría',

  estado VARCHAR(15) NULL COMMENT 'ACTIVO/INACTIVO',
  visibilidad VARCHAR(15) NULL COMMENT 'VISIBLE/OCULTO',

  created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_perm_asist_inst
    FOREIGN KEY (instituciones_id) REFERENCES instituciones(id)
    ON UPDATE CASCADE ON DELETE CASCADE,

  CONSTRAINT fk_perm_asist_est
    FOREIGN KEY (infoestudiantesifas_id) REFERENCES infoestudiantesifas(id)
    ON UPDATE CASCADE ON DELETE CASCADE,

  CONSTRAINT fk_perm_asist_aula
    FOREIGN KEY (aulas_virtuales_id) REFERENCES aulas_virtuales(id)
    ON UPDATE CASCADE ON DELETE SET NULL,

  INDEX idx_perm_inst_est (instituciones_id, infoestudiantesifas_id),
  INDEX idx_perm_rango (fecha_inicio, fecha_fin),
  INDEX idx_perm_aula (aulas_virtuales_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 4) Registro final por estudiante por sesión
CREATE TABLE IF NOT EXISTS asistencias_registros (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,

  asistencias_sesiones_id BIGINT UNSIGNED NOT NULL COMMENT 'Sesión',
  infoestudiantesifas_id INT NOT NULL COMMENT 'Estudiante inscrito',

  estado_asistencia VARCHAR(20) NOT NULL COMMENT 'PRESENTE/ATRASO/FALTA/PERMISO',
  metodo VARCHAR(10) NOT NULL DEFAULT 'QR' COMMENT 'QR/MANUAL/SISTEMA',
  fecha_registro DATETIME NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Cuándo quedó registrado',

  asistencias_qr_tokens_id BIGINT UNSIGNED NULL COMMENT 'Token QR usado (si aplica)',

  gps_lat DECIMAL(10,7) NULL,
  gps_lng DECIMAL(10,7) NULL,
  gps_precision_m DECIMAL(8,2) NULL,
  gps_distancia_m DECIMAL(10,2) NULL,
  gps_valido TINYINT(1) NOT NULL DEFAULT 0,

  observacion VARCHAR(255) NULL COMMENT 'Observación (si aplica)',

  estado VARCHAR(15) NULL COMMENT 'ACTIVO/INACTIVO',
  visibilidad VARCHAR(15) NULL COMMENT 'VISIBLE/OCULTO',

  created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_asist_reg_sesion
    FOREIGN KEY (asistencias_sesiones_id) REFERENCES asistencias_sesiones(id)
    ON UPDATE CASCADE ON DELETE CASCADE,

  CONSTRAINT fk_asist_reg_est
    FOREIGN KEY (infoestudiantesifas_id) REFERENCES infoestudiantesifas(id)
    ON UPDATE CASCADE ON DELETE CASCADE,

  CONSTRAINT fk_asist_reg_token
    FOREIGN KEY (asistencias_qr_tokens_id) REFERENCES asistencias_qr_tokens(id)
    ON UPDATE CASCADE ON DELETE SET NULL,

  UNIQUE KEY uq_asist_reg_unica (asistencias_sesiones_id, infoestudiantesifas_id),

  INDEX idx_asist_reg_est (infoestudiantesifas_id),
  INDEX idx_asist_reg_estado (estado_asistencia),
  INDEX idx_asist_reg_metodo (metodo),
  INDEX idx_asist_reg_fecha (fecha_registro)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 5) Auditoría (opcional)
CREATE TABLE IF NOT EXISTS asistencias_auditoria (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,

  asistencias_registros_id BIGINT UNSIGNED NOT NULL,
  accion VARCHAR(30) NOT NULL COMMENT 'CREAR/EDITAR/ANULAR',

  antes VARCHAR(255) NULL,
  despues VARCHAR(255) NULL,

  actor_tipo VARCHAR(20) NOT NULL COMMENT 'DOCENTE/ADMIN/SISTEMA',
  actor_id BIGINT UNSIGNED NOT NULL,
  fecha DATETIME NULL DEFAULT CURRENT_TIMESTAMP,

  CONSTRAINT fk_asist_aud_reg
    FOREIGN KEY (asistencias_registros_id) REFERENCES asistencias_registros(id)
    ON UPDATE CASCADE ON DELETE CASCADE,

  INDEX idx_asist_aud_reg (asistencias_registros_id),
  INDEX idx_asist_aud_actor (actor_tipo, actor_id),
  INDEX idx_asist_aud_fecha (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
