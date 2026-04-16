# BH Custom Fields - Guía de Instalación y Configuración

## 📦 Instalación

### 1. Copiar archivos al servidor

```bash
# WordPress estándar:
cp bh_custom_fields.php bh-custom-fields-config.json /path/to/wp-content/plugins/bh-custom-fields/

# WordPress Bedrock:
cp bh_custom_fields.php bh-custom-fields-config.json /path/to/app/plugins/bh-custom-fields/
```

### 2. Activar el plugin

```bash
wp --path=/path/to/wordpress plugin activate bh-custom-fields
```

O desde el panel: **Plugins > Plugins instalados > BH Custom Fields > Activar**

### 3. Primera activación

Al activar, el plugin copia automáticamente el JSON a la base de datos (`bh_fields_current`) y guarda el hash MD5 del archivo. No requiere configuración adicional.

---

## ⚙️ Configuración del JSON

El archivo `bh-custom-fields-config.json` define todos los campos. Está organizado por post type:

```json
{
  "post": [ ...campos... ],
  "page": [ ...campos... ],
  "mi_cpt": [ ...campos... ]
}
```

### Estructura de un campo

```json
{
  "id": "nombre_campo",
  "label": "Etiqueta visible",
  "type": "tipo_campo",
  "description": "Descripción opcional",
  "default": "valor_por_defecto"
}
```

- `default`: opcional. Valor que se muestra en el editor cuando el campo nunca fue guardado (post nuevo o campo agregado a un post existente). Soportado en todos los tipos excepto campos múltiples.

---

## 📝 Tipos de campos

### text
```json
{ "id": "subtitulo", "label": "Subtítulo", "type": "text", "mode": "plain" }
{ "id": "contenido", "label": "Contenido", "type": "text", "mode": "html" }
```
- `mode`: `"plain"` (textarea) o `"html"` (editor TinyMCE)

### bool
```json
{ "id": "destacado", "label": "Destacado", "type": "bool", "checkbox_label": "Marcar como destacado" }
{ "id": "capitular", "label": "Capitular", "type": "bool", "checkbox_label": "Mostrar capitular", "default": true }
```
- `checkbox_label`: texto junto al checkbox
- `default`: valor inicial para posts nuevos o que nunca guardaron este campo (`true` o `false`). Una vez que el editor guarda el post, el valor explícito prevalece.

### choice
```json
{
  "id": "estado",
  "label": "Estado",
  "type": "choice",
  "display": "select",
  "options": { "borrador": "Borrador", "publicado": "Publicado", "archivado": "Archivado" }
}
```
- `display`: `"select"` (dropdown) o `"radio"` (botones de radio)
- `options`: objeto `{ "clave": "Label visible" }`

### related
```json
{ "id": "post_relacionado", "label": "Post relacionado", "type": "related", "related_type": "post" }
```
- `related_type`: `"post"`, `"page"` o slug de cualquier CPT
- `display`: `"select"` (dropdown estático, por defecto) o `"autocomplete"` (ver abajo)

#### display: "autocomplete"

```json
{
  "id": "autor",
  "label": "Autor",
  "type": "related",
  "related_type": "autor",
  "display": "autocomplete"
}
```

Muestra un input de texto con búsqueda AJAX en tiempo real (debounce 250ms). Al escribir aparece un dropdown con sugerencias de posts existentes del `related_type` especificado.

**Comportamiento al guardar:**
- Si el usuario seleccionó una sugerencia → se guarda el ID del post existente (igual que `select`)
- Si el usuario escribió un nombre que no existe → se crea automáticamente un nuevo post de tipo `related_type` con ese título en estado `publish`, y se guarda su ID

Este comportamiento es equivalente al de las categorías de WordPress: si no existe, se crea al guardar.

### media
```json
{ "id": "imagen", "label": "Imagen", "type": "media", "media_type": "image" }
```
- `media_type`: `"image"`, `"video"` o `"document"`

### date
```json
{ "id": "fecha_evento", "label": "Fecha del evento", "type": "date" }
```
- Muestra un date picker nativo del navegador
- El valor se guarda en formato `YYYY-MM-DD`
- Para mostrar en templates, formatear con `date_i18n()`:
```php
$fecha = bhack_get_custom_field('fecha_evento');
if ($fecha) echo date_i18n(get_option('date_format'), strtotime($fecha));
```

---

## 🔢 Campos múltiples

Cualquier tipo puede ser múltiple agregando:

```json
{
  "id": "galeria",
  "label": "Galería",
  "type": "media",
  "media_type": "image",
  "multiple": true,
  "max_items": 10
}
```

---

## 🔄 Modificar la configuración

1. Editar `bh-custom-fields-config.json`
2. En el panel de WordPress aparecerá una **notice amarilla** avisando que hay cambios
3. Ir a **Fields Sync** en el menú lateral
4. Revisar los cambios detectados:
   - **Cambios seguros** (verde): se aplican con un click
   - **Conflictos** (rojo/amarillo): requieren resolución antes de aplicar
5. Resolver conflictos si los hay
6. Hacer click en **"Actualizar configuración"**

> ⚠️ **Nunca edites el JSON y asumas que los cambios se aplicaron solos.**
> El plugin lee la config desde la BD. Los cambios en el JSON solo se aplican
> cuando se hace click en "Actualizar configuración" en la página Fields Sync.

---

## 💻 Uso en templates

### Campos simples

```php
// Obtener valor
$subtitulo = bhack_get_custom_field('subtitulo');

// Imprimir valor
bhack_the_custom_field('subtitulo');

// Imprimir imagen
bhack_the_custom_field_media('imagen', 'large');

// Obtener URL de imagen
$url = bhack_get_custom_field_media_url('imagen', 'medium');

// Post relacionado
$post = bhack_get_custom_field_related('post_relacionado');
if ($post) {
    echo '<a href="' . get_permalink($post->ID) . '">' . $post->post_title . '</a>';
}

// Label de campo choice (en vez de la clave)
bhack_the_custom_field_label('estado'); // imprime "Publicado" en vez de "publicado"
```

### Campos múltiples

```php
// Galería de imágenes
$galeria = bhack_get_custom_field('galeria');
foreach ((array) $galeria as $id) {
    echo wp_get_attachment_image($id, 'medium');
}

// Posts relacionados
$relacionados = bhack_get_custom_field_related('posts_relacionados');
foreach ((array) $relacionados as $post) {
    echo $post->post_title;
}
```

---

## 📚 Funciones helper disponibles

| Función | Descripción |
|---------|-------------|
| `bhack_get_custom_field($id, $post_id)` | Retorna el valor del campo |
| `bhack_the_custom_field($id, $post_id)` | Imprime el valor del campo |
| `bhack_get_custom_field_related($id, $post_id)` | Retorna post(s) relacionado(s) |
| `bhack_get_custom_field_media_url($id, $size, $post_id)` | Retorna URL(s) de media |
| `bhack_the_custom_field_media($id, $size, $post_id, $attr)` | Imprime imagen(es) |
| `bhack_get_custom_field_label($id, $post_id)` | Retorna label legible de campo choice |
| `bhack_the_custom_field_label($id, $post_id)` | Imprime label legible de campo choice |

Todas las funciones soportan campos simples y múltiples automáticamente.

---

## ✅ Checklist de instalación

- [ ] Archivos copiados a `/wp-content/plugins/bh-custom-fields/` (o `/app/plugins/` en Bedrock)
- [ ] Plugin activado
- [ ] JSON configurado y validado
- [ ] Campos visibles al editar posts/páginas
- [ ] Página "Fields Sync" muestra "Configuración sincronizada"
