# BH Custom Fields — Guía para Claude

## Qué es este proyecto

Plugin de WordPress para gestionar campos personalizados (meta boxes) mediante un archivo JSON de configuración. Diseñado para sitios Bibliohack con 1-5 admins técnicos.

**Versión actual:** 1.1
**Archivo principal:** `bh_custom_fields.php`
**Clase principal:** `BHCustomFieldsManager`

---

## Entorno de desarrollo

- **WordPress:** instalación Bedrock en `/var/www/html/web/`
  - Core WP: `/var/www/html/web/wp/`
  - Plugins: `/var/www/html/web/app/plugins/`
- **Plugin desplegado:** `/var/www/html/web/app/plugins/bh-custom-fields/`
- **URL admin:** `http://defrentealfuturo.com/wp/wp-admin`
- **Credenciales:** admin / admin123
- **WP-CLI:** `wp --path=/var/www/html/web/wp --allow-root`

### Deploy

El archivo desplegado requiere `sudo` para sobrescribir:
```bash
sudo cp bh_custom_fields.php /var/www/html/web/app/plugins/bh-custom-fields/bh_custom_fields.php
sudo cp bh-custom-fields-config.json /var/www/html/web/app/plugins/bh-custom-fields/bh-custom-fields-config.json
```

---

## Arquitectura del plugin

### Fuente de verdad en runtime: la base de datos

El plugin **nunca lee el JSON en runtime**. Lee siempre de la opción `bh_fields_current`. El JSON solo se lee en dos momentos:
1. Primera activación (`initial_setup`)
2. Cuando el admin visita la página Fields Sync y hay cambios pendientes

### Opciones en `wp_options`

| Opción | Descripción |
|--------|-------------|
| `bh_fields_current` | Config activa (array PHP serializado) |
| `bh_json_hash` | MD5 del archivo JSON para detectar cambios |
| `bh_last_sync` | Timestamp de última sincronización |
| `bh_fields_disabled` | IDs de campos desactivados por conflicto type_changed |
| `bh_fields_force_enabled` | IDs de campos forzados activos por el admin |
| `bh_fields_delete_on_uninstall` | Bool: eliminar post_meta al desinstalar (default false) |

**Prefijo uniforme:** todas las opciones usan `bh_`. No usar otros prefijos.

### Flujo de sincronización

```
JSON modificado
    → check_json_changes() detecta md5 distinto (en cada admin_init)
    → notice amarilla en el admin
    → admin visita Fields Sync
    → analyze_changes(bd_config, json_config) clasifica cambios
    → conflictos bloquean el botón "Aplicar"
    → admin resuelve conflictos
    → ajax_apply_config() re-verifica y aplica
```

### Criterio "todo o nada"

Si hay **cualquier** conflicto pendiente, no se puede aplicar ningún cambio. El botón "Actualizar configuración" queda deshabilitado hasta resolver todos.

---

## Tipos de campos

| Tipo | Opciones específicas |
|------|---------------------|
| `text` | `mode: "plain"` o `"html"` |
| `bool` | `checkbox_label` |
| `choice` | `display: "select"` o `"radio"`, `options: {}` |
| `related` | `related_type: "post"/"page"/CPT` |
| `media` | `media_type: "image"/"video"/"document"` |
| `date` | — (date picker nativo, guarda `YYYY-MM-DD`) |

Todos soportan `"multiple": true` + `"max_items": N`.

---

## Tipos de cambios detectados por analyze_changes()

| Tipo | Condición | Clasificación |
|------|-----------|---------------|
| `field_deleted` | Campo en BD, no en JSON | Conflicto si hay posts con datos; seguro si no |
| `type_changed` | Tipo distinto entre BD y JSON | Conflicto si hay datos Y no está force-enabled |
| `option_removed` | Opción de choice eliminada | Conflicto si hay posts con esa opción; seguro si no |
| `new_field` | Campo en JSON, no en BD | Siempre seguro |
| `label_changed` | Label o description distintos | Siempre seguro |
| `options_added` | Nuevas opciones en choice | Siempre seguro |

---

## Funciones helper para templates

```php
bhack_get_custom_field($field_id, $post_id)
bhack_the_custom_field($field_id, $post_id)
bhack_get_custom_field_related($field_id, $post_id)
bhack_get_custom_field_media_url($field_id, $size, $post_id)
bhack_the_custom_field_media($field_id, $size, $post_id, $attr)
bhack_get_custom_field_label($field_id, $post_id)   // para tipo choice
bhack_the_custom_field_label($field_id, $post_id)
```

---

## AJAX handlers

Todos verifican `check_ajax_referer('bhcf_sync', 'nonce')`.

| Action | Método | Descripción |
|--------|--------|-------------|
| `bhcf_apply_config` | `ajax_apply_config` | Aplica JSON → BD (re-verifica conflictos server-side) |
| `bhcf_view_posts` | `ajax_view_posts` | Lista posts que usan un campo |
| `bhcf_export_data` | `ajax_export_data` | Exporta datos como CSV |
| `bhcf_delete_field_data` | `ajax_delete_field_data` | Elimina post_meta en batches de 50 |
| `bhcf_force_enable` | `ajax_force_enable` | Fuerza activación de campo type_changed |
| `bhcf_show_old_config` | `ajax_show_old_config` | Muestra config anterior del campo |

---

## Decisiones de diseño importantes

- **`clearstatcache(true, $json_file)`** antes de `md5_file()` en `check_json_changes()` y `render_sync_page()` — evita que PHP sirva el hash del archivo en caché cuando Node.js (u otro proceso externo) modifica el JSON.
- **`is_array($force_enabled)`** guard en `analyze_changes()` — WP-CLI puede almacenar opciones como strings serializados en vez de arrays; la guarda previene TypeError.
- **`json_decode` validation** en `render_sync_page()` — si el JSON tiene error de sintaxis, muestra mensaje descriptivo en vez de fatal error.
- El batch delete usa 50 posts por llamada AJAX para evitar timeouts en sitios grandes.
- `render_sync_page()` actualiza `bh_fields_disabled` en cada carga (no solo al aplicar).

---

## Tests E2E

**Ubicación:** `/tmp/pw-test/test-bh-fields.js`
**Framework:** Playwright (Node.js, instalado en `/tmp/pw-test/node_modules`)

```bash
cd /tmp/pw-test && node test-bh-fields.js
```

Cubre 8 partes: login, detección de cambios, sync UI, modales, editor con campo desactivado, resolución de conflictos, aplicar config, estado final.

El test es idempotente: el `finally` resetea JSON, post_meta y todas las opciones relevantes a su estado original.

### Problemas conocidos del entorno de test

- Gutenberg carga el editor en un iframe `blob:` (`name="editor-canvas"`) — **no** usa `meta-box-loader`. Los meta boxes se renderizan en el DOM principal pero dentro de un panel colapsado. Usar `page.evaluate(() => document.body.innerHTML)` para buscar contenido en secciones colapsadas.
- `waitForLoadState('networkidle')` falla en páginas con Gutenberg (hace requests continuos). Usar `'domcontentloaded'` para el editor.
- `location.reload()` tras AJAX de force-enable es inmediato; tras delete y apply-config hay un `setTimeout` de 1500ms y 1000ms respectivamente — usar `waitForNavigation` con timeout generoso.

---

## Bugs corregidos (historial)

| Commit | Fix |
|--------|-----|
| `74d00de` | Fatal error cuando el JSON tiene error de sintaxis |
| `8e38680` | Prefijo inconsistente `bhcf_` → `bh_` en `force_enabled` |
| `185f54e` | Opción para controlar si se borran datos al desinstalar |

---

## Cosas pendientes / posibles mejoras

- La opción `bh_fields_conflicts` está listada en el uninstaller pero nunca se usa en el código actual (era para una feature futura).
- El label de `field_deleted` y `type_changed` en la tabla de cambios seguros no tiene emoji (a diferencia de `new_field` y `label_changed`).
- No hay paginación en `ajax_view_posts` — en sitios con miles de posts podría ser lento.
