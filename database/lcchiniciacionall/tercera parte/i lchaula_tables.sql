-- ============================================================
-- SISTEMA TIPO CLASSROOM (MULTI-INSTITUCIÓN) PARA SISTEMALCCHIFAS
-- Nombre: LChaula (sistemaLChaula)
-- Basado en: instituciones -> carreras -> plandeestudios -> materias
-- Estudiante inscrito: infoestudiantesifas
-- Docentes: planteldocentes
-- ============================================================

-- 1) Aulas virtuales (un "classroom" por materia/paralelo dentro de una institución)
CREATE TABLE IF NOT EXISTS aulas_virtuales (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,

  instituciones_id INT NOT NULL COMMENT 'Institución propietaria del aula (aislamiento multi-institución)',
  materias_id INT NOT NULL COMMENT 'Materia/paralelo base del aula virtual',

  nombre VARCHAR(150) NULL COMMENT 'Nombre visible del aula (si es NULL, se puede construir desde la materia)',
  descripcion TEXT NULL COMMENT 'Descripción/Presentación del aula',

  estado VARCHAR(15) NULL COMMENT 'ACTIVO/INACTIVO (si INACTIVO, bloquea acciones como publicar o recibir entregas)',
  visibilidad VARCHAR(15) NULL COMMENT 'VISIBLE/OCULTO (si OCULTO, no se muestra en listados)',

  created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_aulas_virtuales_instituciones
    FOREIGN KEY (instituciones_id) REFERENCES instituciones(id)
    ON UPDATE CASCADE ON DELETE CASCADE,

  CONSTRAINT fk_aulas_virtuales_materias
    FOREIGN KEY (materias_id) REFERENCES materias(id)
    ON UPDATE CASCADE ON DELETE CASCADE,

  UNIQUE KEY uq_aula_institucion_materia (instituciones_id, materias_id),
  INDEX idx_aulas_institucion (instituciones_id),
  INDEX idx_aulas_materia (materias_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 2) Participantes del aula (docentes/estudiantes/admins)
CREATE TABLE IF NOT EXISTS aulas_participantes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,

  aulas_virtuales_id BIGINT UNSIGNED NOT NULL COMMENT 'Aula virtual',
  tipo VARCHAR(20) NOT NULL COMMENT 'ESTUDIANTE/DOCENTE/ADMIN',

  -- según tipo:
  infoestudiantesifas_id INT NULL COMMENT 'Si tipo=ESTUDIANTE, aquí va el inscrito (infoestudiantesifas.id)',
  planteldocentes_id INT NULL COMMENT 'Si tipo=DOCENTE, aquí va el docente (planteldocentes.id)',
  planteladministrativos_id INT NULL COMMENT 'Si tipo=ADMIN, aquí va el administrativo (planteladministrativos.id)',

  -- Permisos dentro del aula
  rol VARCHAR(30) NULL COMMENT 'Opcional: TITULAR, AUXILIAR, COLABORADOR, LECTOR, etc.',
  puede_publicar TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=puede crear publicaciones (anuncios/material/tareas)',
  puede_calificar TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=puede calificar tareas',
  puede_administrar TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=puede administrar el aula (configurar, gestionar participantes, etc.)',

  estado VARCHAR(15) NULL COMMENT 'ACTIVO/INACTIVO',
  visibilidad VARCHAR(15) NULL COMMENT 'VISIBLE/OCULTO',

  created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_aulas_participantes_aula
    FOREIGN KEY (aulas_virtuales_id) REFERENCES aulas_virtuales(id)
    ON UPDATE CASCADE ON DELETE CASCADE,

  CONSTRAINT fk_aulas_participantes_infoest
    FOREIGN KEY (infoestudiantesifas_id) REFERENCES infoestudiantesifas(id)
    ON UPDATE CASCADE ON DELETE CASCADE,

  CONSTRAINT fk_aulas_participantes_docente
    FOREIGN KEY (planteldocentes_id) REFERENCES planteldocentes(id)
    ON UPDATE CASCADE ON DELETE CASCADE,

  CONSTRAINT fk_aulas_participantes_admin
    FOREIGN KEY (planteladministrativos_id) REFERENCES planteladministrativos(id)
    ON UPDATE CASCADE ON DELETE CASCADE,

  INDEX idx_part_aula (aulas_virtuales_id),
  INDEX idx_part_tipo (tipo),
  INDEX idx_part_est (infoestudiantesifas_id),
  INDEX idx_part_doc (planteldocentes_id),
  INDEX idx_part_admin (planteladministrativos_id),

  -- Evita duplicados típicos (nota: MySQL permite múltiples NULL en UNIQUE)
  UNIQUE KEY uq_part_est (aulas_virtuales_id, tipo, infoestudiantesifas_id),
  UNIQUE KEY uq_part_doc (aulas_virtuales_id, tipo, planteldocentes_id),
  UNIQUE KEY uq_part_admin (aulas_virtuales_id, tipo, planteladministrativos_id)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 3) Publicaciones del aula (muro): anuncio/material/tarea
CREATE TABLE IF NOT EXISTS publicaciones_aula (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,

  aulas_virtuales_id BIGINT UNSIGNED NOT NULL COMMENT 'Aula a la que pertenece la publicación',
  tipo VARCHAR(20) NOT NULL COMMENT 'ANUNCIO/MATERIAL/TAREA',

  titulo VARCHAR(255) NOT NULL COMMENT 'Título',
  descripcion LONGTEXT NULL COMMENT 'Contenido (puede ser markdown)',

  creado_por_tipo VARCHAR(30) NOT NULL COMMENT 'PLANTELDOCENTE/ADMIN/OTRO (según se habilite)',
  creado_por_id BIGINT UNSIGNED NOT NULL COMMENT 'ID del creador (según el tipo)',

  fecha_publicacion DATETIME NULL COMMENT 'Si NULL se asume inmediata',
  estado VARCHAR(20) NULL COMMENT 'BORRADOR/PUBLICADO/CERRADO',
  visibilidad VARCHAR(15) NULL COMMENT 'VISIBLE/OCULTO',

  created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_publicaciones_aula
    FOREIGN KEY (aulas_virtuales_id) REFERENCES aulas_virtuales(id)
    ON UPDATE CASCADE ON DELETE CASCADE,

  INDEX idx_pub_aula (aulas_virtuales_id),
  INDEX idx_pub_tipo (tipo),
  INDEX idx_pub_estado (estado),
  INDEX idx_pub_vis (visibilidad),
  INDEX idx_pub_fecha (fecha_publicacion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 4) Detalle de tareas (solo cuando publicaciones_aula.tipo='TAREA')
CREATE TABLE IF NOT EXISTS tareas (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,

  publicaciones_aula_id BIGINT UNSIGNED NOT NULL COMMENT 'Publicación padre (de tipo TAREA)',

  -- Ventana de entrega
  fecha_inicio DATETIME NULL COMMENT 'Desde cuándo se aceptan entregas (NULL=desde publicación)',
  fecha_entrega DATETIME NULL COMMENT 'Fecha/hora límite (deadline)',
  fecha_cierre DATETIME NULL COMMENT 'Cierre duro (si pasa, no se acepta nada). Si NULL, se usa fecha_entrega',

  permitir_entrega_tardia TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=acepta entregas tardías (después de fecha_entrega)',
  limite_tardia_horas INT NULL COMMENT 'Si se permite tardía, máximo horas extra aceptadas (NULL=sin límite)',
  bloquear_recepcion VARCHAR(15) NULL COMMENT 'SI/NO (o usar estado) para bloquear recepción manualmente',

  -- Calificación
  puntaje_maximo INT NOT NULL DEFAULT 100 COMMENT 'Puntaje máximo de la tarea',
  tipo_calificacion VARCHAR(20) NULL COMMENT 'PUNTOS/PORCENTAJE (opcional)',
  estado VARCHAR(15) NULL COMMENT 'ACTIVO/INACTIVO',
  visibilidad VARCHAR(15) NULL COMMENT 'VISIBLE/OCULTO',

  created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_tareas_publicacion
    FOREIGN KEY (publicaciones_aula_id) REFERENCES publicaciones_aula(id)
    ON UPDATE CASCADE ON DELETE CASCADE,

  UNIQUE KEY uq_tarea_publicacion (publicaciones_aula_id),
  INDEX idx_tarea_estado (estado),
  INDEX idx_tarea_fechas (fecha_entrega, fecha_cierre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 5) Entregas de estudiantes por tarea (recepción de trabajos)
CREATE TABLE IF NOT EXISTS entregas_tareas (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,

  tareas_id BIGINT UNSIGNED NOT NULL COMMENT 'Tarea',
  infoestudiantesifas_id INT NOT NULL COMMENT 'Estudiante inscrito que entrega',

  estado VARCHAR(20) NULL COMMENT 'PENDIENTE/ENTREGADO/ATRASADO/DEVUELTO/CALIFICADO',
  fecha_entrega DATETIME NULL COMMENT 'Cuándo entregó (submit)',
  comentario_estudiante TEXT NULL COMMENT 'Mensaje del estudiante al entregar',

  numero_reentrega INT NOT NULL DEFAULT 0 COMMENT '0=primera entrega, 1=segunda, etc.',

  created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_entregas_tarea
    FOREIGN KEY (tareas_id) REFERENCES tareas(id)
    ON UPDATE CASCADE ON DELETE CASCADE,

  CONSTRAINT fk_entregas_estudiante
    FOREIGN KEY (infoestudiantesifas_id) REFERENCES infoestudiantesifas(id)
    ON UPDATE CASCADE ON DELETE CASCADE,

  UNIQUE KEY uq_entrega_unica (tareas_id, infoestudiantesifas_id),
  INDEX idx_entrega_est (infoestudiantesifas_id),
  INDEX idx_entrega_estado (estado),
  INDEX idx_entrega_fecha (fecha_entrega)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 6) Calificación de la entrega (separada para auditoría/recalificación)
CREATE TABLE IF NOT EXISTS calificaciones_tareas (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,

  entregas_tareas_id BIGINT UNSIGNED NOT NULL COMMENT 'Entrega calificada (1:1)',
  planteldocentes_id INT NOT NULL COMMENT 'Docente que calificó',

  puntaje_obtenido INT NULL COMMENT 'Nota/score obtenido',
  comentario_docente TEXT NULL COMMENT 'Feedback del docente',
  fecha_calificacion DATETIME NULL COMMENT 'Cuándo calificó',

  estado VARCHAR(15) NULL COMMENT 'ACTIVO/INACTIVO',
  visibilidad VARCHAR(15) NULL COMMENT 'VISIBLE/OCULTO',

  created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_calif_entrega
    FOREIGN KEY (entregas_tareas_id) REFERENCES entregas_tareas(id)
    ON UPDATE CASCADE ON DELETE CASCADE,

  CONSTRAINT fk_calif_docente
    FOREIGN KEY (planteldocentes_id) REFERENCES planteldocentes(id)
    ON UPDATE CASCADE ON DELETE CASCADE,

  UNIQUE KEY uq_calif_entrega (entregas_tareas_id),
  INDEX idx_calif_docente (planteldocentes_id),
  INDEX idx_calif_fecha (fecha_calificacion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 7) Archivos (metadatos físicos), multi-institución
CREATE TABLE IF NOT EXISTS archivos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,

  instituciones_id INT NOT NULL COMMENT 'Institución propietaria del archivo',
  nombre_original VARCHAR(255) NOT NULL COMMENT 'Nombre original',
  nombre_almacenado VARCHAR(255) NOT NULL COMMENT 'Nombre único guardado en servidor',
  ruta VARCHAR(500) NOT NULL COMMENT 'Ruta lógica/relativa donde se guardó (para organizar por institución/aula/tarea)',
  tamano BIGINT NOT NULL COMMENT 'Tamaño en bytes',
  tipo_mime VARCHAR(120) NOT NULL COMMENT 'Tipo MIME',

  subido_por_tipo VARCHAR(30) NULL COMMENT 'PLANTELDOCENTE/ESTUDIANTE/ADMIN',
  subido_por_id BIGINT UNSIGNED NULL COMMENT 'ID del actor (según tipo)',

  estado VARCHAR(15) NULL COMMENT 'ACTIVO/INACTIVO',
  visibilidad VARCHAR(15) NULL COMMENT 'VISIBLE/OCULTO',

  created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_archivos_instituciones
    FOREIGN KEY (instituciones_id) REFERENCES instituciones(id)
    ON UPDATE CASCADE ON DELETE CASCADE,

  UNIQUE KEY uq_archivo_nombre_almacenado (nombre_almacenado),
  INDEX idx_archivos_institucion (instituciones_id),
  INDEX idx_archivos_mime (tipo_mime)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 8) Relaciones de archivos con entidades (publicación, entrega, etc.)
CREATE TABLE IF NOT EXISTS archivos_relaciones (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,

  archivos_id BIGINT UNSIGNED NOT NULL COMMENT 'Archivo',
  relacion_tipo VARCHAR(30) NOT NULL COMMENT 'PUBLICACION/ENTREGA (ampliable)',
  relacion_id BIGINT UNSIGNED NOT NULL COMMENT 'ID de la entidad (publicaciones_aula.id o entregas_tareas.id)',

  created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,

  CONSTRAINT fk_arch_rel_archivo
    FOREIGN KEY (archivos_id) REFERENCES archivos(id)
    ON UPDATE CASCADE ON DELETE CASCADE,

  INDEX idx_arch_rel (relacion_tipo, relacion_id),
  INDEX idx_arch_rel_archivo (archivos_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 9) Vínculo opcional de Tarea -> Rubro del registro oficial (para reflejar notas)
CREATE TABLE IF NOT EXISTS vinculos_tarea_rubro (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,

  tareas_id BIGINT UNSIGNED NOT NULL COMMENT 'Tarea de classroom',
  rubros_evaluacion_id INT NOT NULL COMMENT 'Rubro del sistema de registro (rubros_evaluacion.id)',

  modo VARCHAR(10) NOT NULL DEFAULT 'MANUAL' COMMENT 'MANUAL/AUTO',
  created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_vinc_tarea
    FOREIGN KEY (tareas_id) REFERENCES tareas(id)
    ON UPDATE CASCADE ON DELETE CASCADE,

  CONSTRAINT fk_vinc_rubro
    FOREIGN KEY (rubros_evaluacion_id) REFERENCES rubros_evaluacion(id)
    ON UPDATE CASCADE ON DELETE CASCADE,

  UNIQUE KEY uq_vinc_tarea (tareas_id),
  INDEX idx_vinc_rubro (rubros_evaluacion_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
