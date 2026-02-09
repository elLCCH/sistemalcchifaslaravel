# LChaula (sistemaLChaula) — Tablas y atributos

Este módulo implementa un sistema tipo Classroom multi‑institución, apoyado en tu jerarquía:

- `instituciones → carreras → plandeestudios → materias`
- Estudiante “inscrito” = `infoestudiantesifas`
- Docentes = `planteldocentes`

> Nota: el SQL está en `database/lchaula_tables.sql` y usa `CREATE TABLE IF NOT EXISTS`.

---

## 1) `aulas_virtuales`
**Qué es:** el “Classroom” de una materia/paralelo dentro de una institución.

- `id`: identificador del aula.
- `instituciones_id`: institución propietaria del aula (aislamiento multi‑institución).
- `materias_id`: materia/paralelo base del aula virtual.
- `nombre`: nombre personalizado del aula (si es `NULL`, puedes mostrar el nombre de la materia).
- `descripcion`: presentación/reglas/descripcion del aula.
- `estado`: `ACTIVO/INACTIVO` (si `INACTIVO`, bloquea publicar/recibir entregas según reglas de negocio).
- `visibilidad`: `VISIBLE/OCULTO` (si `OCULTO`, no se lista).
- `created_at`, `updated_at`: auditoría.

**Por qué hay UNIQUE `(instituciones_id, materias_id)`:** evita duplicar aulas para la misma materia dentro de la misma institución.

---

## 2) `aulas_participantes`
**Qué es:** la inscripción “tipo classroom” y permisos dentro del aula.

- `id`: identificador.
- `aulas_virtuales_id`: aula a la que pertenece el participante.
- `tipo`: `ESTUDIANTE/DOCENTE/ADMIN`.
- `infoestudiantesifas_id`: si `tipo=ESTUDIANTE`, referencia a `infoestudiantesifas.id`.
- `planteldocentes_id`: si `tipo=DOCENTE`, referencia a `planteldocentes.id`.
- `planteladministrativos_id`: si `tipo=ADMIN`, referencia a `planteladministrativos.id`.
- `rol`: etiqueta libre (ej. `TITULAR`, `AUXILIAR`, `COLABORADOR`, `LECTOR`).
- `puede_publicar`: `1` si puede crear anuncios/material/tareas.
- `puede_calificar`: `1` si puede calificar entregas.
- `puede_administrar`: `1` si puede administrar el aula (configuración/participantes).
- `estado`, `visibilidad`: banderas estándar.
- `created_at`, `updated_at`.

**Requisito especial (admin colaborador):** un docente puede agregar un administrativo con `tipo='ADMIN'` y encender `puede_publicar` / `puede_calificar` según lo permitido.

---

## 3) `publicaciones_aula`
**Qué es:** el “muro” del aula.

- `id`: identificador.
- `aulas_virtuales_id`: aula destino.
- `tipo`: `ANUNCIO/MATERIAL/TAREA`.
- `titulo`: título.
- `descripcion`: contenido (puede ser markdown).
- `creado_por_tipo`, `creado_por_id`: quién creó la publicación.
- `fecha_publicacion`: permite programar (si lo usas); si `NULL` es inmediata.
- `estado`: `BORRADOR/PUBLICADO/CERRADO`.
- `visibilidad`: `VISIBLE/OCULTO`.
- `created_at`, `updated_at`.

---

## 4) `tareas`
**Qué es:** configuración específica de una publicación cuando `tipo='TAREA'`.

- `id`: identificador.
- `publicaciones_aula_id`: vínculo 1:1 con `publicaciones_aula`.
- `fecha_inicio`: desde cuándo acepta entregas (`NULL` = desde la publicación).
- `fecha_entrega`: deadline.
- `fecha_cierre`: cierre duro; si `NULL`, se usa `fecha_entrega`.
- `permitir_entrega_tardia`: `1` si acepta después del deadline.
- `limite_tardia_horas`: máximo horas extra (si `NULL`, sin límite).
- `bloquear_recepcion`: bloqueo manual (`SI/NO`) sin cambiar estados.
- `puntaje_maximo`: nota máxima.
- `tipo_calificacion`: `PUNTOS/PORCENTAJE` (opcional).
- `estado`, `visibilidad`: banderas.
- `created_at`, `updated_at`.

---

## 5) `entregas_tareas`
**Qué es:** la entrega del estudiante (1 por tarea y por estudiante).

- `id`: identificador.
- `tareas_id`: tarea entregada.
- `infoestudiantesifas_id`: estudiante inscrito que entrega.
- `estado`: `PENDIENTE/ENTREGADO/ATRASADO/DEVUELTO/CALIFICADO`.
- `fecha_entrega`: cuándo entregó.
- `comentario_estudiante`: mensaje del estudiante.
- `numero_reentrega`: contador simple (0=primera entrega).
- `created_at`, `updated_at`.

**Unique `(tareas_id, infoestudiantesifas_id)`:** evita múltiples filas; para reentrega actualizas la misma fila e incrementas `numero_reentrega` (si quieres historial real luego, se crea otra tabla).

---

## 6) `calificaciones_tareas`
**Qué es:** nota/feedback de una entrega (1:1), útil para recalificación/auditoría.

- `id`: identificador.
- `entregas_tareas_id`: entrega calificada.
- `planteldocentes_id`: docente que calificó.
- `puntaje_obtenido`: nota.
- `comentario_docente`: feedback.
- `fecha_calificacion`: fecha/hora de calificación.
- `estado`, `visibilidad`: banderas.
- `created_at`, `updated_at`.

---

## 7) `archivos`
**Qué es:** metadatos del archivo físico (sirve para materiales, tareas, entregas, etc.).

- `id`: identificador.
- `instituciones_id`: separación por institución.
- `nombre_original`: nombre real.
- `nombre_almacenado`: nombre único (evita colisiones).
- `ruta`: ruta lógica/relativa.
- `tamano`: bytes.
- `tipo_mime`: MIME.
- `subido_por_tipo`, `subido_por_id`: quién subió.
- `estado`, `visibilidad`.
- `created_at`, `updated_at`.

---

## 8) `archivos_relaciones`
**Qué es:** “pegamento” para asociar archivos a distintas entidades sin duplicar tablas.

- `id`: identificador.
- `archivos_id`: archivo.
- `relacion_tipo`: `PUBLICACION/ENTREGA` (ampliable).
- `relacion_id`: id de entidad destino (ej. `publicaciones_aula.id` o `entregas_tareas.id`).
- `created_at`.

Con esto una publicación puede tener varios archivos y una entrega también.

---

## 9) `vinculos_tarea_rubro`
**Qué es:** integración opcional con tu registro oficial (`rubros_evaluacion`, `notas_rubro`).

- `id`: identificador.
- `tareas_id`: tarea de LChaula.
- `rubros_evaluacion_id`: rubro del sistema de registro.
- `modo`: `MANUAL/AUTO`.
- `created_at`, `updated_at`.

**Uso típico:** si existe vínculo, al calificar puedes reflejar la nota en `notas_rubro` (por rubro + `infoestudiantesifas_id`).
