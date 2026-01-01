# Sistema de Asistencias — Tablas y atributos

Este módulo implementa asistencia por QR + GPS multi‑institución, sin tocar tablas de LChaula.
Se relaciona con:
- `instituciones` (GPS base)
- `aulas_virtuales` (materia/aula)
- `aulas_participantes` (quién pertenece al aula)
- `infoestudiantesifas` (estudiante inscrito)
- `planteldocentes` (docente)

> SQL importable: `database/asistencias_tables.sql`

---

## 1) `asistencias_sesiones`
**Qué es:** una sesión diaria de asistencia por aula/materia. Regla: **1 sesión por día** (UNIQUE `aulas_virtuales_id, fecha`).

- `instituciones_id`: aislamiento multi‑institución.
- `aulas_virtuales_id`: aula/materia donde se controla asistencia.
- `planteldocentes_id`: docente que creó la sesión.
- `fecha`: día de la sesión.
- `hora_ingreso`: inicio (base para PRESENTE/ATRASO y vencimiento).
- `tiempo_espera_minutos`: ventana (min) para marcar **PRESENTE**.
- `minutos_falta`: fijo 30 por defecto; pasado este tiempo ya no registra y se cierra.
- `gps_requerido`: si exige GPS.
- `radio_metros`: radio permitido desde `instituciones.UbicacionGps`.
- `estado`: `ABIERTA/CERRADA/CANCELADA`.
- `visibilidad`: `VISIBLE/OCULTO`.

---

## 2) `asistencias_qr_tokens`
**Qué es:** tokens rotativos para el QR (ej. cada 5 segundos) para evitar reutilización.

- `asistencias_sesiones_id`: sesión dueña del token.
- `token`: contenido codificado en QR.
- `expires_at`: expiración corta.

---

## 3) `permisos_asistencia`
**Qué es:** permisos de secretaría para que, al cierre, un estudiante quede **PERMISO** y no **FALTA**.

- `instituciones_id`: institución.
- `infoestudiantesifas_id`: estudiante inscrito.
- `fecha_inicio`, `fecha_fin`: rango de fechas (inclusive).
- `aulas_virtuales_id`: `NULL` = permiso general; con valor = permiso solo para ese aula.
- `motivo`: texto.
- `registrado_por`: referencia simple (opcional).
- `estado`, `visibilidad`: banderas.

---

## 4) `asistencias_registros`
**Qué es:** resultado final por estudiante por sesión.

- `asistencias_sesiones_id`: sesión.
- `infoestudiantesifas_id`: estudiante.
- `estado_asistencia`: `PRESENTE/ATRASO/FALTA/PERMISO`.
- `metodo`: `QR/MANUAL/SISTEMA`.
- `fecha_registro`: cuándo quedó el registro.
- `asistencias_qr_tokens_id`: token usado (si fue QR).
- `gps_*`: lat/lng, precisión, distancia y si fue válido.

Regla: UNIQUE `(asistencias_sesiones_id, infoestudiantesifas_id)`.

---

## 5) `asistencias_auditoria` (opcional)
**Qué es:** trazabilidad para cambios manuales.

- `asistencias_registros_id`: registro afectado.
- `accion`: `CREAR/EDITAR/ANULAR`.
- `antes`, `despues`: texto simple.
- `actor_tipo`, `actor_id`: quién hizo el cambio.
- `fecha`: cuándo.
