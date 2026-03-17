<?php
/**
 * Plugin Name: BH Custom Fields
 * Description: Gestión de campos personalizados mediante JSON para BH
 * Version: 1.1
 * Author: BH
 */

if (!defined('ABSPATH')) exit;

class BHCustomFieldsManager {
    private $fields_config = [];
    
    public function __construct() {
        add_action('init', [$this, 'load_fields_config']);
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post', [$this, 'save_meta_boxes']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'check_json_changes']);
        add_action('admin_init', [$this, 'handle_settings_save']);
        add_action('wp_ajax_bhcf_apply_config',     [$this, 'ajax_apply_config']);
        add_action('wp_ajax_bhcf_view_posts',       [$this, 'ajax_view_posts']);
        add_action('wp_ajax_bhcf_export_data',      [$this, 'ajax_export_data']);
        add_action('wp_ajax_bhcf_delete_field_data',[$this, 'ajax_delete_field_data']);
        add_action('wp_ajax_bhcf_force_enable',     [$this, 'ajax_force_enable']);
        add_action('wp_ajax_bhcf_show_old_config',  [$this, 'ajax_show_old_config']);
    }
    
    public function get_fields_config() {
        return $this->fields_config;
    }
    
    public function load_fields_config() {
        // Siempre lee de BD (consistente con los datos)
        $this->fields_config = get_option('bh_fields_current', []);

        // Si la BD está vacía, es primera instalación
        if (empty($this->fields_config)) {
            $this->initial_setup();
        }
    }

    private function initial_setup() {
        $json_file = plugin_dir_path(__FILE__) . 'bh-custom-fields-config.json';

        if (!file_exists($json_file)) {
            return;
        }

        $json_content = file_get_contents($json_file);
        $config = json_decode($json_content, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($config)) {
            update_option('bh_fields_current', $config);
            update_option('bh_json_hash', md5_file($json_file));
            update_option('bh_last_sync', current_time('mysql'));
            $this->fields_config = $config;
        }
    }

    public function handle_settings_save() {
        if (!isset($_POST['bh_fields_action']) || $_POST['bh_fields_action'] !== 'save_settings') {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }
        check_admin_referer('bh_fields_settings', 'bh_fields_settings_nonce');
        update_option('bh_fields_delete_on_uninstall', !empty($_POST['bh_delete_on_uninstall']));
        wp_redirect(admin_url('admin.php?page=bh-fields-sync&settings_saved=1'));
        exit;
    }

    public function check_json_changes() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $json_file = plugin_dir_path(__FILE__) . 'bh-custom-fields-config.json';
        clearstatcache(true, $json_file);

        if (!file_exists($json_file)) {
            return;
        }

        $current_hash = md5_file($json_file);
        $stored_hash = get_option('bh_json_hash', '');

        if ($current_hash !== $stored_hash) {
            add_action('admin_notices', [$this, 'show_sync_notice']);
        }
    }

    public function show_sync_notice() {
        $screen = get_current_screen();
        if ($screen && $screen->id === 'toplevel_page_bh-fields-sync') {
            return;
        }
        ?>
        <div class="notice notice-warning is-dismissible">
            <p>
                <strong>🟡 BH Custom Fields:</strong>
                Se detectaron cambios en la configuración de campos.
                <a href="<?php echo admin_url('admin.php?page=bh-fields-sync'); ?>"
                   class="button button-primary"
                   style="margin-left: 10px;">
                    Revisar cambios
                </a>
            </p>
        </div>
        <?php
    }

    public function add_admin_menu() {
        add_menu_page(
            'BH Fields Sync',
            'Fields Sync',
            'manage_options',
            'bh-fields-sync',
            [$this, 'render_sync_page'],
            'dashicons-update',
            80
        );
    }

    public function render_sync_page() {
        ?>
        <div class="wrap">
            <h1>BH Custom Fields - Sincronización</h1>

            <?php
            $json_file = plugin_dir_path(__FILE__) . 'bh-custom-fields-config.json';
            clearstatcache(true, $json_file);

            if (!file_exists($json_file)) {
                ?>
                <div class="notice notice-error">
                    <p><strong>⛔ Error:</strong> No se encuentra el archivo <code>bh-custom-fields-config.json</code></p>
                </div>
                <?php
                return;
            }

            $current_hash = md5_file($json_file);
            $stored_hash = get_option('bh_json_hash', '');

            if ($current_hash === $stored_hash) {
                ?>
                <div class="notice notice-success">
                    <p>✅ <strong>Configuración sincronizada</strong></p>
                    <p>No hay cambios pendientes. La configuración en la base de datos está actualizada con el archivo JSON.</p>
                </div>

                <div class="card">
                    <h2>Estado actual</h2>
                    <table class="widefat">
                        <tr>
                            <th style="width: 200px;">Hash del archivo JSON:</th>
                            <td><code><?php echo esc_html(substr($current_hash, 0, 16)); ?>...</code></td>
                        </tr>
                        <tr>
                            <th>Última sincronización:</th>
                            <td><?php echo esc_html(get_option('bh_last_sync', 'No disponible')); ?></td>
                        </tr>
                        <tr>
                            <th>Post types configurados:</th>
                            <td><?php
                                $config = get_option('bh_fields_current', []);
                                echo !empty($config) ? esc_html(implode(', ', array_keys($config))) : 'Ninguno';
                            ?></td>
                        </tr>
                    </table>
                </div>

                <p style="margin-top: 20px;">
                    <button type="button" class="button" onclick="location.reload()">
                        🔄 Verificar cambios nuevamente
                    </button>
                </p>
                <?php
            } else {
                $json_content = file_get_contents($json_file);
                $json_config  = json_decode($json_content, true);

                if (json_last_error() !== JSON_ERROR_NONE || !is_array($json_config)) {
                    ?>
                    <div class="notice notice-error">
                        <p><strong>⛔ Error en el archivo JSON:</strong>
                        <?php echo esc_html(json_last_error_msg()); ?></p>
                        <p>Corregí el archivo <code>bh-custom-fields-config.json</code> y recargá esta página.</p>
                    </div>
                    <?php
                    return;
                }

                $bd_config    = get_option('bh_fields_current', []);
                $analysis     = $this->analyze_changes($bd_config, $json_config);
                $has_conflicts = !empty($analysis['conflicts']);

                // Actualizar lista de campos desactivados según conflictos de tipo
                $disabled = array_values(array_map(
                    fn($c) => $c['field_id'],
                    array_filter($analysis['conflicts'], fn($c) => $c['type'] === 'type_changed')
                ));
                update_option('bh_fields_disabled', $disabled);
                ?>
                <div class="notice notice-warning">
                    <p><strong>⚠️ Cambios detectados</strong></p>
                    <p>El archivo JSON ha sido modificado y hay cambios pendientes de revisar.</p>
                </div>

                <?php $this->render_analysis($analysis); ?>

                <p style="margin-top: 20px;">
                    <button type="button" id="bhcf-btn-rescan" class="button">
                        🔄 Re-escanear JSON
                    </button>
                    &nbsp;
                    <button type="button" id="bhcf-btn-apply" class="button button-primary" <?php echo $has_conflicts ? 'disabled' : ''; ?>>
                        ✅ Actualizar configuración
                    </button>
                    <?php if ($has_conflicts): ?>
                        <br><small style="color: #dc3232;">Resolvé los conflictos antes de aplicar los cambios</small>
                    <?php else: ?>
                        <br><small style="color: #555;"><?php echo count($analysis['safe_changes']); ?> cambio(s) listos para aplicar</small>
                    <?php endif; ?>
                </p>
                <?php
            }
            ?>

            <hr style="margin: 40px 0;">

            <?php if (isset($_GET['settings_saved'])): ?>
            <div class="notice notice-success is-dismissible"><p>✅ Configuración guardada.</p></div>
            <?php endif; ?>

            <div class="card">
                <h2>⚙️ Configuración del plugin</h2>
                <form method="post">
                    <input type="hidden" name="bh_fields_action" value="save_settings">
                    <?php wp_nonce_field('bh_fields_settings', 'bh_fields_settings_nonce'); ?>
                    <table class="form-table" style="margin: 0;">
                        <tr>
                            <th style="width: 200px; padding: 8px 0;">Al desinstalar el plugin:</th>
                            <td style="padding: 8px 0;">
                                <label>
                                    <input type="checkbox" name="bh_delete_on_uninstall" value="1"
                                        <?php checked(get_option('bh_fields_delete_on_uninstall', false)); ?>>
                                    Eliminar todos los datos guardados en la base de datos
                                    <br><small style="color: #777; margin-top: 4px; display: block;">
                                        Si está desmarcado, al eliminar el plugin se borran solo las opciones
                                        de configuración pero se conservan los datos guardados en los posts
                                        (<code>wp_postmeta</code>).
                                    </small>
                                </label>
                            </td>
                        </tr>
                    </table>
                    <p><button type="submit" class="button">Guardar configuración</button></p>
                </form>
            </div>

            <hr style="margin: 40px 0;">

            <details>
                <summary style="cursor: pointer; font-weight: bold;">ℹ️ Información técnica</summary>
                <div style="margin-top: 15px; padding: 15px; background: #f0f0f1; border-radius: 4px;">
                    <h3>Cómo funciona la sincronización:</h3>
                    <ol>
                        <li>El plugin <strong>siempre lee la configuración desde la base de datos</strong> (opción <code>bh_fields_current</code>)</li>
                        <li>Si eres administrador, <strong>se verifica el hash MD5</strong> del archivo JSON en cada carga del admin</li>
                        <li>Si el hash cambió, aparece una <strong>notificación</strong> para revisar los cambios</li>
                        <li>En esta página puedes <strong>revisar, aprobar o rechazar</strong> los cambios antes de aplicarlos</li>
                        <li>Los cambios solo se aplican cuando haces click en <strong>"Actualizar configuración"</strong></li>
                    </ol>

                    <h3>Archivos y opciones involucradas:</h3>
                    <ul>
                        <li><code>bh-custom-fields-config.json</code> - Archivo de configuración (Git)</li>
                        <li><code>bh_fields_current</code> - Configuración activa en BD</li>
                        <li><code>bh_json_hash</code> - Hash MD5 para detectar cambios</li>
                        <li><code>bh_last_sync</code> - Fecha/hora de última sincronización</li>
                        <li><code>bh_fields_conflicts</code> - Conflictos pendientes (próximamente)</li>
                    </ul>
                </div>
            </details>
        </div>

        <style>
            .card {
                background: white;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
                padding: 20px;
                margin: 20px 0;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
            }
            .card h2 { margin-top: 0; }
            .widefat th { font-weight: 600; }
        </style>
        <?php
        $this->render_sync_page_scripts();
    }
    
    private function analyze_changes($bd_config, $json_config) {
        $safe_changes = [];
        $conflicts    = [];

        $all_post_types = array_unique(array_merge(array_keys($bd_config), array_keys($json_config)));

        foreach ($all_post_types as $post_type) {
            $bd_fields   = $bd_config[$post_type]   ?? [];
            $json_fields = $json_config[$post_type] ?? [];

            $bd_indexed   = array_column($bd_fields,   null, 'id');
            $json_indexed = array_column($json_fields, null, 'id');

            // Campos en BD: detectar eliminados, cambios de tipo, cambios de label
            $force_enabled = get_option('bh_fields_force_enabled', []);
            if (!is_array($force_enabled)) $force_enabled = [];

            foreach ($bd_indexed as $field_id => $bd_field) {
                if (!isset($json_indexed[$field_id])) {
                    $affected = $this->count_posts_with_field($field_id);
                    // Sin datos → cambio seguro (no hay nada que perder)
                    if ($affected === 0) {
                        $safe_changes[] = [
                            'type'       => 'field_deleted',
                            'post_type'  => $post_type,
                            'field_id'   => $field_id,
                            'old_config' => $bd_field,
                            'affected_posts' => 0,
                        ];
                    } else {
                        $conflicts[] = [
                            'type'           => 'field_deleted',
                            'post_type'      => $post_type,
                            'field_id'       => $field_id,
                            'old_config'     => $bd_field,
                            'affected_posts' => $affected,
                        ];
                    }
                    continue;
                }

                $json_field = $json_indexed[$field_id];

                if ($bd_field['type'] !== $json_field['type']) {
                    $affected = $this->count_posts_with_field($field_id);
                    // Sin datos o forzado por el admin → cambio seguro
                    if ($affected === 0 || in_array($field_id, $force_enabled)) {
                        $safe_changes[] = [
                            'type'       => 'type_changed',
                            'post_type'  => $post_type,
                            'field_id'   => $field_id,
                            'old_type'   => $bd_field['type'],
                            'new_type'   => $json_field['type'],
                            'old_config' => $bd_field,
                            'new_config' => $json_field,
                            'affected_posts' => $affected,
                        ];
                    } else {
                        $conflicts[] = [
                            'type'           => 'type_changed',
                            'post_type'      => $post_type,
                            'field_id'       => $field_id,
                            'old_type'       => $bd_field['type'],
                            'new_type'       => $json_field['type'],
                            'old_config'     => $bd_field,
                            'new_config'     => $json_field,
                            'affected_posts' => $affected,
                        ];
                    }
                    continue;
                }

                // Cambios en opciones de campo choice
                if ($bd_field['type'] === 'choice') {
                    $bd_options   = $bd_field['options']   ?? [];
                    $json_options = $json_field['options'] ?? [];

                    $removed_options = array_diff_key($bd_options, $json_options);
                    $added_options   = array_diff_key($json_options, $bd_options);

                    foreach ($removed_options as $option_key => $option_label) {
                        $affected = $this->count_posts_with_field_value($field_id, $option_key);
                        if ($affected > 0) {
                            $conflicts[] = [
                                'type'         => 'option_removed',
                                'post_type'    => $post_type,
                                'field_id'     => $field_id,
                                'option_key'   => $option_key,
                                'option_label' => $option_label,
                                'affected_posts' => $affected,
                                'old_config'   => $bd_field,
                                'new_config'   => $json_field,
                            ];
                        } else {
                            $safe_changes[] = [
                                'type'         => 'option_removed',
                                'post_type'    => $post_type,
                                'field_id'     => $field_id,
                                'option_key'   => $option_key,
                                'option_label' => $option_label,
                                'affected_posts' => 0,
                            ];
                        }
                    }

                    if (!empty($added_options)) {
                        $safe_changes[] = [
                            'type'          => 'options_added',
                            'post_type'     => $post_type,
                            'field_id'      => $field_id,
                            'added_options' => $added_options,
                        ];
                    }
                }

                // Cambio de label o description (seguro)
                $label_changed = $bd_field['label'] !== $json_field['label'];
                $desc_changed  = ($bd_field['description'] ?? '') !== ($json_field['description'] ?? '');

                if ($label_changed || $desc_changed) {
                    $safe_changes[] = [
                        'type'            => 'label_changed',
                        'post_type'       => $post_type,
                        'field_id'        => $field_id,
                        'old_label'       => $bd_field['label'],
                        'new_label'       => $json_field['label'],
                        'old_description' => $bd_field['description'] ?? '',
                        'new_description' => $json_field['description'] ?? '',
                    ];
                }
            }

            // Campos en JSON pero no en BD: son nuevos (seguros)
            foreach ($json_indexed as $field_id => $json_field) {
                if (!isset($bd_indexed[$field_id])) {
                    $safe_changes[] = [
                        'type'       => 'new_field',
                        'post_type'  => $post_type,
                        'field_id'   => $field_id,
                        'new_config' => $json_field,
                    ];
                }
            }
        }

        return [
            'safe_changes' => $safe_changes,
            'conflicts'    => $conflicts,
        ];
    }

    private function count_posts_with_field($field_id) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''",
            $field_id
        ));
    }

    private function count_posts_with_field_value($field_id, $value) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
            $field_id,
            $value
        ));
    }

    private function render_analysis($analysis) {
        $conflicts    = $analysis['conflicts'];
        $safe_changes = $analysis['safe_changes'];

        if (!empty($conflicts)) {
            echo '<div class="card" style="border-left: 4px solid #dc3232;">';
            echo '<h2 style="color: #dc3232;">⚠️ Conflictos (' . count($conflicts) . ')</h2>';
            echo '<p style="color: #555;">Deben resolverse todos antes de poder aplicar cualquier cambio.</p>';

            foreach ($conflicts as $c) {
                $field_id  = esc_attr($c['field_id']);
                $post_type = esc_attr($c['post_type']);

                echo '<div class="bhcf-conflict-item">';

                if ($c['type'] === 'field_deleted') {
                    $this->render_conflict_deleted($c);
                } elseif ($c['type'] === 'type_changed') {
                    $this->render_conflict_type_changed($c);
                } elseif ($c['type'] === 'option_removed') {
                    $this->render_conflict_option_removed($c);
                }

                echo '</div>';
            }

            echo '</div>';
        }

        if (!empty($safe_changes)) {
            $safe_labels = [
                'new_field'      => '🆕 Campo nuevo',
                'label_changed'  => '✏️ Label / descripción',
                'options_added'  => '➕ Opciones agregadas',
                'option_removed' => '➖ Opción eliminada (sin datos)',
            ];

            echo '<div class="card" style="border-left: 4px solid #00a32a;">';
            echo '<h2 style="color: #00a32a;">✅ Cambios seguros (' . count($safe_changes) . ')</h2>';
            echo '<p style="color: #555;">Se aplicarán automáticamente al hacer "Actualizar configuración".</p>';
            echo '<table class="widefat striped">';
            echo '<thead><tr><th>Tipo</th><th>Post type</th><th>Campo</th><th>Detalle</th></tr></thead>';
            echo '<tbody>';

            foreach ($safe_changes as $c) {
                $label = $safe_labels[$c['type']] ?? $c['type'];
                echo '<tr>';
                echo '<td>' . esc_html($label) . '</td>';
                echo '<td><code>' . esc_html($c['post_type']) . '</code></td>';
                echo '<td><code>' . esc_html($c['field_id']) . '</code></td>';

                if ($c['type'] === 'label_changed') {
                    echo '<td>"' . esc_html($c['old_label']) . '" → "' . esc_html($c['new_label']) . '"</td>';
                } elseif ($c['type'] === 'options_added') {
                    echo '<td>' . esc_html(implode(', ', array_keys($c['added_options']))) . '</td>';
                } elseif ($c['type'] === 'new_field') {
                    $type = $c['new_config']['type'] ?? '?';
                    echo '<td>Tipo: <code>' . esc_html($type) . '</code></td>';
                } else {
                    echo '<td>—</td>';
                }

                echo '</tr>';
            }

            echo '</tbody></table>';
            echo '</div>';
        }

        if (empty($conflicts) && empty($safe_changes)) {
            echo '<div class="card"><p>No se detectaron cambios en los campos.</p></div>';
        }
    }

    private function render_conflict_deleted($c) {
        $field_id  = esc_attr($c['field_id']);
        $post_type = esc_attr($c['post_type']);
        $affected  = (int) $c['affected_posts'];
        $old_type  = esc_html($c['old_config']['type'] ?? '?');
        $old_label = esc_html($c['old_config']['label'] ?? $c['field_id']);
        ?>
        <div class="bhcf-conflict-box" style="border: 1px solid #dc3232; padding: 15px; margin: 10px 0; border-radius: 3px; background: #fff8f8;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 10px;">
                <div>
                    <strong style="color: #dc3232;">Campo eliminado:</strong>
                    <code><?php echo esc_html($c['field_id']); ?></code>
                    <span style="color: #777;">(<?php echo $old_label; ?>, tipo: <code><?php echo $old_type; ?></code>, post type: <code><?php echo esc_html($c['post_type']); ?></code>)</span>
                    <br>
                    <span style="color: #dc3232; font-weight: bold;">📊 <?php echo $affected; ?> post<?php echo $affected !== 1 ? 's' : ''; ?> con datos</span>
                </div>
                <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                    <button type="button"
                        class="button bhcf-btn-view-posts"
                        data-field-id="<?php echo $field_id; ?>"
                        data-post-type="<?php echo $post_type; ?>">
                        🔍 Ver posts
                    </button>
                    <button type="button"
                        class="button bhcf-btn-export"
                        data-field-id="<?php echo $field_id; ?>"
                        data-post-type="<?php echo $post_type; ?>">
                        📥 Exportar datos
                    </button>
                    <button type="button"
                        class="button button-link-delete bhcf-btn-delete-data"
                        data-field-id="<?php echo $field_id; ?>"
                        data-post-type="<?php echo $post_type; ?>"
                        data-affected="<?php echo $affected; ?>"
                        style="color: #dc3232;">
                        🗑️ Eliminar datos
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_conflict_type_changed($c) {
        $field_id  = esc_attr($c['field_id']);
        $post_type = esc_attr($c['post_type']);
        $affected  = (int) $c['affected_posts'];
        $old_label = esc_html($c['old_config']['label'] ?? $c['field_id']);
        ?>
        <div class="bhcf-conflict-box" style="border: 1px solid #dba617; padding: 15px; margin: 10px 0; border-radius: 3px; background: #fffbf0;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 10px;">
                <div>
                    <strong style="color: #dba617;">Cambio de tipo:</strong>
                    <code><?php echo esc_html($c['field_id']); ?></code>
                    <span style="color: #777;">(<?php echo $old_label; ?>, post type: <code><?php echo esc_html($c['post_type']); ?></code>)</span>
                    <br>
                    <code><?php echo esc_html($c['old_type']); ?></code>
                    <strong> → </strong>
                    <code><?php echo esc_html($c['new_type']); ?></code>
                    &nbsp;·&nbsp;
                    <span style="color: #dc3232; font-weight: bold;">📊 <?php echo $affected; ?> post<?php echo $affected !== 1 ? 's' : ''; ?> con datos incompatibles</span>
                    <br>
                    <small style="color: #777;">El campo está desactivado hasta que se resuelva este conflicto.</small>
                </div>
                <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                    <button type="button"
                        class="button bhcf-btn-show-old-config"
                        data-field-id="<?php echo $field_id; ?>"
                        data-post-type="<?php echo $post_type; ?>">
                        📋 Ver config anterior
                    </button>
                    <button type="button"
                        class="button bhcf-btn-export"
                        data-field-id="<?php echo $field_id; ?>"
                        data-post-type="<?php echo $post_type; ?>">
                        📥 Exportar datos
                    </button>
                    <button type="button"
                        class="button bhcf-btn-force-enable"
                        data-field-id="<?php echo $field_id; ?>"
                        data-post-type="<?php echo $post_type; ?>">
                        ⚡ Forzar activación
                    </button>
                    <button type="button"
                        class="button button-link-delete bhcf-btn-delete-data"
                        data-field-id="<?php echo $field_id; ?>"
                        data-post-type="<?php echo $post_type; ?>"
                        data-affected="<?php echo $affected; ?>"
                        style="color: #dc3232;">
                        🗑️ Eliminar datos
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_conflict_option_removed($c) {
        $field_id  = esc_attr($c['field_id']);
        $post_type = esc_attr($c['post_type']);
        $affected  = (int) $c['affected_posts'];
        ?>
        <div class="bhcf-conflict-box" style="border: 1px solid #dba617; padding: 15px; margin: 10px 0; border-radius: 3px; background: #fffbf0;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 10px;">
                <div>
                    <strong style="color: #dba617;">Opción eliminada:</strong>
                    <code><?php echo esc_html($c['field_id']); ?></code>
                    →
                    opción <code><?php echo esc_html($c['option_key']); ?></code>
                    <span style="color: #777;">("<?php echo esc_html($c['option_label']); ?>", post type: <code><?php echo esc_html($c['post_type']); ?></code>)</span>
                    <br>
                    <span style="color: #dc3232; font-weight: bold;">📊 <?php echo $affected; ?> post<?php echo $affected !== 1 ? 's' : ''; ?> usan esta opción</span>
                </div>
                <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                    <button type="button"
                        class="button bhcf-btn-view-posts"
                        data-field-id="<?php echo $field_id; ?>"
                        data-post-type="<?php echo $post_type; ?>"
                        data-option-key="<?php echo esc_attr($c['option_key']); ?>">
                        🔍 Ver posts
                    </button>
                    <button type="button"
                        class="button button-link-delete bhcf-btn-delete-data"
                        data-field-id="<?php echo $field_id; ?>"
                        data-post-type="<?php echo $post_type; ?>"
                        data-affected="<?php echo $affected; ?>"
                        style="color: #dc3232;">
                        🗑️ Eliminar datos
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // AJAX handlers
    // -------------------------------------------------------------------------

    public function ajax_apply_config() {
        check_ajax_referer('bhcf_sync', 'nonce');
        if (!current_user_can('manage_options')) wp_die();

        $json_file = plugin_dir_path(__FILE__) . 'bh-custom-fields-config.json';

        if (!file_exists($json_file)) {
            wp_send_json_error(['message' => 'Archivo JSON no encontrado.']);
        }

        $json_content = file_get_contents($json_file);
        $json_config  = json_decode($json_content, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($json_config)) {
            wp_send_json_error(['message' => 'El archivo JSON no es válido.']);
        }

        // Verificar que no haya conflictos antes de aplicar
        $bd_config = get_option('bh_fields_current', []);
        $analysis  = $this->analyze_changes($bd_config, $json_config);

        if (!empty($analysis['conflicts'])) {
            wp_send_json_error(['message' => 'Hay conflictos sin resolver. No se puede aplicar la configuración.']);
        }

        update_option('bh_fields_current', $json_config);
        update_option('bh_json_hash', md5_file($json_file));
        update_option('bh_last_sync', current_time('mysql'));
        update_option('bh_fields_disabled', []);
        update_option('bh_fields_force_enabled', []);

        wp_send_json_success(['message' => 'Configuración actualizada correctamente.']);
    }

    public function ajax_view_posts() {
        check_ajax_referer('bhcf_sync', 'nonce');
        if (!current_user_can('manage_options')) wp_die();

        $field_id = sanitize_key($_POST['field_id']);

        global $wpdb;
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, pm.meta_value
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE pm.meta_key = %s AND pm.meta_value != '' AND p.post_status != 'auto-draft'
             ORDER BY p.post_title
             LIMIT 50",
            $field_id
        ));

        $posts = [];
        foreach ($results as $row) {
            $value = is_serialized($row->meta_value)
                ? wp_json_encode(maybe_unserialize($row->meta_value))
                : $row->meta_value;
            $posts[] = [
                'id'    => $row->ID,
                'title' => $row->post_title,
                'url'   => get_edit_post_link($row->ID, 'raw'),
                'value' => $value,
            ];
        }

        wp_send_json_success(['posts' => $posts]);
    }

    public function ajax_export_data() {
        check_ajax_referer('bhcf_sync', 'nonce');
        if (!current_user_can('manage_options')) wp_die();

        $field_id = sanitize_key($_POST['field_id']);

        global $wpdb;
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_type, pm.meta_value
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE pm.meta_key = %s AND pm.meta_value != '' AND p.post_status != 'auto-draft'
             ORDER BY p.ID",
            $field_id
        ));

        $csv = "ID,Titulo,Post Type,Valor\n";
        foreach ($results as $row) {
            $value = is_serialized($row->meta_value)
                ? wp_json_encode(maybe_unserialize($row->meta_value))
                : $row->meta_value;
            $csv .= '"' . $row->ID . '",'
                  . '"' . str_replace('"', '""', $row->post_title) . '",'
                  . '"' . $row->post_type . '",'
                  . '"' . str_replace('"', '""', $value) . '"' . "\n";
        }

        wp_send_json_success([
            'csv'      => $csv,
            'filename' => $field_id . '_export.csv',
        ]);
    }

    public function ajax_delete_field_data() {
        check_ajax_referer('bhcf_sync', 'nonce');
        if (!current_user_can('manage_options')) wp_die();

        $field_id   = sanitize_key($_POST['field_id']);
        $batch_size = 50;

        global $wpdb;

        $post_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = %s AND meta_value != ''
             LIMIT %d",
            $field_id,
            $batch_size
        ));

        $deleted = 0;
        foreach ($post_ids as $post_id) {
            delete_post_meta((int) $post_id, $field_id);
            $deleted++;
        }

        $remaining = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta}
             WHERE meta_key = %s AND meta_value != ''",
            $field_id
        ));

        wp_send_json_success([
            'deleted'   => $deleted,
            'remaining' => $remaining,
            'done'      => $remaining === 0,
        ]);
    }

    public function ajax_force_enable() {
        check_ajax_referer('bhcf_sync', 'nonce');
        if (!current_user_can('manage_options')) wp_die();

        $field_id = sanitize_key($_POST['field_id']);

        // Remover de la lista de desactivados
        $disabled = get_option('bh_fields_disabled', []);
        $disabled = array_values(array_filter($disabled, fn($id) => $id !== $field_id));
        update_option('bh_fields_disabled', $disabled);

        // Registrar como forzado (para auditoría)
        $force_enabled = get_option('bh_fields_force_enabled', []);
        if (!in_array($field_id, $force_enabled)) {
            $force_enabled[] = $field_id;
            update_option('bh_fields_force_enabled', $force_enabled);
        }

        wp_send_json_success(['message' => "Campo '{$field_id}' forzado a activo."]);
    }

    public function ajax_show_old_config() {
        check_ajax_referer('bhcf_sync', 'nonce');
        if (!current_user_can('manage_options')) wp_die();

        $field_id  = sanitize_key($_POST['field_id']);
        $post_type = sanitize_key($_POST['post_type']);

        $bd_config    = get_option('bh_fields_current', []);
        $fields       = $bd_config[$post_type] ?? [];
        $field_config = null;

        foreach ($fields as $field) {
            if ($field['id'] === $field_id) {
                $field_config = $field;
                break;
            }
        }

        if (!$field_config) {
            wp_send_json_error(['message' => 'Campo no encontrado en BD.']);
        }

        wp_send_json_success(['config' => $field_config]);
    }

    // -------------------------------------------------------------------------
    // Scripts y modal para la página de sync
    // -------------------------------------------------------------------------

    private function render_sync_page_scripts() {
        $nonce   = wp_create_nonce('bhcf_sync');
        $ajaxurl = admin_url('admin-ajax.php');
        ?>
        <div id="bhcf-modal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.6); z-index:9999; justify-content:center; align-items:flex-start; padding-top:60px;">
            <div style="background:white; border-radius:4px; padding:30px; max-width:720px; width:90%; max-height:75vh; overflow-y:auto; position:relative; box-shadow: 0 4px 20px rgba(0,0,0,0.3);">
                <button type="button" id="bhcf-modal-close" style="position:absolute; top:12px; right:15px; background:none; border:none; font-size:20px; cursor:pointer; color:#555;">✕</button>
                <h3 id="bhcf-modal-title" style="margin-top:0;"></h3>
                <div id="bhcf-modal-content"></div>
            </div>
        </div>

        <script>
        var bhcf = {
            ajaxurl: '<?php echo esc_js($ajaxurl); ?>',
            nonce:   '<?php echo esc_js($nonce); ?>'
        };

        jQuery(document).ready(function($) {

            // --- Modal helpers ---
            function showModal(title, content) {
                $('#bhcf-modal-title').text(title);
                $('#bhcf-modal-content').html(content);
                $('#bhcf-modal').css('display', 'flex');
            }
            function closeModal() {
                $('#bhcf-modal').hide();
                $('#bhcf-modal-content').html('');
            }
            $('#bhcf-modal-close').on('click', closeModal);
            $('#bhcf-modal').on('click', function(e) {
                if ($(e.target).is('#bhcf-modal')) closeModal();
            });

            // --- Ver posts ---
            $(document).on('click', '.bhcf-btn-view-posts', function() {
                var btn      = $(this);
                var fieldId  = btn.data('field-id');
                btn.prop('disabled', true).text('Cargando...');

                $.post(bhcf.ajaxurl, {
                    action:    'bhcf_view_posts',
                    nonce:     bhcf.nonce,
                    field_id:  fieldId,
                    post_type: btn.data('post-type')
                }, function(r) {
                    btn.prop('disabled', false).text('🔍 Ver posts');
                    if (!r.success) { alert('Error: ' + r.data.message); return; }

                    var posts = r.data.posts;
                    if (!posts.length) {
                        showModal('Posts con campo "' + fieldId + '"', '<p>No se encontraron posts.</p>');
                        return;
                    }

                    var note = posts.length >= 50 ? ' <small>(mostrando primeros 50)</small>' : '';
                    var html = '<p><strong>' + posts.length + '</strong> posts encontrados' + note + ':</p>';
                    html += '<table class="widefat striped"><thead><tr><th>ID</th><th>Título</th><th>Valor guardado</th></tr></thead><tbody>';
                    $.each(posts, function(i, p) {
                        var val = p.value.length > 80 ? p.value.substring(0, 80) + '…' : p.value;
                        html += '<tr>'
                              + '<td>' + p.id + '</td>'
                              + '<td><a href="' + p.url + '" target="_blank">' + $('<span>').text(p.title).html() + '</a></td>'
                              + '<td><code>' + $('<span>').text(val).html() + '</code></td>'
                              + '</tr>';
                    });
                    html += '</tbody></table>';
                    showModal('Posts con campo "' + fieldId + '"', html);
                });
            });

            // --- Exportar datos ---
            $(document).on('click', '.bhcf-btn-export', function() {
                var btn     = $(this);
                var fieldId = btn.data('field-id');
                btn.prop('disabled', true).text('Exportando...');

                $.post(bhcf.ajaxurl, {
                    action:    'bhcf_export_data',
                    nonce:     bhcf.nonce,
                    field_id:  fieldId,
                    post_type: btn.data('post-type')
                }, function(r) {
                    btn.prop('disabled', false).text('📥 Exportar datos');
                    if (!r.success) { alert('Error al exportar.'); return; }

                    var blob = new Blob([r.data.csv], { type: 'text/csv' });
                    var url  = URL.createObjectURL(blob);
                    var a    = document.createElement('a');
                    a.href     = url;
                    a.download = r.data.filename;
                    a.click();
                    URL.revokeObjectURL(url);
                });
            });

            // --- Eliminar datos ---
            $(document).on('click', '.bhcf-btn-delete-data', function() {
                var btn      = $(this);
                var fieldId  = btn.data('field-id');
                var affected = parseInt(btn.data('affected'));

                var html = '<p>Estás por eliminar los datos del campo <code>' + $('<span>').text(fieldId).html() + '</code>'
                         + ' de <strong>' + affected + ' posts</strong>.</p>'
                         + '<p><strong>Esta acción no se puede deshacer.</strong> Considerá exportar los datos primero.</p>'
                         + '<p>Para confirmar, escribí <strong>ELIMINAR</strong>:</p>'
                         + '<input type="text" id="bhcf-confirm-input" class="regular-text" placeholder="ELIMINAR">'
                         + '<div style="margin-top:15px;">'
                         +   '<button type="button" class="button button-primary" id="bhcf-confirm-delete" data-field-id="' + $('<span>').text(fieldId).html() + '">Confirmar eliminación</button>'
                         +   ' <button type="button" class="button" id="bhcf-cancel-delete">Cancelar</button>'
                         + '</div>'
                         + '<div id="bhcf-delete-progress" style="display:none; margin-top:20px;">'
                         +   '<div style="background:#eee; border-radius:3px; overflow:hidden; height:20px;">'
                         +     '<div id="bhcf-progress-bar" style="height:20px; background:#dc3232; width:0; transition:width 0.3s;"></div>'
                         +   '</div>'
                         +   '<p id="bhcf-progress-text" style="margin-top:8px; color:#555;"></p>'
                         + '</div>';

                showModal('Eliminar datos del campo "' + fieldId + '"', html);

                $('#bhcf-cancel-delete').on('click', closeModal);

                $('#bhcf-confirm-delete').on('click', function() {
                    if ($('#bhcf-confirm-input').val() !== 'ELIMINAR') {
                        $('#bhcf-confirm-input').css('border-color', '#dc3232').focus();
                        return;
                    }

                    $('#bhcf-confirm-input, #bhcf-confirm-delete, #bhcf-cancel-delete').prop('disabled', true);
                    $('#bhcf-delete-progress').show();

                    var total   = affected;
                    var deleted = 0;

                    function deleteBatch() {
                        $.post(bhcf.ajaxurl, {
                            action:   'bhcf_delete_field_data',
                            nonce:    bhcf.nonce,
                            field_id: fieldId
                        }, function(r) {
                            if (!r.success) {
                                $('#bhcf-progress-text').text('Error al eliminar.').css('color', '#dc3232');
                                return;
                            }
                            deleted += r.data.deleted;
                            var pct  = total > 0 ? Math.min(100, Math.round(deleted / total * 100)) : 100;
                            $('#bhcf-progress-bar').css('width', pct + '%');
                            $('#bhcf-progress-text').text('Eliminando... ' + deleted + ' / ' + total);

                            if (r.data.done) {
                                $('#bhcf-progress-text').text('✅ Listo. ' + deleted + ' posts procesados.').css('color', '#00a32a');
                                setTimeout(function() { location.reload(); }, 1500);
                            } else {
                                deleteBatch();
                            }
                        });
                    }
                    deleteBatch();
                });
            });

            // --- Forzar activación ---
            $(document).on('click', '.bhcf-btn-force-enable', function() {
                var btn     = $(this);
                var fieldId = btn.data('field-id');

                if (!confirm('¿Forzar activación del campo "' + fieldId + '"?\n\nSe mostrará en el editor aunque haya un conflicto de tipo. Los datos existentes pueden no renderizarse correctamente.')) {
                    return;
                }

                btn.prop('disabled', true).text('Procesando...');
                $.post(bhcf.ajaxurl, {
                    action:   'bhcf_force_enable',
                    nonce:    bhcf.nonce,
                    field_id: fieldId
                }, function(r) {
                    if (r.success) {
                        location.reload();
                    } else {
                        btn.prop('disabled', false).text('⚡ Forzar activación');
                        alert('Error: ' + r.data.message);
                    }
                });
            });

            // --- Ver config anterior ---
            $(document).on('click', '.bhcf-btn-show-old-config', function() {
                var btn      = $(this);
                var fieldId  = btn.data('field-id');
                var postType = btn.data('post-type');
                btn.prop('disabled', true).text('Cargando...');

                $.post(bhcf.ajaxurl, {
                    action:    'bhcf_show_old_config',
                    nonce:     bhcf.nonce,
                    field_id:  fieldId,
                    post_type: postType
                }, function(r) {
                    btn.prop('disabled', false).text('📋 Ver config anterior');
                    if (!r.success) { alert('Error: ' + r.data.message); return; }

                    var html = '<pre style="background:#f0f0f1; padding:15px; border-radius:3px; overflow:auto; font-size:13px;">'
                             + $('<span>').text(JSON.stringify(r.data.config, null, 2)).html()
                             + '</pre>';
                    showModal('Configuración anterior de "' + fieldId + '"', html);
                });
            });

            // --- Re-escanear JSON ---
            $('#bhcf-btn-rescan').on('click', function() {
                $(this).prop('disabled', true).text('Escaneando...');
                location.reload();
            });

            // --- Actualizar configuración ---
            $('#bhcf-btn-apply').on('click', function() {
                var btn = $(this);
                if (!confirm('¿Aplicar la nueva configuración?\n\nLos cambios seguros se guardarán en la base de datos y la configuración quedará sincronizada.')) {
                    return;
                }

                btn.prop('disabled', true).text('Aplicando...');

                $.post(bhcf.ajaxurl, {
                    action: 'bhcf_apply_config',
                    nonce:  bhcf.nonce
                }, function(r) {
                    if (r.success) {
                        btn.text('✅ ¡Listo!').css('background', '#00a32a');
                        setTimeout(function() { location.reload(); }, 1000);
                    } else {
                        btn.prop('disabled', false).text('✅ Actualizar configuración');
                        alert('Error: ' + r.data.message);
                    }
                });
            });

        });
        </script>
        <?php
    }

    public function add_meta_boxes() {
        if (empty($this->fields_config)) return;
        
        foreach ($this->fields_config as $post_type => $fields) {
            add_meta_box(
                'bh_custom_fields_' . $post_type,
                'Campos Personalizados',
                [$this, 'render_meta_box'],
                $post_type,
                'normal',
                'high',
                ['fields' => $fields]
            );
        }
    }
    
    public function render_meta_box($post, $metabox) {
        wp_nonce_field('bh_custom_fields_save', 'bh_custom_fields_nonce');
        $fields          = $metabox['args']['fields'];
        $disabled_fields = get_option('bh_fields_disabled', []);
        $sync_url        = admin_url('admin.php?page=bh-fields-sync');

        echo '<div class="bh-custom-fields-container">';

        foreach ($fields as $field) {
            if (in_array($field['id'], $disabled_fields)) {
                echo '<div class="notice notice-warning inline" style="margin: 8px 0; padding: 8px 12px;">';
                echo '<p style="margin: 0;">';
                echo '⚠️ <strong>' . esc_html($field['label']) . '</strong> está desactivado. ';
                echo 'El tipo de este campo cambió en el JSON. ';
                echo '<a href="' . esc_url($sync_url) . '">Resolver en Sincronización →</a>';
                echo '</p>';
                echo '</div>';
                continue;
            }

            $multiple = $field['multiple'] ?? false;

            if ($multiple) {
                $value = get_post_meta($post->ID, $field['id'], true);
                if (!is_array($value)) $value = [];
                $this->render_multiple_field($field, $value, $post->ID);
            } else {
                $value = get_post_meta($post->ID, $field['id'], true);
                $this->render_field($field, $value, $post->ID);
            }
        }

        echo '</div>';
    }
    
    private function render_multiple_field($field, $values, $post_id) {
        $max_items = $field['max_items'] ?? 10;
        $field_id = $field['id'];
        
        echo '<div class="custom-field-multiple-wrapper" data-field-id="' . esc_attr($field_id) . '" data-max-items="' . esc_attr($max_items) . '" style="margin-bottom: 30px; padding: 15px; border: 1px solid #ddd; background: #f9f9f9;">';
        echo '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">';
        echo '<label style="font-weight: bold; font-size: 14px; margin: 0;">' . esc_html($field['label']) . '</label>';
        echo '<button type="button" class="button button-primary add-multiple-item" data-field-type="' . esc_attr($field['type']) . '">+ Agregar</button>';
        echo '</div>';
        
        if (!empty($field['description'])) {
            echo '<p class="description" style="margin-top: 0;">' . esc_html($field['description']) . '</p>';
        }
        
        echo '<div class="multiple-items-container" style="margin-top: 10px;">';
        
        if (!empty($values)) {
            foreach ($values as $index => $value) {
                $this->render_multiple_item($field, $value, $index);
            }
        }
        
        echo '</div>';
        echo '<p class="items-counter" style="margin-top: 10px; color: #666; font-size: 12px;">Items: <span class="current-count">' . count($values) . '</span> / ' . $max_items . '</p>';
        echo '</div>';
        
        // Template para nuevos items
        echo '<script type="text/template" id="template-' . esc_attr($field_id) . '">';
        $this->render_multiple_item($field, '', '{{INDEX}}');
        echo '</script>';
    }
    
    private function render_multiple_item($field, $value, $index) {
        $field_id = $field['id'];
        $item_class = is_numeric($index) ? 'multiple-item' : 'multiple-item-template';
        
        echo '<div class="' . $item_class . '" style="margin-bottom: 15px; padding: 15px; background: white; border: 1px solid #ddd; position: relative;">';
        echo '<button type="button" class="button remove-multiple-item" style="position: absolute; top: 10px; right: 10px;">✕</button>';
        
        $single_field = $field;
        $single_field['id'] = $field_id . '[' . $index . ']';
        
        switch ($field['type']) {
            case 'text':
                $this->render_text_field($single_field, $value, true);
                break;
            case 'bool':
                $this->render_bool_field($single_field, $value, true);
                break;
            case 'choice':
                $this->render_choice_field($single_field, $value, true);
                break;
            case 'related':
                $this->render_related_field($single_field, $value, true);
                break;
            case 'media':
                $this->render_media_field($single_field, $value, true);
                break;
        }
        
        echo '</div>';
    }
    
    private function render_field($field, $value, $post_id) {
        echo '<div class="custom-field-wrapper" style="margin-bottom: 20px;">';
        echo '<label style="display: block; font-weight: bold; margin-bottom: 5px;">';
        echo esc_html($field['label']);
        echo '</label>';
        
        switch ($field['type']) {
            case 'text':
                $this->render_text_field($field, $value);
                break;
            case 'bool':
                $this->render_bool_field($field, $value);
                break;
            case 'choice':
                $this->render_choice_field($field, $value);
                break;
            case 'related':
                $this->render_related_field($field, $value);
                break;
            case 'media':
                $this->render_media_field($field, $value);
                break;
        }
        
        if (!empty($field['description'])) {
            echo '<p class="description">' . esc_html($field['description']) . '</p>';
        }
        
        echo '</div>';
    }
    
    private function render_text_field($field, $value, $is_multiple = false) {
        $mode = $field['mode'] ?? 'plain';
        $name = esc_attr($field['id']);
        $label = $is_multiple ? '' : '';
        
        if ($mode === 'html') {
            if ($is_multiple) {
                echo '<textarea name="' . $name . '" rows="5" style="width: 100%;">' . esc_textarea($value) . '</textarea>';
            } else {
                wp_editor($value, str_replace(['[', ']'], '_', $name), [
                    'textarea_name' => $name,
                    'textarea_rows' => 10,
                    'media_buttons' => false,
                ]);
            }
        } else {
            echo '<textarea name="' . $name . '" rows="3" style="width: 100%;">';
            echo esc_textarea($value);
            echo '</textarea>';
        }
    }
    
    private function render_bool_field($field, $value, $is_multiple = false) {
        $name = esc_attr($field['id']);
        $checked = $value ? 'checked' : '';
        $label_text = $field['checkbox_label'] ?? 'Activado';
        
        echo '<label style="display: flex; align-items: center; gap: 8px;">';
        echo '<input type="checkbox" name="' . $name . '" value="1" ' . $checked . '>';
        echo '<span>' . esc_html($label_text) . '</span>';
        echo '</label>';
    }
    
    private function render_choice_field($field, $value, $is_multiple = false) {
        $name = esc_attr($field['id']);
        $options = $field['options'] ?? [];
        $display = $field['display'] ?? 'select';
        $allow_multiple = $field['allow_multiple'] ?? false;
        
        if (empty($options)) {
            echo '<p style="color: #dc3232;">⚠️ No hay opciones definidas para este campo</p>';
            return;
        }
        
        if ($display === 'radio') {
            echo '<div class="choice-field-radio" style="display: flex; flex-direction: column; gap: 8px;">';
            
            foreach ($options as $key => $label) {
                $checked = ($value === $key) ? 'checked' : '';
                $input_id = $name . '_' . $key;
                
                echo '<label style="display: flex; align-items: center; gap: 8px;">';
                echo '<input type="radio" name="' . $name . '" id="' . esc_attr($input_id) . '" value="' . esc_attr($key) . '" ' . $checked . '>';
                echo '<span>' . esc_html($label) . '</span>';
                echo '</label>';
            }
            
            echo '</div>';
        } else {
            if ($allow_multiple && $is_multiple) {
                echo '<select name="' . $name . '[]" multiple style="width: 100%; height: 120px;">';
            } else {
                echo '<select name="' . $name . '" style="width: 100%;">';
                echo '<option value="">-- Seleccionar --</option>';
            }
            
            foreach ($options as $key => $label) {
                if ($allow_multiple && is_array($value)) {
                    $selected = in_array($key, $value) ? 'selected' : '';
                } else {
                    $selected = selected($value, $key, false);
                }
                
                echo '<option value="' . esc_attr($key) . '" ' . $selected . '>';
                echo esc_html($label);
                echo '</option>';
            }
            
            echo '</select>';
        }
    }
    
    private function render_related_field($field, $value, $is_multiple = false) {
        $related_type = $field['related_type'] ?? 'post';
        $name = esc_attr($field['id']);
        
        $args = [
            'post_type' => $related_type,
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ];
        
        $posts = get_posts($args);
        
        echo '<select name="' . $name . '" style="width: 100%;">';
        echo '<option value="">-- Seleccionar --</option>';
        
        foreach ($posts as $post) {
            $selected = selected($value, $post->ID, false);
            echo '<option value="' . esc_attr($post->ID) . '" ' . $selected . '>';
            echo esc_html($post->post_title);
            echo '</option>';
        }
        
        echo '</select>';
    }
    
    private function render_media_field($field, $value, $is_multiple = false) {
        $name = esc_attr($field['id']);
        $media_type = $field['media_type'] ?? 'image';
        $media_url = '';
        $preview_html = '';
        
        if ($value) {
            $media_url = wp_get_attachment_url($value);
            $mime_type = get_post_mime_type($value);
            
            if ($media_type === 'image' && strpos($mime_type, 'image') !== false) {
                $preview_html = '<img src="' . esc_url($media_url) . '" style="max-width: 200px; height: auto; display: block;">';
            } elseif ($media_type === 'video' && strpos($mime_type, 'video') !== false) {
                $preview_html = '<video controls style="max-width: 200px;"><source src="' . esc_url($media_url) . '" type="' . esc_attr($mime_type) . '"></video>';
            } elseif ($media_type === 'document') {
                $filename = basename($media_url);
                $preview_html = '<p>📄 ' . esc_html($filename) . '</p>';
            }
        }
        
        $button_text = [
            'image' => 'Seleccionar Imagen',
            'video' => 'Seleccionar Video',
            'document' => 'Seleccionar Documento'
        ];
        
        echo '<div class="media-field-wrapper" data-media-type="' . esc_attr($media_type) . '">';
        echo '<input type="hidden" name="' . $name . '" class="media-field-id" value="' . esc_attr($value) . '">';
        
        echo '<div class="media-preview" style="margin-bottom: 10px;">';
        echo $preview_html;
        echo '</div>';
        
        echo '<button type="button" class="button media-upload-button">' . esc_html($button_text[$media_type] ?? 'Seleccionar Archivo') . '</button> ';
        echo '<button type="button" class="button media-remove-button" style="' . ($value ? '' : 'display:none;') . '">Eliminar</button>';
        echo '</div>';
    }
    
    public function save_meta_boxes($post_id) {
        if (!isset($_POST['bh_custom_fields_nonce']) || 
            !wp_verify_nonce($_POST['bh_custom_fields_nonce'], 'bh_custom_fields_save')) {
            return;
        }
        
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        
        $post_type = get_post_type($post_id);
        
        if (isset($this->fields_config[$post_type])) {
            foreach ($this->fields_config[$post_type] as $field) {
                $field_id = $field['id'];
                $is_multiple = $field['multiple'] ?? false;
                
                if (isset($_POST[$field_id])) {
                    if ($is_multiple) {
                        $values = $_POST[$field_id];
                        $cleaned_values = [];
                        
                        foreach ($values as $value) {
                            if (!empty($value) || $value === '0') {
                                if ($field['type'] === 'text' && ($field['mode'] ?? 'plain') === 'html') {
                                    $cleaned_values[] = wp_kses_post($value);
                                } elseif ($field['type'] === 'bool') {
                                    $cleaned_values[] = (bool) $value;
                                } elseif ($field['type'] === 'choice') {
                                    $cleaned_values[] = sanitize_text_field($value);
                                } else {
                                    $cleaned_values[] = sanitize_text_field($value);
                                }
                            }
                        }
                        
                        update_post_meta($post_id, $field_id, $cleaned_values);
                    } else {
                        $value = $_POST[$field_id];
                        
                        if ($field['type'] === 'text' && ($field['mode'] ?? 'plain') === 'html') {
                            $value = wp_kses_post($value);
                        } elseif ($field['type'] === 'bool') {
                            $value = (bool) $value;
                        } elseif ($field['type'] === 'choice') {
                            if (is_array($value)) {
                                $value = array_map('sanitize_text_field', $value);
                            } else {
                                $value = sanitize_text_field($value);
                            }
                        } else {
                            $value = sanitize_text_field($value);
                        }
                        
                        update_post_meta($post_id, $field_id, $value);
                    }
                } else {
                    if ($field['type'] === 'bool' && !$is_multiple) {
                        update_post_meta($post_id, $field_id, false);
                    } else {
                        delete_post_meta($post_id, $field_id);
                    }
                }
            }
        }
    }
    
    public function enqueue_scripts($hook) {
        if ('post.php' !== $hook && 'post-new.php' !== $hook) return;
        
        wp_enqueue_media();
        
        wp_add_inline_script('jquery', "
            jQuery(document).ready(function($) {
                var mediaUploader;
                
                var mimeTypes = {
                    'image': {
                        title: 'Seleccionar Imagen',
                        mimes: ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml']
                    },
                    'video': {
                        title: 'Seleccionar Video',
                        mimes: ['video/mp4', 'video/webm', 'video/ogg', 'video/avi', 'video/quicktime']
                    },
                    'document': {
                        title: 'Seleccionar Documento',
                        mimes: ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'text/plain']
                    }
                };
                
                $(document).on('click', '.media-upload-button', function(e) {
                    e.preventDefault();
                    var button = $(this);
                    var wrapper = button.closest('.media-field-wrapper');
                    var idField = wrapper.find('.media-field-id');
                    var preview = wrapper.find('.media-preview');
                    var removeBtn = wrapper.find('.media-remove-button');
                    var mediaType = wrapper.data('media-type') || 'image';
                    var config = mimeTypes[mediaType] || mimeTypes['image'];
                    
                    mediaUploader = wp.media({
                        title: config.title,
                        button: { text: 'Usar este archivo' },
                        multiple: false,
                        library: {
                            type: config.mimes
                        }
                    });
                    
                    mediaUploader.on('select', function() {
                        var attachment = mediaUploader.state().get('selection').first().toJSON();
                        idField.val(attachment.id);
                        
                        var previewHtml = '';
                        if (mediaType === 'image' && attachment.type === 'image') {
                            previewHtml = '<img src=\"' + attachment.url + '\" style=\"max-width: 200px; height: auto; display: block;\">';
                        } else if (mediaType === 'video' && attachment.type === 'video') {
                            previewHtml = '<video controls style=\"max-width: 200px;\"><source src=\"' + attachment.url + '\" type=\"' + attachment.mime + '\"></video>';
                        } else if (mediaType === 'document') {
                            previewHtml = '<p>📄 ' + attachment.filename + '</p>';
                        }
                        
                        preview.html(previewHtml);
                        removeBtn.show();
                    });
                    
                    mediaUploader.open();
                });
                
                $(document).on('click', '.media-remove-button', function(e) {
                    e.preventDefault();
                    var button = $(this);
                    var wrapper = button.closest('.media-field-wrapper');
                    wrapper.find('.media-field-id').val('');
                    wrapper.find('.media-preview').html('');
                    button.hide();
                });
                
                $(document).on('click', '.add-multiple-item', function(e) {
                    e.preventDefault();
                    var button = $(this);
                    var container = button.closest('.custom-field-multiple-wrapper');
                    var fieldId = container.data('field-id');
                    var maxItems = container.data('max-items');
                    var itemsContainer = container.find('.multiple-items-container');
                    var currentCount = itemsContainer.find('.multiple-item').length;
                    var counter = container.find('.current-count');
                    
                    if (currentCount >= maxItems) {
                        alert('Has alcanzado el máximo de ' + maxItems + ' items permitidos.');
                        return;
                    }
                    
                    var template = $('#template-' + fieldId).html();
                    var newIndex = currentCount;
                    var newItem = template.replace(/\{\{INDEX\}\}/g, newIndex);
                    
                    itemsContainer.append(newItem);
                    counter.text(currentCount + 1);
                    
                    if (currentCount + 1 >= maxItems) {
                        button.prop('disabled', true);
                    }
                });
                
                $(document).on('click', '.remove-multiple-item', function(e) {
                    e.preventDefault();
                    var button = $(this);
                    var item = button.closest('.multiple-item');
                    var container = item.closest('.custom-field-multiple-wrapper');
                    var itemsContainer = container.find('.multiple-items-container');
                    var addButton = container.find('.add-multiple-item');
                    var counter = container.find('.current-count');
                    
                    item.remove();
                    
                    itemsContainer.find('.multiple-item').each(function(index) {
                        $(this).find('input, select, textarea').each(function() {
                            var name = $(this).attr('name');
                            if (name) {
                                var baseName = name.replace(/\[\d+\]/, '');
                                $(this).attr('name', baseName + '[' + index + ']');
                            }
                        });
                    });
                    
                    var newCount = itemsContainer.find('.multiple-item').length;
                    counter.text(newCount);
                    addButton.prop('disabled', false);
                });
            });
        ");
    }
}

// Inicializar el plugin
new BHCustomFieldsManager();

// Funciones helper para usar en templates
function bhack_get_custom_field($field_id, $post_id = null) {
    if (!$post_id) {
        $post_id = get_the_ID();
    }
    return get_post_meta($post_id, $field_id, true);
}

function bhack_the_custom_field($field_id, $post_id = null) {
    echo bhack_get_custom_field($field_id, $post_id);
}

function bhack_get_custom_field_related($field_id, $post_id = null, $index = null) {
    $value = bhack_get_custom_field($field_id, $post_id);
    
    if (is_array($value)) {
        if ($index !== null && isset($value[$index])) {
            return get_post($value[$index]);
        }
        $related = [];
        foreach ($value as $id) {
            if ($id) $related[] = get_post($id);
        }
        return $related;
    }
    
    if ($value) {
        return get_post($value);
    }
    return null;
}

function bhack_get_custom_field_media_url($field_id, $size = 'full', $post_id = null, $index = null) {
    $value = bhack_get_custom_field($field_id, $post_id);
    
    if (is_array($value)) {
        if ($index !== null && isset($value[$index])) {
            return wp_get_attachment_image_url($value[$index], $size);
        }
        $urls = [];
        foreach ($value as $id) {
            if ($id) $urls[] = wp_get_attachment_image_url($id, $size);
        }
        return $urls;
    }
    
    if ($value) {
        return wp_get_attachment_image_url($value, $size);
    }
    return '';
}

function bhack_the_custom_field_media($field_id, $size = 'full', $post_id = null, $attr = [], $index = null) {
    $value = bhack_get_custom_field($field_id, $post_id);
    
    if (is_array($value)) {
        if ($index !== null && isset($value[$index])) {
            echo wp_get_attachment_image($value[$index], $size, false, $attr);
        } else {
            foreach ($value as $id) {
                if ($id) echo wp_get_attachment_image($id, $size, false, $attr);
            }
        }
    } elseif ($value) {
        echo wp_get_attachment_image($value, $size, false, $attr);
    }
}

function bhack_get_custom_field_label($field_id, $post_id = null) {
    $value = bhack_get_custom_field($field_id, $post_id);
    
    if (empty($value)) {
        return '';
    }
    
    $manager = new BHCustomFieldsManager();
    $manager->load_fields_config();
    
    $post_type = get_post_type($post_id ?: get_the_ID());
    $fields_config = $manager->get_fields_config();
    
    if (!isset($fields_config[$post_type])) {
        return $value;
    }
    
    $field_config = null;
    foreach ($fields_config[$post_type] as $field) {
        if ($field['id'] === $field_id) {
            $field_config = $field;
            break;
        }
    }
    
    if (!$field_config || $field_config['type'] !== 'choice') {
        return $value;
    }
    
    $options = $field_config['options'] ?? [];
    
    if (is_array($value)) {
        $labels = [];
        foreach ($value as $key) {
            if (isset($options[$key])) {
                $labels[] = $options[$key];
            } else {
                $labels[] = "⚠️ $key (opción eliminada)";
            }
        }
        return $labels;
    }
    
    if (isset($options[$value])) {
        return $options[$value];
    }
    
    return "⚠️ $value (opción eliminada)";
}

function bhack_the_custom_field_label($field_id, $post_id = null) {
    $label = bhack_get_custom_field_label($field_id, $post_id);
    
    if (is_array($label)) {
        echo implode(', ', $label);
    } else {
        echo $label;
    }
}
// Hook de desinstalación
register_uninstall_hook(__FILE__, 'bh_custom_fields_uninstall');

function bh_custom_fields_uninstall() {
    global $wpdb;

    // Eliminar datos de posts solo si el usuario lo eligió explícitamente
    if (get_option('bh_fields_delete_on_uninstall', false)) {
        $config = get_option('bh_fields_current', []);
        foreach ($config as $post_type => $fields) {
            foreach ($fields as $field) {
                if (!empty($field['id'])) {
                    $wpdb->delete($wpdb->postmeta, ['meta_key' => $field['id']]);
                }
            }
        }
    }

    // Eliminar todas las opciones del plugin
    $options = [
        'bh_fields_current',
        'bh_json_hash',
        'bh_last_sync',
        'bh_fields_disabled',
        'bh_fields_conflicts',
        'bh_fields_force_enabled',
        'bh_fields_delete_on_uninstall',
    ];
    foreach ($options as $option) {
        delete_option($option);
    }
}
