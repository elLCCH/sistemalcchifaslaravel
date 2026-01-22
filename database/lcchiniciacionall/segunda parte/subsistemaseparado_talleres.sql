-- ============================================================
-- SUBSISTEMA SEPARADO: TALLERES
-- Tablas: talleristas, pagostalleres
-- Nota: planteldocentes ya existe.
-- ============================================================

DROP TABLE IF EXISTS `pagostalleres`;
DROP TABLE IF EXISTS `talleristas`;

CREATE TABLE `talleristas` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  instituciones_id INT NOT NULL,
  Foto VARCHAR(250) NULL,
  Ap_Paterno VARCHAR(50) NULL,
  Ap_Materno VARCHAR(50) NULL,
  Nombre VARCHAR(60) NULL,
  Carnet VARCHAR(50) NULL,
  Celular INT NULL,
  Nombre_Padre VARCHAR(80) NULL,
  Celular_Padre INT NULL,
  Nombre_Madre VARCHAR(80) NULL,
  Celular_Madre INT NULL,
  created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_talleristas_instituciones (instituciones_id),
  INDEX idx_talleristas_carnet (Carnet),
  FOREIGN KEY (instituciones_id) REFERENCES instituciones(id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pagostalleres` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  instituciones_id INT NOT NULL,
  talleristas_id INT NOT NULL,
  planteldocentes_id INT NOT NULL,
  Especialidad VARCHAR(80) NULL,
  FechaPago DATE NULL,
  FechaHasta DATE NULL,
  MontoPagado INT NULL,
  DetallePago VARCHAR(150) NULL,
  Observacion VARCHAR(150) NULL,
  ComprobantePago VARCHAR(250) NULL,
  Turno VARCHAR(20) NULL,
  Horario VARCHAR(50) NULL,
  created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_pagostalleres_instituciones (instituciones_id),
  INDEX idx_pagostalleres_tallerista (talleristas_id),
  INDEX idx_pagostalleres_docente (planteldocentes_id),
  INDEX idx_pagostalleres_fechas (FechaPago, FechaHasta),
  FOREIGN KEY (instituciones_id) REFERENCES instituciones(id) ON UPDATE CASCADE ON DELETE RESTRICT,
  FOREIGN KEY (talleristas_id) REFERENCES talleristas(id) ON UPDATE CASCADE ON DELETE RESTRICT,
  FOREIGN KEY (planteldocentes_id) REFERENCES planteldocentes(id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
