# BH Custom Fields - Documentación de Continuidad

## 📋 Información del Proyecto

**Plugin:** BH Custom Fields
**Archivo principal:** `bh_custom_fields.php`
**Versión:** 1.1 (sistema de sincronización completo)
**Estado:** Implementado y activo en producción
**Última actualización:** Marzo 2026

---

## 🎯 Problema que resuelve

El plugin lee la configuración de campos desde un archivo JSON. Cuando ese JSON se modifica (cambio de tipo, eliminación de campos, etc.), los datos existentes en posts pueden:
- Quedar huérfanos (campo eliminado pero datos siguen en BD)
- Intentar renderizarse con tipo incorrecto (campo que era `text` ahora es `media`)
- Perderse silenciosamente sin advertencia al usuario

La solución es un **sistema de sincronización controlada** con interfaz de administración.

---

## 🏗️ Arquitectura

```
MODO OPERACIÓN (normal)
Request → Lee BD (bh_fields_current) → Renderiza campos
(Sin I/O de disco, sin parsing JSON)

MODO SINCRONIZACIÓN (admin detecta cambios en JSON)
1. Parsea JSON
2. Compara con BD → analyze_changes()
3. Detecta conflictos y cambios seguros
4. Muestra UI de resolución con acciones por conflicto
5. Admin aprueba → Actualiza BD → Notice desaparece
```

**Ventajas:**
- ✅ Performance: BD es 10-50x más rápida que leer+parsear JSON
- ✅ Control: cambios no se aplican automáticamente
- ✅ Seguridad: detecta y previene pérdida de datos
- ✅ Reversible: se puede revertir antes de aplicar

---

## 🔑 Conceptos clave

### Detección de cambios
- Hash MD5 del archivo JSON guardado en BD (`bh_json_hash`)
- En cada carga del admin, se recalcula y compara
- Si difieren → notice amarilla + análisis al entrar a la página de sync

### Tipos de cambios

| Tipo | Criticidad | Acción |
|------|------------|--------|
| Campo nuevo | ✅ Seguro | Se aplica con "Actualizar configuración" |
| Cambio de label/description | ✅ Seguro | Se aplica con "Actualizar configuración" |
| Opciones agregadas (choice) | ✅ Seguro | Se aplica con "Actualizar configuración" |
| Cambio de otras propiedades (display, mode, default, etc.) | ✅ Seguro | Se aplica con "Actualizar configuración" |
| Campo eliminado | ⚠️ Conflicto | Advertir, ofrecer eliminar datos |
| Cambio de tipo | ⚠️ Conflicto | Campo desactivado hasta resolver |
| Opción eliminada con datos | ⚠️ Conflicto | Advertir, ofrecer eliminar datos |

### Criterio "todo o nada"
Si hay **cualquier conflicto**: el botón "Actualizar configuración" permanece deshabilitado. El admin debe resolver todos los conflictos primero.

### Opciones de BD utilizadas

| Opción | Contenido |
|--------|-----------|
| `bh_fields_current` | Config activa (array PHP serializado) |
| `bh_json_hash` | MD5 del archivo JSON |
| `bh_last_sync` | Timestamp de última sincronización |
| `bh_fields_disabled` | IDs de campos desactivados por type_changed |
| `bhcf_fields_force_enabled` | IDs de campos forzados activos (auditoría) |

---

## 📐 Implementación completada

### ✅ Paso 7: Desinstalación limpia
- `register_uninstall_hook()` registrado en el plugin
- `bh_custom_fields_uninstall()` elimina todas las opciones de BD al desinstalar
- Lee `bh_fields_current` antes de borrarlo para eliminar también los post meta de cada campo registrado
- Opciones eliminadas: `bh_fields_current`, `bh_json_hash`, `bh_last_sync`, `bh_fields_disabled`, `bh_fields_conflicts`, `bhcf_fields_force_enabled`

---

## 📐 Implementación completada

### ✅ Paso 1: Infraestructura base
- `load_fields_config()` lee de BD en vez de JSON
- `initial_setup()` copia JSON → BD en primera activación
- `check_json_changes()` detecta cambios vía hash MD5
- Notice amarilla en el admin con link a la página de sync
- Página "Fields Sync" con estado sincronizado/pendiente

### ✅ Paso 2: Análisis de cambios
- `analyze_changes($bd_config, $json_config)` compara campo por campo
- `count_posts_with_field($field_id)` cuenta posts afectados
- `count_posts_with_field_value($field_id, $value)` para opciones de choice
- Retorna `safe_changes` y `conflicts` separados

### ✅ Paso 3: UI de conflictos
- `render_analysis($analysis)` muestra análisis en la página de sync
- `render_conflict_deleted($c)` — card roja con botones de acción
- `render_conflict_type_changed($c)` — card amarilla con botones de acción
- `render_conflict_option_removed($c)` — card amarilla con botones de acción
- Tabla de cambios seguros en verde

### ✅ Paso 4: Resolución de conflictos (AJAX)
- `ajax_view_posts()` — lista posts afectados (hasta 50)
- `ajax_export_data()` — descarga CSV con datos del campo
- `ajax_delete_field_data()` — elimina datos en lotes de 50, con barra de progreso
- `ajax_force_enable()` — reactiva campo desactivado (lo quita de `bh_fields_disabled`)
- `ajax_show_old_config()` — muestra config anterior desde BD en modal

### ✅ Paso 5: Botones funcionales
- **Re-escanear JSON**: recarga la página (re-corre el análisis con estado actual del JSON)
- **Actualizar configuración** (`ajax_apply_config()`):
  - Verifica que no haya conflictos (doble check server-side)
  - Copia JSON a `bh_fields_current`
  - Actualiza hash y timestamp
  - Vacía `bh_fields_disabled` y `bhcf_fields_force_enabled`

### ✅ Paso 6: Desactivación de campos conflictivos
- Al visitar la página de sync: los campos con `type_changed` se guardan en `bh_fields_disabled`
- `render_meta_box()` comprueba esta lista antes de renderizar cada campo
- Campo desactivado muestra aviso inline con link a la página de sync
- Se reactiva al hacer "Forzar activación" o al aplicar configuración

---

## 📂 Archivos del proyecto

```
bh_custom_fields.php             ← Todo el código del plugin
bh-custom-fields-config.json    ← Configuración de campos (versionar con Git)
docs/
├── Documento de continuidad.md
├── FLUJO DE GUARDADO.md
└── INSTALACION Y CONFIGURACION.md
```

---

## 🔄 Flujo completo de sincronización

```
1. Admin edita bh-custom-fields-config.json
        ↓
2. En la próxima carga del admin:
   check_json_changes() detecta hash distinto
   → Notice amarilla: "Se detectaron cambios"
        ↓
3. Admin hace click en "Revisar cambios"
   → analyze_changes() compara JSON vs BD
   → Campos con type_changed → guardados en bh_fields_disabled
   → UI muestra conflictos (rojo) y cambios seguros (verde)
        ↓
4. En el editor de posts:
   → Campos en bh_fields_disabled muestran aviso
   → Campos normales siguen funcionando
        ↓
5. Admin resuelve conflictos:
   → [Eliminar datos] → batch delete → campo queda sin datos
   → [Forzar activación] → campo sale de bh_fields_disabled
        ↓
6. "Actualizar configuración" se habilita (0 conflictos)
   Admin hace click
   → JSON copiado a BD
   → Hash actualizado → Notice desaparece
   → bh_fields_disabled vaciado
   → Editor vuelve a mostrar todos los campos
```

---

## 🧪 Testing

### Flujo básico:
- [ ] Editar JSON → notice aparece en admin
- [ ] Ir a Fields Sync → análisis muestra cambios correctos
- [ ] Aplicar (sin conflictos) → notice desaparece, sync page muestra "sincronizado"

### Conflictos:
- [ ] Eliminar campo del JSON → detecta field_deleted con conteo de posts
- [ ] Cambiar type de campo → detecta type_changed, campo desactivado en editor
- [ ] Eliminar opción de choice con datos → detecta option_removed con conteo

### Acciones:
- [ ] Ver posts → muestra lista correcta
- [ ] Exportar datos → descarga CSV
- [ ] Eliminar datos (pocos posts) → funciona
- [ ] Eliminar datos (muchos posts) → batch con barra de progreso
- [ ] Forzar activación → campo reactiva en editor
- [ ] Ver config anterior → modal con JSON de BD

---

## 🚨 Decisiones de diseño

| Decisión | Razón |
|----------|-------|
| Criterio "todo o nada" | Evita estados intermedios inconsistentes |
| Config completa en BD (no solo hash) | Permite mostrar "config anterior" y es rápido |
| Desactivar campos con type_changed | Previene errores de renderizado |
| Sin migraciones automáticas | Simplicidad; usuarios son técnicos |
| Prefijo `bhack_` en helpers | Evita conflictos con otros plugins |
| Lotes de 50 en delete | Evita timeouts en sitios con muchos posts |

---

## 💬 Contexto de usuario

- Usuarios técnicos (1-5 desarrolladores/admins)
- Proyecto: migración ikiwiki → WordPress (De Frente al Futuro)
- Accesos en `~/accesos.md`
- WP-CLI: `wp --path=/var/www/html/web/wp --allow-root <comando>`
