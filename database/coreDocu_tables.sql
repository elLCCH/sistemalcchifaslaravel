-- ============================================================
-- TABLAS PARA EL SISTEMA DE GESTIÓN DE DOCUMENTACIÓN COREDOCU
-- Adaptado a Laravel con MySQL
-- ============================================================

-- Tabla de Proyectos
CREATE TABLE IF NOT EXISTS `projects` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL COMMENT 'Nombre del proyecto',
    `description` LONGTEXT NULL COMMENT 'Descripción del proyecto',
    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Fecha de creación',
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Fecha de última actualización',
    INDEX idx_name (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Almacena los proyectos de documentación';

-- Tabla de Secciones (pertenecen a un proyecto)
CREATE TABLE IF NOT EXISTS `sections` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `project_id` BIGINT UNSIGNED NOT NULL COMMENT 'ID del proyecto',
    `title` VARCHAR(255) NOT NULL COMMENT 'Título de la sección',
    `order` INT NOT NULL DEFAULT 0 COMMENT 'Orden de la sección dentro del proyecto',
    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Fecha de creación',
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Fecha de última actualización',
    CONSTRAINT fk_sections_projects FOREIGN KEY (`project_id`) 
        REFERENCES `projects`(`id`) ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_project_id (`project_id`),
    INDEX idx_order (`order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Almacena las secciones de los proyectos';

-- Tabla de Artículos (pertenecen a una sección)
CREATE TABLE IF NOT EXISTS `articles` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `section_id` BIGINT UNSIGNED NOT NULL COMMENT 'ID de la sección',
    `title` VARCHAR(255) NOT NULL COMMENT 'Título del artículo',
    `content` LONGTEXT NULL COMMENT 'Contenido del artículo (Markdown)',
    `order` INT NOT NULL DEFAULT 0 COMMENT 'Orden del artículo dentro de la sección',
    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Fecha de creación',
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Fecha de última actualización',
    CONSTRAINT fk_articles_sections FOREIGN KEY (`section_id`) 
        REFERENCES `sections`(`id`) ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_section_id (`section_id`),
    INDEX idx_order (`order`),
    FULLTEXT INDEX ft_content (title, content)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Almacena los artículos de las secciones';

-- Tabla de Archivos Adjuntos (pertenecen a un artículo)
CREATE TABLE IF NOT EXISTS `attachments` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `article_id` BIGINT UNSIGNED NOT NULL COMMENT 'ID del artículo',
    `file_name` VARCHAR(255) NOT NULL COMMENT 'Nombre original del archivo',
    `stored_name` VARCHAR(255) NOT NULL COMMENT 'Nombre almacenado en el servidor',
    `size` BIGINT NOT NULL COMMENT 'Tamaño del archivo en bytes',
    `content_type` VARCHAR(100) NOT NULL COMMENT 'Tipo MIME del archivo',
    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Fecha de creación',
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Fecha de última actualización',
    CONSTRAINT fk_attachments_articles FOREIGN KEY (`article_id`) 
        REFERENCES `articles`(`id`) ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_article_id (`article_id`),
    UNIQUE INDEX unique_stored_name (`stored_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Almacena los archivos adjuntos de los artículos';

-- ============================================================
-- DATOS DE EJEMPLO (OPCIONAL)
-- ============================================================

-- Insertar un proyecto de ejemplo
INSERT INTO `projects` (`name`, `description`) VALUES 
('Mi Primer Proyecto', 'Este es un proyecto de documentación de ejemplo');

-- Insertar una sección de ejemplo
INSERT INTO `sections` (`project_id`, `title`, `order`) VALUES 
(1, 'Introducción', 0),
(1, 'Guía de Instalación', 1),
(1, 'Referencia de API', 2);

-- Insertar artículos de ejemplo
INSERT INTO `articles` (`section_id`, `title`, `content`, `order`) VALUES 
(1, 'Bienvenida', '# Bienvenida\n\nEste es el primer artículo de tu documentación.', 0),
(2, 'Requisitos', '## Requisitos del Sistema\n\n- PHP 8.1 o superior\n- Laravel 10.x\n- MySQL 5.7 o superior', 0),
(3, 'Endpoints', '## Endpoints Disponibles\n\n### Proyectos\n- GET /api/projects\n- POST /api/projects\n- PUT /api/projects/{id}\n- DELETE /api/projects/{id}', 0);
