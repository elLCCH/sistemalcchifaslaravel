# Adaptación de CoreDocu API a Laravel

## 📋 Resumen

Se ha adaptado completamente la API de CoreDocu.Api (.NET) a Laravel. El sistema incluye:

- **4 Modelos**: Project, Section, Article, Attachment
- **3 Controllers**: ProjectController, SectionController, ArticleController
- **Script SQL**: Archivo con las tablas necesarias
- **Rutas API**: Configuración completa de endpoints

---

## 🚀 Instalación

### 1. Crear las Tablas en la Base de Datos

Ejecuta el script SQL en tu base de datos MySQL:

```sql
-- Archivo: database/coreDocu_tables.sql
-- Copia el contenido del archivo y ejecuta en MySQL
```

Alternativamente, desde la línea de comandos:

```bash
mysql -u tu_usuario -p tu_base_de_datos < database/coreDocu_tables.sql
```

### 2. Agregar las Rutas a tu API

En tu archivo `routes/api.php`, agrega:

```php
<?php

use App\Http\Controllers\ProjectController;
use App\Http\Controllers\SectionController;
use App\Http\Controllers\ArticleController;

Route::apiResource('projects', ProjectController::class);

Route::prefix('sections')->group(function () {
    Route::get('project/{projectId}', [SectionController::class, 'byProject']);
    Route::apiResource('', SectionController::class);
    Route::post('reorder', [SectionController::class, 'reorder']);
});

Route::prefix('articles')->group(function () {
    Route::get('section/{sectionId}', [ArticleController::class, 'bySection']);
    Route::apiResource('', ArticleController::class);
    Route::post('reorder', [ArticleController::class, 'reorder']);
    
    Route::get('{articleId}/attachments', [ArticleController::class, 'getAttachments']);
    Route::post('{articleId}/upload', [ArticleController::class, 'upload']);
    Route::get('{articleId}/attachment/{attachmentId}', [ArticleController::class, 'downloadAttachment']);
    Route::delete('{articleId}/attachment/{attachmentId}', [ArticleController::class, 'deleteAttachment']);
});
?>
```

O copia directamente de `routes/coreDocu_api_routes.php`

### 3. Crear el Directorio para Archivos

Asegúrate de que exista el directorio para almacenar archivos adjuntos:

```bash
mkdir -p storage/app/attachments
chmod 755 storage/app/attachments
```

### 4. Archivos Creados

```
app/Models/
  ├── Project.php        (Modelo Project)
  ├── Section.php        (Modelo Section)
  ├── Article.php        (Modelo Article)
  └── Attachment.php     (Modelo Attachment)

app/Http/Controllers/
  ├── ProjectController.php   (CRUD Projects)
  ├── SectionController.php   (CRUD Sections + Reorder)
  └── ArticleController.php   (CRUD Articles + Attachments + Reorder)

database/
  └── coreDocu_tables.sql    (Script SQL con tablas)

routes/
  └── coreDocu_api_routes.php (Rutas API completas)
```

---

## 📚 Documentación de Endpoints

### **PROYECTOS**

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/api/projects` | Obtener todos los proyectos |
| POST | `/api/projects` | Crear nuevo proyecto |
| GET | `/api/projects/{id}` | Obtener proyecto por ID |
| PUT | `/api/projects/{id}` | Actualizar proyecto |
| DELETE | `/api/projects/{id}` | Eliminar proyecto |

**Crear Proyecto:**
```json
POST /api/projects
{
  "name": "Mi Proyecto",
  "description": "Descripción del proyecto"
}
```

---

### **SECCIONES**

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/api/sections/project/{projectId}` | Obtener secciones de un proyecto |
| POST | `/api/sections` | Crear nueva sección |
| GET | `/api/sections/{id}` | Obtener sección por ID |
| PUT | `/api/sections/{id}` | Actualizar sección |
| DELETE | `/api/sections/{id}` | Eliminar sección |
| POST | `/api/sections/reorder` | Reordenar secciones |

**Crear Sección:**
```json
POST /api/sections
{
  "project_id": 1,
  "title": "Mi Sección",
  "order": 0
}
```

**Reordenar Secciones:**
```json
POST /api/sections/reorder
[
  { "id": 1, "order": 0 },
  { "id": 2, "order": 1 },
  { "id": 3, "order": 2 }
]
```

---

### **ARTÍCULOS**

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/api/articles/section/{sectionId}` | Obtener artículos de una sección |
| POST | `/api/articles` | Crear nuevo artículo |
| GET | `/api/articles/{id}` | Obtener artículo por ID |
| PUT | `/api/articles/{id}` | Actualizar artículo |
| DELETE | `/api/articles/{id}` | Eliminar artículo |
| POST | `/api/articles/reorder` | Reordenar artículos |

**Crear Artículo:**
```json
POST /api/articles
{
  "section_id": 1,
  "title": "Mi Artículo",
  "content": "# Contenido Markdown",
  "order": 0
}
```

**Actualizar Artículo:**
```json
PUT /api/articles/1
{
  "title": "Título Actualizado",
  "content": "Contenido Markdown actualizado"
}
```

**Reordenar Artículos:**
```json
POST /api/articles/reorder
[
  { "id": 1, "order": 0 },
  { "id": 2, "order": 1 }
]
```

---

### **ARCHIVOS ADJUNTOS**

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/api/articles/{articleId}/attachments` | Listar archivos de un artículo |
| POST | `/api/articles/{articleId}/upload` | Subir archivo |
| GET | `/api/articles/{articleId}/attachment/{attachmentId}` | Descargar archivo |
| DELETE | `/api/articles/{articleId}/attachment/{attachmentId}` | Eliminar archivo |

**Subir Archivo:**
```
POST /api/articles/1/upload
Content-Type: multipart/form-data

file: [archivo binario]
```

---

## 🔐 Autenticación

Por defecto, los endpoints están protegidos con `auth:sanctum`. Para cambiar esto:

### Opción 1: Remover autenticación de endpoints públicos

En los controllers, cambia:

```php
// De esto:
public function index() { ... }

// A esto (si necesitas permitir acceso sin autenticación):
// En las rutas:
Route::get('projects', [ProjectController::class, 'index'])
    ->withoutMiddleware('auth:sanctum');
```

### Opción 2: Usar middleware personalizado

Crea un middleware si necesitas lógica específica:

```bash
php artisan make:middleware CheckProjectAccess
```

---

## 📁 Estructura de Carpetas

```
sistemalcchifas/
├── app/
│   ├── Models/
│   │   ├── Project.php
│   │   ├── Section.php
│   │   ├── Article.php
│   │   └── Attachment.php
│   └── Http/Controllers/
│       ├── ProjectController.php
│       ├── SectionController.php
│       └── ArticleController.php
├── database/
│   └── coreDocu_tables.sql
├── routes/
│   ├── api.php (agregar las rutas aquí)
│   └── coreDocu_api_routes.php (referencia)
└── storage/
    └── app/
        └── attachments/
```

---

## 🧪 Pruebas con Postman

### 1. Crear Proyecto

```
POST http://localhost:8000/api/projects
Headers: Content-Type: application/json
Body:
{
  "name": "Mi Documentación",
  "description": "Sistema de documentación completo"
}
```

### 2. Crear Sección

```
POST http://localhost:8000/api/sections
Headers: Content-Type: application/json
Body:
{
  "project_id": 1,
  "title": "Introducción",
  "order": 0
}
```

### 3. Crear Artículo

```
POST http://localhost:8000/api/articles
Headers: Content-Type: application/json
Body:
{
  "section_id": 1,
  "title": "Bienvenida",
  "content": "# Bienvenido a mi documentación",
  "order": 0
}
```

### 4. Subir Archivo

```
POST http://localhost:8000/api/articles/1/upload
Headers: Content-Type: multipart/form-data
Body:
file: [seleccionar archivo]
```

---

## ⚙️ Configuración Adicional

### Límites de Carga

En `config/filesystems.php`, verifica el límite máximo de upload en `php.ini`:

```ini
upload_max_filesize = 50M
post_max_size = 50M
```

En el controller ya está limitado a 50MB:

```php
'file' => 'required|file|max:50000', // 50MB
```

### CORS

Si necesitas CORS, instala y configura:

```bash
composer require fruitcake/laravel-cors
```

---

## 🔍 Relaciones entre Modelos

```
Project
  ├── has many Sections
  │     ├── has many Articles
  │     │     └── has many Attachments

Attachments pertenecen a Articles
Articles pertenecen a Sections
Sections pertenecen a Projects
```

---

## 📝 Notas Importantes

1. **Eliminación en cascada**: Al eliminar un proyecto, se eliminan automáticamente todas sus secciones, artículos y archivos.

2. **Archivos**: Los archivos se almacenan en `storage/app/attachments/` con nombres únicos para evitar conflictos.

3. **Orden**: Los campos `order` permiten mantener un orden personalizado en secciones y artículos.

4. **Timestamps**: Todos los modelos incluyen `created_at` y `updated_at` automáticamente.

5. **Respuestas**: Todos los endpoints devuelven:
   ```json
   {
     "success": true/false,
     "data": {...},
     "message": "..."
   }
   ```

---

## 🚨 Solución de Problemas

### Error: "SQLSTATE[42S02]: Table not found"

**Solución**: Ejecuta el script SQL en tu base de datos.

### Error: "Storage disk not found"

**Solución**: Crea la carpeta:
```bash
mkdir -p storage/app/attachments
```

### Error: "File not found" al descargar

**Solución**: Verifica que el archivo existe en `storage/app/attachments/`

---

## 📞 Soporte

Para más información sobre los endpoints, consulta `routes/coreDocu_api_routes.php`

¡Listo! Tu API CoreDocu está lista para usar en Laravel. 🎉
