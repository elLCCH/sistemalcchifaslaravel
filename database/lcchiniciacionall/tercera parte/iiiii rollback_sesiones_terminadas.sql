CREATE TABLE IF NOT EXISTS terminar_clase_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    planteldocentes_id INT NOT NULL,
    instituciones_id INT NOT NULL,
    tipo_asignacion VARCHAR(30) NOT NULL,
    evaluacion INT NOT NULL DEFAULT 1,
    fecha DATE NOT NULL COMMENT 'Fecha de la clase terminada',
    cursos_json TEXT NULL COMMENT 'JSON de cursos seleccionados',
    sesiones_creadas_ids TEXT NOT NULL COMMENT 'JSON array con IDs de sesiones_avance_estudiantil creadas',
    cantidad_creadas INT NOT NULL DEFAULT 0,
    deshecho_at TIMESTAMP NULL DEFAULT NULL COMMENT 'NULL = vigente, con valor = ya deshecho',
    deshecho_por INT NULL COMMENT 'ID del docente que deshizo',
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tcl_docente_fecha (planteldocentes_id, fecha),
    INDEX idx_tcl_institucion (instituciones_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;