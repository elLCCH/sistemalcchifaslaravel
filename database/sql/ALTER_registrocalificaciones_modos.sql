-- Ejecuta esto en phpMyAdmin si ya creaste las tablas y necesitas actualizarlas.

-- 1) evaluaciones_materia: agregar modo_eval (si no existe)
ALTER TABLE evaluaciones_materia
  ADD COLUMN modo_eval TINYINT NOT NULL DEFAULT 3 AFTER limite_practico;

-- 2) rubros_evaluacion: marcar rubro de asistencia
ALTER TABLE rubros_evaluacion
  ADD COLUMN es_asistencia TINYINT(1) NOT NULL DEFAULT 0 AFTER orden;

-- 3) notas_rubro: nota como INT (si aún está DECIMAL)
ALTER TABLE notas_rubro
  MODIFY COLUMN nota INT NULL;

-- Opcional: reponer asistencia si te falta (por evaluación)
-- (el backend lo crea automáticamente al cargar/guardar)
