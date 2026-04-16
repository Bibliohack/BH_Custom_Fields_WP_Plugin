# BH Custom Fields - Flujo de guardado y arquitectura de datos

## 🔄 Flujo de guardado de posts

### 1. Trigger
WordPress ejecuta el hook `save_post` al publicar o actualizar un post:
```php
add_action('save_post', [$this, 'save_meta_boxes']);
```

### 2. Verificaciones de seguridad
```php
// Nonce CSRF
wp_verify_nonce($_POST['bh_custom_fields_nonce'], 'bh_custom_fields_save')

// Evitar autoguardados
if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

// Verificar permisos
if (!current_user_can('edit_post', $post_id)) return;
```

### 3. Sanitización por tipo

| Tipo | Sanitización |
|------|-------------|
| `text` plain | `sanitize_text_field()` |
| `text` html | `wp_kses_post()` (permite HTML seguro) |
| `bool` | `(bool) $value` — guarda `'0'` (string) explícitamente si no está marcado |
| `choice` | `sanitize_text_field()` (o `array_map` para selección múltiple) |
| `related` (select) | `sanitize_text_field()` (guarda el ID numérico) |
| `related` (autocomplete, post existente) | `sanitize_text_field()` (guarda el ID numérico) |
| `related` (autocomplete, texto nuevo) | `wp_insert_post()` crea el post → guarda el ID generado |
| `media` | `sanitize_text_field()` (guarda el ID numérico) |

### 4. Almacenamiento en `wp_postmeta`

```php
update_post_meta($post_id, $field_id, $valor_sanitizado);
```

Los arrays (campos múltiples) se serializan automáticamente por WordPress:
```
meta_key: galeria
meta_value: a:3:{i:0;s:3:"101";i:1;s:3:"102";i:2;s:3:"103";}
```

---

## 🏗️ Arquitectura de configuración

### Dónde vive la config activa
La fuente de verdad en runtime es **la base de datos**, no el archivo JSON:

```php
$this->fields_config = get_option('bh_fields_current', []);
```

El archivo JSON solo se lee en dos momentos:
1. Primera activación del plugin (copia JSON → BD)
2. Cuando el admin visita la página "Fields Sync" y hay cambios pendientes

### Primera activación (`initial_setup`)
```
bh-custom-fields-config.json
    → json_decode()
    → update_option('bh_fields_current', $config)
    → update_option('bh_json_hash', md5_file($json_file))
    → update_option('bh_last_sync', now)
```

### Detección de cambios (`check_json_changes`)
Corre en cada carga del admin para usuarios con `manage_options`:
```
md5_file(json) vs get_option('bh_json_hash')
    → igual: no hace nada
    → distinto: muestra notice amarilla
```

### Análisis de cambios (`analyze_changes`)
Corre solo cuando el admin visita la página de sync:
```
BD config vs JSON config
    → field_deleted   → conflict (posts afectados)
    → type_changed    → conflict (posts con datos incompatibles)
    → option_removed  → conflict si hay posts con esa opción, safe si no
    → new_field       → safe
    → label_changed   → safe
    → options_added   → safe
```

### Aplicar configuración (`ajax_apply_config`)
```
1. Verifica JSON válido
2. Re-corre analyze_changes (server-side, no confía solo en el cliente)
3. Si conflictos → error
4. Si OK:
    update_option('bh_fields_current', $json_config)
    update_option('bh_json_hash', md5_file($json_file))
    update_option('bh_last_sync', now)
    update_option('bh_fields_disabled', [])
    update_option('bh_fields_force_enabled', [])
```

---

## 🗄️ Tablas de WordPress utilizadas

**`wp_postmeta`** — datos de los campos por post:
```
meta_id | post_id | meta_key         | meta_value
--------|---------|------------------|---------------------------
1       | 42      | subtitulo        | Mi subtítulo
2       | 42      | galeria          | a:3:{i:0;s:3:"101";...}
3       | 42      | estado           | publicado
```

**`wp_options`** — configuración del plugin:
```
option_name                  | option_value
-----------------------------|------------------------------------------
bh_fields_current         | a:3:{s:4:"post";a:6:{...}}  (serializado)
bh_json_hash              | a1057212a24b773362acab81e8e855a3
bh_last_sync              | 2026-03-16 09:42:58
bh_fields_disabled        | a:0:{}  (vacío si no hay conflictos)
bh_fields_force_enabled   | a:0:{}
```

No se crean tablas adicionales. El plugin usa exclusivamente la infraestructura nativa de WordPress.

---

## 🔍 Consultas útiles

```sql
-- Ver datos de un campo en todos los posts
SELECT p.ID, p.post_title, pm.meta_value
FROM wp_posts p
JOIN wp_postmeta pm ON p.ID = pm.post_id
WHERE pm.meta_key = 'subtitulo'
AND p.post_status != 'auto-draft';

-- Ver configuración activa
SELECT option_value FROM wp_options WHERE option_name = 'bh_fields_current';

-- Ver campos desactivados
SELECT option_value FROM wp_options WHERE option_name = 'bh_fields_disabled';
```

```bash
# Ver configuración activa con WP-CLI
wp --path=/var/www/html/web/wp --allow-root option get bh_fields_current

# Ver estado de sincronización
wp --path=/var/www/html/web/wp --allow-root option get bh_json_hash
wp --path=/var/www/html/web/wp --allow-root option get bh_last_sync
```

---

## 🧪 Checklist de pruebas

### Flujo básico
- [ ] Editar JSON → notice amarilla aparece en el admin
- [ ] Ir a Fields Sync → análisis muestra los cambios correctamente
- [ ] Aplicar (sin conflictos) → notice desaparece, sync page muestra "sincronizado"

### Conflictos
- [ ] Eliminar campo del JSON → detecta `field_deleted` con conteo de posts afectados
- [ ] Cambiar type de campo → detecta `type_changed`, campo desactivado en el editor
- [ ] Eliminar opción de choice con datos → detecta `option_removed` con conteo

### Acciones de resolución
- [ ] Ver posts → muestra lista correcta
- [ ] Exportar datos → descarga CSV
- [ ] Eliminar datos (pocos posts) → funciona sin barra de progreso
- [ ] Eliminar datos (muchos posts) → batch con barra de progreso
- [ ] Forzar activación → campo se reactiva en el editor
- [ ] Ver config anterior → modal con JSON de BD
