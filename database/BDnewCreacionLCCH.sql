CREATE TABLE `personal_access_tokens` (
    `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `tokenable_type` VARCHAR(255) NOT NULL,
    `tokenable_id` BIGINT(20) UNSIGNED NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `token` VARCHAR(64) NOT NULL,
    `abilities` TEXT,
    `nombrecompleto` VARCHAR(80) NULL,
    `pertenencia` VARCHAR(20) NULL,
    `permisos` VARCHAR(50) NULL,
    `expires_at` TIMESTAMP NULL,
    `last_used_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`, `tokenable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE OR REPLACE TABLE anios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    Anio VARCHAR(11) NULL,
    Estado VARCHAR(15) NULL,
    Visibilidad VARCHAR(15) NULL,
    EdicionInscripciones VARCHAR(25) NULL,
    EdicionCalificaciones VARCHAR(25) NULL,
    Predeterminado VARCHAR(50) NULL,
    created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- PARA LAS INSTITUCIONES
CREATE OR REPLACE TABLE instituciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    AnioIncorporacion VARCHAR(20) NULL,
    NIT VARCHAR(30) NULL,
    Nombre VARCHAR(100) NULL,
    Logo TEXT NULL,
    BannerInicial VARCHAR(80) NULL,
    ImagenVision VARCHAR(80) NULL,
    ImagenMision VARCHAR(80) NULL,
    Direccion VARCHAR(150) NULL,
    UbicacionGps VARCHAR(100) NULL,
    Telefono VARCHAR(15) NULL,
    Celular VARCHAR(15) NULL,
    Celular2 VARCHAR(15) NULL,
    Celular3 VARCHAR(15) NULL,
    Mision VARCHAR(300) NULL,
    Vision VARCHAR(300) NULL,
    Facebook VARCHAR(100) NULL,
    Tiktok VARCHAR(100) NULL,
    Instagram VARCHAR(100) NULL,
    PlataformaEducativa VARCHAR(100) NULL,
    Historia TEXT NULL,
    Funciones VARCHAR(250) NULL,
    Caractisticas VARCHAR(250) NULL,
    Estado VARCHAR(10) NULL,
    Visibilidad VARCHAR(10) NULL,
    created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE OR REPLACE TABLE carreras (
    id INT AUTO_INCREMENT PRIMARY KEY,
    instituciones_id INT NOT NULL,
    NombreCarrera VARCHAR(100) NULL,
    Descripcion TEXT NULL,
    Area VARCHAR(50) NULL,
    Mencion VARCHAR(50) NULL,
    Resolucion VARCHAR(50) NULL,
    Programa VARCHAR(50) NULL,
    CantidadEvaluaciones INT NULL, -- PARA DETERMINAR CUANTAS EVALUACIONES SE TENDRA DURANTE EL AÑO DEPENDIENDO SI ES ANUAL O SEMESTRAL
    Nivel VARCHAR(50) NULL, -- TECNICO SUPERIOR, CAPACITACION
    Capacitacion VARCHAR(50) NULL, -- PARA AQUELLAS INSTITUCIONES Q MANEJAN MUY APARTE SUS ESTUDIANTES PEQUEÑOS
    CarreraProfesional VARCHAR(50) NULL, -- PARA AQUELLAS INSTITUCIONES Q MANEJAN MUY APARTE SUS ESTUDIANTES GRANDES
    Modalidad VARCHAR(50) NULL,
    Duracion VARCHAR(50) NULL,
    HorasTotales VARCHAR(50) NULL,
    TituloOficial VARCHAR(100) NULL,
    LimiteMaxTeorico INT NULL,
    LimiteMaxPractico INT NULL,
    NotaAprobacion INT NULL,
    NotaMinRevalida INT NULL,
    Estado VARCHAR(10) NULL,
    Visibilidad VARCHAR(10) NULL,
    created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (instituciones_id) REFERENCES instituciones(id) ON UPDATE CASCADE ON DELETE CASCADE
);

CREATE OR REPLACE TABLE plandeestudios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    carreras_id INT NOT NULL,
    Rango INT NULL, -- PARA ORDENAR LAS MATERIAS
    RangoLvlCurso INT NULL, -- PARA ORDENAR LAS MATERIAS SEGUN EL NIVEL DEL CURSO
    LvlCurso VARCHAR(50) NULL, -- PRIMERO SUPERIOR, SEGUNDO SUPERIOR, TERCERO SUPERIOR
    Horas INT NULL, -- PARA LAS HORAS DE LA MATERIA
    anio_id INT NULL, -- PARA SABER A QUE AÑO PERTENECE LA MATERIA DEL PLAN DE ESTUDIOS
    ModoMateria VARCHAR(50) NULL, -- PARA CONFIGURAR EL TIPO DE RELACION DOCENTE MATERIA 1:* , 1:1 
    NombreMateria VARCHAR(100) NULL,
    SiglaMateria VARCHAR(20) NULL,
    Prerrequisitos VARCHAR(100) NULL,
    SiglasPrerrequisitos VARCHAR(50) NULL,
    TipoMateria VARCHAR(50) NULL,
    Periodo VARCHAR(25) NULL,
    RelacionDocenteCursoAEstudiante VARCHAR(60) NULL,
    created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (carreras_id) REFERENCES carreras(id) ON UPDATE CASCADE ON DELETE CASCADE,
    FOREIGN KEY (anio_id) REFERENCES anios(id) ON UPDATE CASCADE ON DELETE CASCADE
);
-- PARA LOS USUARIOS DOCENTES Y ADMINS DE LAS INSTITUCIONES
CREATE OR REPLACE TABLE usuarioslcchs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    Nombres VARCHAR(100) NULL,
    Apellidos VARCHAR(100) NULL,
    Usuario VARCHAR(50) NULL,
    Contrasenia VARCHAR(500) NULL,
    CelularTrabajo INT NULL,
    Foto VARCHAR(500) NULL,
    Estado VARCHAR(10) NULL,
    Tipo VARCHAR(500) NULL,
    Permisos VARCHAR(50) NULL,
    Cargo VARCHAR(100) NULL,
    Biografia TEXT NULL,
    Visibilidad VARCHAR(15) NULL,
    created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
CREATE OR REPLACE TABLE planteldocentes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    instituciones_id INT NOT NULL,
    Nombres VARCHAR(100) NULL,
    Apellidos VARCHAR(100) NULL,
    Sexo VARCHAR(10) NULL,
    FechaNac DATE NULL,
    Usuario VARCHAR(50) NULL,
    Contrasenia VARCHAR(500) NULL,
    Celular INT NULL,
    CelularTrabajo INT NULL,
    Carnet VARCHAR(50) NULL,
    Foto VARCHAR(500) NULL,
    Estado VARCHAR(10) NULL,
    Tipo VARCHAR(500) NULL,
    Permisos VARCHAR(50) NULL,
    Cargo VARCHAR(100) NULL,
    Biografia TEXT NULL,
    Visibilidad VARCHAR(15) NULL,
    
    created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (instituciones_id) REFERENCES instituciones(id) ON UPDATE CASCADE ON DELETE CASCADE
);
CREATE OR REPLACE TABLE planteladministrativos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    instituciones_id INT NOT NULL,
    Nombres VARCHAR(100) NULL,
    Apellidos VARCHAR(100) NULL,
    Sexo VARCHAR(10) NULL,
    FechaNac DATE NULL,
    Usuario VARCHAR(50) NULL,
    Contrasenia VARCHAR(500) NULL,
    Celular INT NULL,
    CelularTrabajo INT NULL,
    Carnet VARCHAR(50) NULL,
    Foto VARCHAR(500) NULL,
    Estado VARCHAR(10) NULL,
    Tipo VARCHAR(500) NULL,
    Permisos VARCHAR(50) NULL,
    Cargo VARCHAR(100) NULL,
    Biografia TEXT NULL,
    Visibilidad VARCHAR(15) NULL,
    
    created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (instituciones_id) REFERENCES instituciones(id) ON UPDATE CASCADE ON DELETE CASCADE
);
-- ESTUDIANTES IFAS
CREATE OR REPLACE TABLE estudiantesifas (
    id INT AUTO_INCREMENT PRIMARY KEY, 
    Foto VARCHAR(250) NULL, -- INFORMACION PERSONAL
    Ap_Paterno VARCHAR(50) NULL,
    Ap_Materno VARCHAR(50) NULL,
    Nombre VARCHAR(60) NULL,
    Sexo VARCHAR(10) NULL,
    FechaNac DATE NULL,
    Edad INT NULL,
    CI VARCHAR(20) NULL,
    Expedido VARCHAR(20) NULL,
    Celular VARCHAR(15) NULL,
    Direccion VARCHAR(150) NULL,
    Correo VARCHAR(100) NULL,
    Nombre_Padre VARCHAR(50) NULL, -- INFORMACION FAMILIA
    Nombre_Madre VARCHAR(50) NULL,
    OcupacionP VARCHAR(20) NULL,
    OcupacionM VARCHAR(20) NULL,
    NumCelP VARCHAR(15) NULL,
    NumCelM VARCHAR(15) NULL,
    NColegio VARCHAR(100) NULL, -- INFORMACION ACADEMICA 
    TipoColegio VARCHAR(50) NULL,
    CGrado VARCHAR(50) NULL,
    CNivel VARCHAR(50) NULL,
    Usuario VARCHAR(50) NULL, -- INFORMACION DE IFA
    Contrasenia VARCHAR(500) NULL,
    Estado VARCHAR(10) NULL,
    Matricula VARCHAR(25) NULL,
    InstrumentoMusical VARCHAR(100) NULL,
    IntrumentoMusicalSecundario VARCHAR(100) NULL,
    InformacionCompartidaIFAS TEXT NULL,

    created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE OR REPLACE TABLE infoestudiantesifas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    estudiantesifas_id INT NOT NULL,
    planteldocadmins_id INT NULL,
    planteldocadmins_idPC INT NULL,
    planteldocadmins_idOtros INT NULL,
    instituciones_id INT NOT NULL,
    FechInsc DATE NULL, -- INFO QUE ESTABA EN TABLA ORIGINAL
    Verificacion VARCHAR(100) NULL,
    Anotaciones TEXT NULL,
    Notas TEXT NULL,
    Observacion TEXT NULL,
    Categoria VARCHAR(50) NULL, -- ANTIGUO, NUEVO, TRANSFERIDO
    Turno VARCHAR(20) NULL, -- MAÑANA, TARDE, NOCHE
    Curso_Solicitado VARCHAR(60) NULL, -- PRIMERO SUPERIOR, SEGUNDO SUPERIOR, TERCERO SUPERIOR
    Paralelo_Solicitado VARCHAR(5) NULL, -- A, B, C
    CantidadMateriasAsignadas INT NULL,
    InstrumentoMusical VARCHAR(100) NULL,
    IntrumentoMusicalSecundario VARCHAR(100) NULL,
    created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (estudiantesifas_id) REFERENCES estudiantesifas(id) ON UPDATE CASCADE ON DELETE CASCADE,
    FOREIGN KEY (planteldocadmins_id) REFERENCES planteldocentes(id) ON UPDATE CASCADE ON DELETE SET NULL,
    FOREIGN KEY (planteldocadmins_idPC) REFERENCES planteldocentes(id) ON UPDATE CASCADE ON DELETE SET NULL,
    FOREIGN KEY (planteldocadmins_idOtros) REFERENCES planteldocentes(id) ON UPDATE CASCADE ON DELETE SET NULL,
    FOREIGN KEY (instituciones_id) REFERENCES instituciones(id) ON UPDATE CASCADE ON DELETE CASCADE
);

-- MATERIAS
CREATE OR REPLACE TABLE materias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    plandeestudios_id INT NOT NULL,
    Paralelo VARCHAR(5) NULL,
    EstadoHabilitacion VARCHAR(50) NULL,
    EstadoEnvio VARCHAR(250) NULL,
    created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (plandeestudios_id) REFERENCES plandeestudios(id) ON UPDATE CASCADE ON DELETE CASCADE
);

CREATE OR REPLACE TABLE planteldocentesmaterias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    planteldocentes_id INT NOT NULL,
    materias_id INT NOT NULL,
    created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (planteldocentes_id) REFERENCES planteldocentes(id) ON UPDATE CASCADE ON DELETE CASCADE,
    FOREIGN KEY (materias_id) REFERENCES materias(id) ON UPDATE CASCADE ON DELETE CASCADE
);

CREATE OR REPLACE TABLE calificaciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    infoestudiantesifas_id INT NOT NULL,
    materias_id INT NOT NULL,
    Teorico1 INT NULL,
    Teorico2 INT NULL,
    Teorico3 INT NULL,
    Teorico4 INT NULL,
    Practico1 INT NULL,
    Practico2 INT NULL,
    Practico3 INT NULL,
    Practico4 INT NULL,
    PromTeorico INT NULL,
    PromPractico INT NULL,
    Promedio INT NULL,
    PruebaRecuperacion INT NULL,
    EstadoRegistroMateria VARCHAR(50) NULL, -- ARRASTRE, REGULAR, CONVALIDADO, CORRELATIVO
    
    created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (infoestudiantesifas_id) REFERENCES infoestudiantesifas(id) ON UPDATE CASCADE ON DELETE CASCADE,
    FOREIGN KEY (materias_id) REFERENCES materias(id) ON UPDATE CASCADE ON DELETE CASCADE
);

CREATE OR REPLACE TABLE historialinformacionestudiantes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    Nombres VARCHAR(100) NULL,
    Apellidos VARCHAR(100) NULL,
    Anio VARCHAR(11) NULL,
    Categoria VARCHAR(50) NULL,
    DocenteEspecialidad VARCHAR(100) NULL,
    DocentePC VARCHAR(100) NULL,
    DocenteOtros VARCHAR(100) NULL,
    Institucion VARCHAR(100) NULL,
    Especialidad VARCHAR(100) NULL,
    Observacion TEXT NULL,
    Turno VARCHAR(20) NULL,
    Edad INT NULL,
    MallaCurricular VARCHAR(100) NULL,
    created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);


CREATE OR REPLACE TABLE inicios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    archivo VARCHAR(200) NULL,
    etiqueta VARCHAR(50) NULL,
    titulo VARCHAR(100) NULL,
    subtitulo VARCHAR(100) NULL,
    descripcion TEXT NULL,
    categoria VARCHAR(50) NULL,
    link VARCHAR(150) NULL,
    costo INT NULL,
    duracion VARCHAR(20) NULL,
    cupos VARCHAR(100) NULL,
    fecha DATE NULL,
    -- AHORA LO NUEVO
    icono VARCHAR(100) NULL,
    estado VARCHAR(15) NULL,
    visibilidad VARCHAR(15) NULL,
    id_institucion INT NULL,
    FOREIGN KEY (id_institucion) REFERENCES instituciones(id) ON UPDATE CASCADE ON DELETE CASCADE,

    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
-- controles es usado para los select de categorias, en si seria como si fuera el antiguo api
CREATE OR REPLACE TABLE controles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    instituciones_id INT NULL,
    Estado VARCHAR(15) NULL,
    Visibilidad VARCHAR(15) NULL,
    Categoria VARCHAR(60) NULL, 
    NivelCurso VARCHAR(60) NULL, 
    ParaI VARCHAR(60) NULL, 
    Edades VARCHAR(60) NULL, 

    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (instituciones_id) REFERENCES instituciones(id) ON UPDATE CASCADE ON DELETE CASCADE

);

-- PARA LOS RUBROS DE CALIFICACIONES registro de calificaciones
-- 1) Evaluaciones por materia (Eval 1..4)
CREATE TABLE evaluaciones_materia (
  id INT AUTO_INCREMENT PRIMARY KEY,
  materias_id INT NOT NULL,
  numero_eval TINYINT NOT NULL,          -- 1..4 (mapea a Teorico1/Practico1, etc.)
  nombre VARCHAR(120) NULL,              -- Ej: "Primera evaluación"
  limite_teorico INT NOT NULL DEFAULT 30,
  limite_practico INT NOT NULL DEFAULT 70,
    modo_eval TINYINT NOT NULL DEFAULT 3,  -- 1=Prom 100->(Teo20 + Asist10)/Prac70 | 2=Prom 30/70 | 3=Sumatoria
  habilitada TINYINT(1) NOT NULL DEFAULT 1,

  created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_eval_materia
    FOREIGN KEY (materias_id) REFERENCES materias(id)
    ON UPDATE CASCADE ON DELETE CASCADE,

  CONSTRAINT uq_eval_materia_numero
    UNIQUE (materias_id, numero_eval)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 2) Rubros/columnas dentro de cada evaluación
CREATE TABLE rubros_evaluacion (
  id INT AUTO_INCREMENT PRIMARY KEY,
  evaluacion_id INT NOT NULL,
  tipo ENUM('TEO','PRA') NOT NULL,        -- Teórico o Práctico
  nombre VARCHAR(150) NOT NULL,           -- Ej: "Tarea exposición"
  max_puntos INT NOT NULL,                -- Ej: 10 (se valida contra limites al sumar)
  orden INT NOT NULL DEFAULT 1,
    es_asistencia TINYINT(1) NOT NULL DEFAULT 0, -- 1 si es el rubro fijo de asistencia (siempre último en TEO)
  habilitado TINYINT(1) NOT NULL DEFAULT 1,

  created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_rubro_eval
    FOREIGN KEY (evaluacion_id) REFERENCES evaluaciones_materia(id)
    ON UPDATE CASCADE ON DELETE CASCADE,

  INDEX idx_rubro_eval (evaluacion_id),
  INDEX idx_rubro_eval_tipo (evaluacion_id, tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 3) Notas por estudiante por rubro
CREATE TABLE notas_rubro (
  id INT AUTO_INCREMENT PRIMARY KEY,
  rubro_id INT NOT NULL,
  infoestudiantesifas_id INT NOT NULL,
    nota INT NULL,                          -- TODO ES ENTERO (decimales se redondean en frontend/backend)
  observacion VARCHAR(250) NULL,

  created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_nota_rubro
    FOREIGN KEY (rubro_id) REFERENCES rubros_evaluacion(id)
    ON UPDATE CASCADE ON DELETE CASCADE,

  CONSTRAINT fk_nota_info
    FOREIGN KEY (infoestudiantesifas_id) REFERENCES infoestudiantesifas(id)
    ON UPDATE CASCADE ON DELETE CASCADE,

  CONSTRAINT uq_nota_rubro_est
    UNIQUE (rubro_id, infoestudiantesifas_id),

  INDEX idx_nota_est (infoestudiantesifas_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PARA SACAR FOTO CON CELULAR Y EMPAREJAR CELULAR
CREATE TABLE `capture_sessions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `token` VARCHAR(128) NOT NULL,
  `institucion_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NULL,
  `estudianteifas_id` BIGINT UNSIGNED NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'PENDING',
  `file_path` VARCHAR(255) NULL,
  `expires_at` DATETIME NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `capture_sessions_token_unique` (`token`),
  KEY `capture_sessions_institucion_status_index` (`institucion_id`, `status`),
  KEY `capture_sessions_estudiante_index` (`estudianteifas_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE `capture_pairings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `token` VARCHAR(128) NOT NULL,
  `institucion_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'PENDING',
  `device_label` VARCHAR(100) NULL,
  `pending_capture_token` VARCHAR(128) NULL,
  `linked_at` DATETIME NULL,
  `last_seen_at` DATETIME NULL,
  `expires_at` DATETIME NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `capture_pairings_token_unique` (`token`),
  KEY `capture_pairings_institucion_id_index` (`institucion_id`),
  KEY `capture_pairings_status_index` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FUNCIONA CON; EN LOCAL HAY Q REEMPLAZAR ENVIRONMENT localhost POR LA IP DE LA MAQUINA LCCH
-- php artisan serve --host 0.0.0.0 --port 8000
-- ng serve --host 0.0.0.0 --port 4200


-- PARA SISTEMA PAGOS
CREATE OR REPLACE TABLE pagoslcch (
  id INT AUTO_INCREMENT PRIMARY KEY,
  infoestudiantesifas_id INT NOT NULL,

  mes TINYINT NOT NULL,          -- 1..12
  gestion VARCHAR(10) NOT NULL,  -- ej: 2025
  monto INT NULL,

  fechapago DATE NULL,
  horapago TIME NULL,

  file VARCHAR(300) NULL,
  observacion TEXT NULL,
  estadopago VARCHAR(50) NULL,   -- ej: PAGADO/CANCELADO (desde controles)

  created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_pagoslcch_info FOREIGN KEY (infoestudiantesifas_id)
    REFERENCES infoestudiantesifas(id) ON UPDATE CASCADE ON DELETE CASCADE,

  UNIQUE KEY uq_pagoslcch_mes (infoestudiantesifas_id, gestion, mes),
  INDEX idx_pagoslcch_info (infoestudiantesifas_id),
  INDEX idx_pagoslcch_fecha (fechapago)
);
