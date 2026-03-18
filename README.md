# BH Custom Fields

Plugin de WordPress para gestión de campos personalizados mediante configuración JSON con sistema de sincronización controlada.

## Características

- Define campos personalizados por post type en un archivo JSON versionable
- La configuración activa se lee desde la base de datos (sin I/O de disco en cada request)
- Detecta automáticamente cambios en el JSON y notifica al administrador
- Sistema de sincronización controlada: los cambios no se aplican automáticamente
- Previene pérdida de datos al cambiar o eliminar campos con contenido existente
- Tipos de campo: `text` (plain/html), `bool`, `choice` (select/radio), `related`, `media`, `date`
- Soporte para campos múltiples (`"multiple": true`)
- Funciones helper para usar en templates PHP

## Requisitos

- WordPress 5.2 o superior
- PHP 7.4 o superior

## Instalación

1. Copiar la carpeta del plugin a `wp-content/plugins/` (o `web/app/plugins/` en Bedrock):

```bash
cp -r bh-custom-fields /var/www/html/web/app/plugins/
```

2. Activar el plugin desde el panel de administración o con WP-CLI:

```bash
wp plugin activate bh-custom-fields
```

3. En la primera activación, el plugin copia automáticamente el JSON a la base de datos. No requiere configuración adicional.

## Configuración

Los campos se definen en `bh-custom-fields-config.json`, organizado por post type:

```json
{
  "post": [
    { "id": "subtitulo", "label": "Subtítulo", "type": "text", "mode": "plain" },
    { "id": "imagen_destacada", "label": "Imagen destacada", "type": "media", "media_type": "image" },
    { "id": "seccion", "label": "Sección", "type": "choice", "display": "select",
      "options": { "politica": "Política", "cultura": "Cultura" } }
  ]
}
```

Ver [INSTALACION Y CONFIGURACION.md](docs/INSTALACION%20Y%20CONFIGURACION.md) para la referencia completa de tipos de campo.

## Uso en templates

```php
// Obtener valor
$subtitulo = bhack_get_custom_field('subtitulo');

// Imprimir valor
bhack_the_custom_field('subtitulo');

// Imagen
bhack_the_custom_field_media('imagen_destacada', 'large');

// Label legible de un campo choice
bhack_the_custom_field_label('seccion'); // imprime "Política" en vez de "politica"

// Post relacionado
$post = bhack_get_custom_field_related('post_relacionado');
```

## Modificar la configuración

1. Editar `bh-custom-fields-config.json`
2. Aparecerá una notificación en el panel de WordPress
3. Ir a **Fields Sync** en el menú lateral
4. Revisar los cambios detectados y resolver conflictos si los hay
5. Hacer click en **"Actualizar configuración"**

> Los cambios en el JSON no se aplican solos. El plugin siempre lee desde la base de datos.

## Desinstalación

Al desinstalar el plugin desde el panel de WordPress se eliminan automáticamente todas las opciones de configuración en la base de datos y los post meta de los campos registrados.

## Documentación

- [Instalación y configuración](docs/INSTALACION%20Y%20CONFIGURACION.md)
- [Flujo de guardado y arquitectura de datos](docs/FLUJO%20DE%20GUARDADO.md)
- [Documento de continuidad](docs/Documento%20de%20continuidad.md)

## Licencia

GPL v2 o superior.
