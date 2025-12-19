<?php
/**
 * Plugin Name: CO360 Audio Analytics
 * Description: Recuento de reproducciones y tiempo escuchado de elementos <audio>, con informes, filtros, exportación y detalle por audio.
 * Version: 1.6.0
 * Author: CO360
 */

if (!defined('ABSPATH')) exit;

class CO360_Audio_Analytics {

    const DB_TOT        = 'co360_audio_totals';
    const DB_SES        = 'co360_audio_sessions';
    const DB_META       = 'co360_audio_meta';
    const NONCE_ACTION  = 'co360_audio_event';
    const MENU_SLUG     = 'co360-audio-analytics';

    public function __construct() {
        register_activation_hook(__FILE__, [$this, 'activate']);

        // Frontend tracking
        add_action('wp_enqueue_scripts',        [$this, 'enqueue_front']);
        add_action('wp_ajax_co360_audio_event',        [$this, 'handle_event']);
        add_action('wp_ajax_nopriv_co360_audio_event', [$this, 'handle_event']);

        // Admin
        add_action('admin_menu',            [$this, 'admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'admin_enqueue']);
        add_action('admin_notices',         [$this, 'render_admin_notices']);
        add_action('admin_post_co360_delete_all_stats',     [$this, 'handle_delete_all_stats']);
        add_action('admin_post_co360_delete_stats_by_date', [$this, 'handle_delete_stats_by_date']);
        add_action('admin_post_co360_delete_stats_by_audio',[$this, 'handle_delete_stats_by_audio']);

        // Export CSV/Excel backend
        add_action('admin_init',            [$this, 'maybe_export_admin']);

        // Shortcodes + export frontend
        add_action('init',                 [$this, 'register_shortcodes']);
        add_action('template_redirect',    [$this, 'maybe_export_front']);
    }

    /*=====================================================
     * ACTIVATION
     *====================================================*/

    public function activate() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $totals   = $wpdb->prefix . self::DB_TOT;
        $sessions = $wpdb->prefix . self::DB_SES;
        $meta     = $wpdb->prefix . self::DB_META;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql1 = "CREATE TABLE IF NOT EXISTS `$totals` (
            `audio_id` varchar(191) NOT NULL,
            `date` date NOT NULL,
            `plays` int unsigned NOT NULL DEFAULT 0,
            `unique_plays` int unsigned NOT NULL DEFAULT 0,
            `seconds` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`audio_id`,`date`)
        ) $charset;";

        $sql2 = "CREATE TABLE IF NOT EXISTS `$sessions` (
            `audio_id` varchar(191) NOT NULL,
            `session_id` varchar(64) NOT NULL,
            `date` date NOT NULL,
            PRIMARY KEY (`audio_id`,`session_id`,`date`)
        ) $charset;";

        $sql3 = "CREATE TABLE IF NOT EXISTS `$meta` (
            `audio_id` varchar(191) NOT NULL,
            `title` varchar(255) DEFAULT NULL,
            `duration` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`audio_id`)
        ) $charset;";

        dbDelta($sql1);
        dbDelta($sql2);
        dbDelta($sql3);
    }

    /*=====================================================
     * FRONTEND TRACKING
     *====================================================*/

    public function enqueue_front() {
        $handle = 'co360-audio-analytics';

        wp_register_script(
            $handle,
            plugins_url('public.js', __FILE__), // JS de tracking
            [],
            '1.1.0',
            true
        );

        wp_localize_script($handle, 'CO360AUDIO', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce(self::NONCE_ACTION),
            'user_id'  => get_current_user_id(),
        ]);

        wp_enqueue_script($handle);
    }

    public function handle_event() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $audio_id   = sanitize_text_field($_POST['audio_id'] ?? '');
        $event      = sanitize_text_field($_POST['event'] ?? '');
        $delta      = intval($_POST['delta'] ?? 0);
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        $title      = sanitize_text_field($_POST['title'] ?? '');
        $duration   = intval($_POST['duration'] ?? 0);

        if (!$audio_id || !$event || !$session_id) {
            wp_send_json_error(['msg' => 'Parámetros incompletos'], 400);
        }

        global $wpdb;
        $totals   = $wpdb->prefix . self::DB_TOT;
        $sessions = $wpdb->prefix . self::DB_SES;
        $meta     = $wpdb->prefix . self::DB_META;
        $today    = current_time('Y-m-d');

        // Metadatos
        if ($event === 'meta') {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT 1 FROM $meta WHERE audio_id=%s LIMIT 1",
                $audio_id
            ));

            if (!$exists) {
                $wpdb->insert($meta, [
                    'audio_id' => $audio_id,
                    'title'    => $title ?: $audio_id,
                    'duration' => max(0, $duration),
                ], ['%s','%s','%d']);
            } else {
                $row = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $meta WHERE audio_id=%s LIMIT 1",
                    $audio_id
                ));
                if ($row) {
                    $new_title    = $row->title;
                    $new_duration = (int) $row->duration;

                    if ($title && $title !== $row->title) {
                        $new_title = $title;
                    }
                    if ($duration > 0 && (int)$row->duration === 0) {
                        $new_duration = $duration;
                    }

                    if ($new_title !== $row->title || $new_duration !== (int)$row->duration) {
                        $wpdb->update(
                            $meta,
                            ['title' => $new_title, 'duration' => $new_duration],
                            ['audio_id' => $audio_id],
                            ['%s','%d'],
                            ['%s']
                        );
                    }
                }
            }

            wp_send_json_success(['ok' => true, 'meta' => true]);
        }

        // Asegurar fila en totals
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM $totals WHERE audio_id=%s AND date=%s LIMIT 1",
            $audio_id, $today
        ));
        if (!$exists) {
            $wpdb->insert($totals, [
                'audio_id'      => $audio_id,
                'date'          => $today,
                'plays'         => 0,
                'unique_plays'  => 0,
                'seconds'       => 0,
            ], ['%s','%s','%d','%d','%d']);
        }

        // Play
        if ($event === 'play') {
            $wpdb->query($wpdb->prepare(
                "UPDATE $totals SET plays = plays + 1 WHERE audio_id=%s AND date=%s",
                $audio_id, $today
            ));

            $ins = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO $sessions (audio_id, session_id, date) VALUES (%s,%s,%s)",
                $audio_id, $session_id, $today
            ));
            if ($ins) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE $totals SET unique_plays = unique_plays + 1 WHERE audio_id=%s AND date=%s",
                    $audio_id, $today
                ));
            }
        }

        // Tiempo escuchado
        if ($delta > 0) {
            $wpdb->query($wpdb->prepare(
                "UPDATE $totals SET seconds = seconds + %d WHERE audio_id=%s AND date=%s",
                $delta, $audio_id, $today
            ));
        }

        wp_send_json_success(['ok' => true]);
    }

    /*=====================================================
     * ADMIN
     *====================================================*/

    public function admin_menu() {
        add_menu_page(
            __('Audio Analytics','co360'),
            __('Audio Analytics','co360'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render_admin_page'],
            'dashicons-format-audio',
            70
        );
    }

    public function admin_enqueue($hook) {
        if ($hook !== 'toplevel_page_' . self::MENU_SLUG) return;

        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js',
            [],
            '4.4.0',
            true
        );

        wp_enqueue_script(
            'co360-audio-analytics-admin',
            plugins_url('admin.js', __FILE__),
            ['chartjs'],
            '1.0.4',
            true
        );

        $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
        $date_to   = isset($_GET['date_to'])   ? sanitize_text_field($_GET['date_to'])   : '';
        $audio_id  = isset($_GET['audio_id'])  ? sanitize_text_field($_GET['audio_id'])  : '';

        $rows = $this->get_stats($date_from, $date_to);

        $data = [];
        foreach ($rows as $r) {
            $plays   = (int) $r->plays;
            $seconds = (int) $r->seconds;
            $avg     = $plays > 0 ? $seconds / $plays : 0;

            $data[] = [
                'audio_id'      => $r->audio_id,
                'title'         => $r->title,
                'duration'      => (int) $r->duration,
                'plays'         => $plays,
                'unique_plays'  => (int) $r->unique_plays,
                'seconds'       => $seconds,
                'avg'           => $avg,
            ];
        }

        $detail = null;
        if ($audio_id) {
            $meta   = $this->get_audio_meta($audio_id);
            $daily  = $this->get_audio_daily_stats($audio_id, $date_from, $date_to);
            $items  = [];

            foreach ($daily as $d) {
                $items[] = [
                    'date'         => $d->date,
                    'plays'        => (int) $d->plays,
                    'unique_plays' => (int) $d->unique_plays,
                    'seconds'      => (int) $d->seconds,
                ];
            }

            $detail = [
                'audio_id' => $audio_id,
                'title'    => $meta->title,
                'items'    => $items,
            ];
        }

        wp_localize_script('co360-audio-analytics-admin', 'CO360AUDIOADMIN', [
            'items'     => $data,
            'date_from' => $date_from,
            'date_to'   => $date_to,
            'detail'    => $detail,
        ]);
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) return;

        $audio_id  = isset($_GET['audio_id'])  ? sanitize_text_field($_GET['audio_id'])  : '';
        $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
        $date_to   = isset($_GET['date_to'])   ? sanitize_text_field($_GET['date_to'])   : '';

        if ($audio_id) {
            $this->render_audio_detail_page($audio_id, $date_from, $date_to);
            return;
        }

        $rows = $this->get_stats($date_from, $date_to);

        $base_args = ['page' => self::MENU_SLUG];
        if ($date_from) $base_args['date_from'] = $date_from;
        if ($date_to)   $base_args['date_to']   = $date_to;

        $csv_url   = add_query_arg($base_args + ['co360_export' => 'csv'],   admin_url('admin.php'));
        $excel_url = add_query_arg($base_args + ['co360_export' => 'excel'], admin_url('admin.php'));
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Informe de Audio Analytics', 'co360'); ?></h1>

            <p><?php esc_html_e('Resumen de reproducciones y tiempo escuchado por archivo de audio.', 'co360'); ?></p>

            <h2><?php esc_html_e('Filtros', 'co360'); ?></h2>

            <form method="get" style="margin-bottom:15px;">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::MENU_SLUG); ?>" />

                <label style="margin-right:10px;">
                    <?php esc_html_e('Desde', 'co360'); ?>:
                    <input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>" />
                </label>

                <label style="margin-right:10px;">
                    <?php esc_html_e('Hasta', 'co360'); ?>:
                    <input type="date" name="date_to" value="<?php echo esc_attr($date_to); ?>" />
                </label>

                <button type="submit" class="button button-primary">
                    <?php esc_html_e('Aplicar filtros', 'co360'); ?>
                </button>

                <?php if ($date_from || $date_to): ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)); ?>" class="button">
                        <?php esc_html_e('Quitar filtros', 'co360'); ?>
                    </a>
                <?php endif; ?>
            </form>

            <p>
                <?php esc_html_e('Exportar datos:', 'co360'); ?>
                <a href="<?php echo esc_url($csv_url); ?>" class="button">
                    <?php esc_html_e('Exportar CSV', 'co360'); ?>
                </a>
                <a href="<?php echo esc_url($excel_url); ?>" class="button">
                    <?php esc_html_e('Exportar Excel', 'co360'); ?>
                </a>
            </p>

            <h2><?php esc_html_e('Tabla de audios', 'co360'); ?></h2>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Título del audio', 'co360'); ?></th>
                        <th><?php esc_html_e('ID de audio', 'co360'); ?></th>
                        <th><?php esc_html_e('Duración (H:M:S)', 'co360'); ?></th>
                        <th><?php esc_html_e('Reproducciones', 'co360'); ?></th>
                        <th><?php esc_html_e('Reproducciones únicas', 'co360'); ?></th>
                        <th><?php esc_html_e('Tiempo total reproducido (H:M:S)', 'co360'); ?></th>
                        <th><?php esc_html_e('Media por reproducción (H:M:S)', 'co360'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php
                if ($rows) {
                    foreach ($rows as $r) {
                        $plays    = (int) $r->plays;
                        $uplays   = (int) $r->unique_plays;
                        $seconds  = (int) $r->seconds;
                        $avg      = $plays > 0 ? (int) round($seconds / $plays) : 0;

                        $dur_hms   = $r->duration ? gmdate('H:i:s', (int)$r->duration) : '-';
                        $total_hms = $seconds ? gmdate('H:i:s', $seconds) : '-';
                        $avg_hms   = $avg ? gmdate('H:i:s', $avg) : '-';

                        $detail_url = add_query_arg(
                            [
                                'page'     => self::MENU_SLUG,
                                'audio_id' => $r->audio_id,
                            ],
                            admin_url('admin.php')
                        );

                        echo '<tr>';
                        echo '<td><a href="' . esc_url($detail_url) . '">' . esc_html($r->title) . '</a></td>';
                        echo '<td><code>' . esc_html($r->audio_id) . '</code></td>';
                        echo '<td>' . esc_html($dur_hms) . '</td>';
                        echo '<td>' . esc_html($plays) . '</td>';
                        echo '<td>' . esc_html($uplays) . '</td>';
                        echo '<td>' . esc_html($total_hms) . '</td>';
                        echo '<td>' . esc_html($avg_hms) . '</td>';
                        echo '</tr>';
                    }
                } else {
                    echo '<tr><td colspan="7">' . esc_html__('No hay datos para el rango seleccionado.', 'co360') . '</td></tr>';
                }
                ?>
                </tbody>
            </table>

            <hr />

            <h2><?php esc_html_e('Gráfica por audio', 'co360'); ?></h2>
            <p><?php esc_html_e('Comparativa de número de reproducciones y tiempo total reproducido.', 'co360'); ?></p>

            <canvas id="co360-audio-chart" style="max-width: 900px; max-height: 500px;"></canvas>

            <?php $this->render_data_cleanup_section(); ?>
        </div>
        <?php
    }

    private function render_data_cleanup_section() {
        if (!current_user_can('manage_options')) return;

        $audios      = $this->get_all_audio_meta();
        $admin_post  = admin_url('admin-post.php');
        $warning_css = 'background:#fef3f2;border:1px solid #c1351d;padding:16px;max-width:900px;margin-top:30px;';
        ?>
        <div style="<?php echo esc_attr($warning_css); ?>">
            <h2 style="margin-top:0; color:#c1351d;">
                <?php esc_html_e('Gestión de estadísticas / Limpieza de datos', 'co360'); ?>
            </h2>
            <p><?php esc_html_e('Acciones sensibles: solo afecta a estadísticas, no a los metadatos de los audios.', 'co360'); ?></p>

            <hr />

            <h3 style="color:#c1351d;">
                <?php esc_html_e('Opción A — Borrado completo', 'co360'); ?>
            </h3>
            <form method="post" action="<?php echo esc_url($admin_post); ?>" onsubmit="return confirm('<?php echo esc_js(__('¿Seguro que quieres borrar todas las estadísticas?', 'co360')); ?>');">
                <?php wp_nonce_field('co360_delete_all_stats'); ?>
                <input type="hidden" name="action" value="co360_delete_all_stats" />
                <p>
                    <button type="submit" class="button button-secondary" style="border-color:#c1351d; color:#c1351d;">
                        <?php esc_html_e('Borrar todas las estadísticas', 'co360'); ?>
                    </button>
                </p>
            </form>

            <hr />

            <h3 style="color:#c1351d;">
                <?php esc_html_e('Opción B — Borrado por rango de fechas', 'co360'); ?>
            </h3>
            <form method="post" action="<?php echo esc_url($admin_post); ?>" onsubmit="return confirm('<?php echo esc_js(__('¿Seguro que quieres borrar las estadísticas del rango indicado?', 'co360')); ?>');" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
                <?php wp_nonce_field('co360_delete_stats_by_date'); ?>
                <input type="hidden" name="action" value="co360_delete_stats_by_date" />

                <label>
                    <?php esc_html_e('Desde (YYYY-MM-DD)', 'co360'); ?><br />
                    <input type="date" name="date_from" required />
                </label>

                <label>
                    <?php esc_html_e('Hasta (YYYY-MM-DD)', 'co360'); ?><br />
                    <input type="date" name="date_to" required />
                </label>

                <button type="submit" class="button">
                    <?php esc_html_e('Borrar estadísticas del periodo', 'co360'); ?>
                </button>
            </form>

            <hr />

            <h3 style="color:#c1351d;">
                <?php esc_html_e('Opción C — Borrado por audio', 'co360'); ?>
            </h3>
            <form method="post" action="<?php echo esc_url($admin_post); ?>" onsubmit="return confirm('<?php echo esc_js(__('¿Seguro que quieres borrar las estadísticas del audio seleccionado?', 'co360')); ?>');" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
                <?php wp_nonce_field('co360_delete_stats_by_audio'); ?>
                <input type="hidden" name="action" value="co360_delete_stats_by_audio" />

                <label>
                    <?php esc_html_e('Selecciona un audio', 'co360'); ?><br />
                    <select name="audio_id" required>
                        <option value=""><?php esc_html_e('Elige un audio', 'co360'); ?></option>
                        <?php foreach ($audios as $audio): ?>
                            <option value="<?php echo esc_attr($audio->audio_id); ?>">
                                <?php echo esc_html($audio->title . ' (' . $audio->audio_id . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <button type="submit" class="button">
                    <?php esc_html_e('Borrar estadísticas de este audio', 'co360'); ?>
                </button>
            </form>
        </div>
        <?php
    }

    private function render_audio_detail_page($audio_id, $date_from = '', $date_to = '') {
        if (!current_user_can('manage_options')) return;

        $meta  = $this->get_audio_meta($audio_id);
        $rows  = $this->get_audio_daily_stats($audio_id, $date_from, $date_to);

        $total_plays   = 0;
        $total_uplays  = 0;
        $total_seconds = 0;

        foreach ($rows as $r) {
            $total_plays   += (int) $r->plays;
            $total_uplays  += (int) $r->unique_plays;
            $total_seconds += (int) $r->seconds;
        }

        $avg = $total_plays > 0 ? (int) round($total_seconds / $total_plays) : 0;

        $dur_hms   = $meta->duration ? gmdate('H:i:s', (int) $meta->duration) : '-';
        $total_hms = $total_seconds ? gmdate('H:i:s', $total_seconds) : '-';
        $avg_hms   = $avg ? gmdate('H:i:s', $avg) : '-';

        $back_url = admin_url('admin.php?page=' . self::MENU_SLUG);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Detalle de audio', 'co360'); ?></h1>

            <p>
                <a href="<?php echo esc_url($back_url); ?>">← <?php esc_html_e('Volver al listado general', 'co360'); ?></a>
            </p>

            <h2><?php echo esc_html($meta->title); ?></h2>
            <p>
                <strong><?php esc_html_e('ID de audio:', 'co360'); ?></strong>
                <code><?php echo esc_html($meta->audio_id); ?></code>
            </p>

            <h3><?php esc_html_e('Filtros', 'co360'); ?></h3>
            <form method="get" style="margin-bottom:15px;">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::MENU_SLUG); ?>" />
                <input type="hidden" name="audio_id" value="<?php echo esc_attr($audio_id); ?>" />

                <label style="margin-right:10px;">
                    <?php esc_html_e('Desde', 'co360'); ?>:
                    <input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>" />
                </label>

                <label style="margin-right:10px;">
                    <?php esc_html_e('Hasta', 'co360'); ?>:
                    <input type="date" name="date_to" value="<?php echo esc_attr($date_to); ?>" />
                </label>

                <button type="submit" class="button button-primary">
                    <?php esc_html_e('Aplicar filtros', 'co360'); ?>
                </button>

                <?php if ($date_from || $date_to): ?>
                    <a href="<?php echo esc_url(add_query_arg(['page' => self::MENU_SLUG, 'audio_id' => $audio_id], admin_url('admin.php'))); ?>" class="button">
                        <?php esc_html_e('Quitar filtros', 'co360'); ?>
                    </a>
                <?php endif; ?>
            </form>

            <h3><?php esc_html_e('Resumen', 'co360'); ?></h3>
            <table class="widefat striped" style="max-width:650px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Duración (H:M:S)', 'co360'); ?></th>
                        <th><?php esc_html_e('Reproducciones', 'co360'); ?></th>
                        <th><?php esc_html_e('Reproducciones únicas', 'co360'); ?></th>
                        <th><?php esc_html_e('Tiempo total (H:M:S)', 'co360'); ?></th>
                        <th><?php esc_html_e('Media por reproducción (H:M:S)', 'co360'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?php echo esc_html($dur_hms); ?></td>
                        <td><?php echo esc_html($total_plays); ?></td>
                        <td><?php echo esc_html($total_uplays); ?></td>
                        <td><?php echo esc_html($total_hms); ?></td>
                        <td><?php echo esc_html($avg_hms); ?></td>
                    </tr>
                </tbody>
            </table>

            <hr />

            <h3><?php esc_html_e('Evolución por fecha', 'co360'); ?></h3>
            <p><?php esc_html_e('Gráfica diaria de reproducciones y tiempo total escuchado.', 'co360'); ?></p>

            <canvas id="co360-audio-detail-chart" style="max-width: 900px; max-height: 500px;"></canvas>

            <h3 style="margin-top:30px;"><?php esc_html_e('Detalle diario', 'co360'); ?></h3>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Fecha', 'co360'); ?></th>
                        <th><?php esc_html_e('Reproducciones', 'co360'); ?></th>
                        <th><?php esc_html_e('Reproducciones únicas', 'co360'); ?></th>
                        <th><?php esc_html_e('Tiempo total (H:M:S)', 'co360'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php
                if ($rows) {
                    foreach ($rows as $r) {
                        $seconds = (int) $r->seconds;
                        $hms     = $seconds ? gmdate('H:i:s', $seconds) : '-';

                        echo '<tr>';
                        echo '<td>' . esc_html($r->date) . '</td>';
                        echo '<td>' . esc_html((int) $r->plays) . '</td>';
                        echo '<td>' . esc_html((int) $r->unique_plays) . '</td>';
                        echo '<td>' . esc_html($hms) . '</td>';
                        echo '</tr>';
                    }
                } else {
                    echo '<tr><td colspan="4">' . esc_html__('No hay datos para este audio en el rango seleccionado.', 'co360') . '</td></tr>';
                }
                ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function maybe_export_admin() {
        if (!is_admin()) return;

        $page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
        if ($page !== self::MENU_SLUG) return;

        if (!isset($_GET['co360_export'])) return;
        if (!current_user_can('manage_options')) return;

        $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
        $date_to   = isset($_GET['date_to'])   ? sanitize_text_field($_GET['date_to'])   : '';

        $type = $_GET['co360_export'] === 'excel' ? 'excel' : 'csv';

        $rows = $this->get_stats($date_from, $date_to);

        if (ob_get_length()) {
            ob_end_clean();
        }

        $this->export_stats($rows, $type);
        exit;
    }

    public function render_admin_notices() {
        if (!is_admin()) return;

        $page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
        if ($page !== self::MENU_SLUG) return;

        $message = isset($_GET['co360_notice']) ? sanitize_text_field(wp_unslash($_GET['co360_notice'])) : '';
        $type    = isset($_GET['co360_notice_type']) ? sanitize_text_field(wp_unslash($_GET['co360_notice_type'])) : '';

        if (!$message) return;

        $message = rawurldecode($message);

        $class = $type === 'error' ? 'notice notice-error' : 'notice notice-success';
        ?>
        <div class="<?php echo esc_attr($class); ?>">
            <p><?php echo esc_html($message); ?></p>
        </div>
        <?php
    }

    public function handle_delete_all_stats() {
        if (!current_user_can('manage_options')) wp_die(__('No tienes permisos suficientes.', 'co360'));
        check_admin_referer('co360_delete_all_stats');

        $this->delete_all_stats();

        $url = add_query_arg([
            'page'               => self::MENU_SLUG,
            'co360_notice'       => rawurlencode(__('Se han borrado todas las estadísticas.', 'co360')),
            'co360_notice_type'  => 'success',
        ], admin_url('admin.php'));

        wp_safe_redirect($url);
        exit;
    }

    public function handle_delete_stats_by_date() {
        if (!current_user_can('manage_options')) wp_die(__('No tienes permisos suficientes.', 'co360'));
        check_admin_referer('co360_delete_stats_by_date');

        $from = isset($_POST['date_from']) ? sanitize_text_field($_POST['date_from']) : '';
        $to   = isset($_POST['date_to'])   ? sanitize_text_field($_POST['date_to'])   : '';

        $error = '';
        if (!$this->is_valid_date($from) || !$this->is_valid_date($to)) {
            $error = __('Las fechas deben tener formato YYYY-MM-DD.', 'co360');
        } elseif ($from > $to) {
            $error = __('La fecha inicial no puede ser mayor que la final.', 'co360');
        }

        if ($error) {
            $url = add_query_arg([
                'page'              => self::MENU_SLUG,
                'co360_notice'      => rawurlencode($error),
                'co360_notice_type' => 'error',
            ], admin_url('admin.php'));
            wp_safe_redirect($url);
            exit;
        }

        $this->delete_stats_by_date($from, $to);

        $url = add_query_arg([
            'page'               => self::MENU_SLUG,
            'co360_notice'       => rawurlencode(sprintf(__('Estadísticas borradas entre %s y %s.', 'co360'), $from, $to)),
            'co360_notice_type'  => 'success',
        ], admin_url('admin.php'));

        wp_safe_redirect($url);
        exit;
    }

    public function handle_delete_stats_by_audio() {
        if (!current_user_can('manage_options')) wp_die(__('No tienes permisos suficientes.', 'co360'));
        check_admin_referer('co360_delete_stats_by_audio');

        $audio_id = isset($_POST['audio_id']) ? sanitize_text_field($_POST['audio_id']) : '';

        if (!$audio_id) {
            $url = add_query_arg([
                'page'              => self::MENU_SLUG,
                'co360_notice'      => rawurlencode(__('Debes seleccionar un audio válido.', 'co360')),
                'co360_notice_type' => 'error',
            ], admin_url('admin.php'));
            wp_safe_redirect($url);
            exit;
        }

        $this->delete_stats_by_audio($audio_id);

        $url = add_query_arg([
            'page'               => self::MENU_SLUG,
            'co360_notice'       => rawurlencode(sprintf(__('Estadísticas borradas para el audio %s.', 'co360'), $audio_id)),
            'co360_notice_type'  => 'success',
        ], admin_url('admin.php'));

        wp_safe_redirect($url);
        exit;
    }

    /*=====================================================
     * FRONTEND SHORTCODES + EXPORT
     *====================================================*/

    public function register_shortcodes() {
        add_shortcode('co360_audio_dashboard', [$this, 'shortcode_audio_dashboard']);
    }

    public function maybe_export_front() {
        if (!isset($_GET['co360_front_export'])) return;

        $type = $_GET['co360_front_export'] === 'excel' ? 'excel' : 'csv';

        $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
        $date_to   = isset($_GET['date_to'])   ? sanitize_text_field($_GET['date_to'])   : '';

        $rows = $this->get_stats($date_from, $date_to);

        if (ob_get_length()) {
            ob_end_clean();
        }

        $this->export_stats($rows, $type);
        exit;
    }

    // ============================
    // Shortcode panel frontend
    // ============================
    public function shortcode_audio_dashboard($atts) {
        $atts = shortcode_atts([], $atts, 'co360_audio_dashboard');

        // Estilos frontend (solo una vez)
        static $co360_styles_printed = false;
        if (!$co360_styles_printed) {
            $co360_styles_printed = true;
            ?>
            <style>
.co360-audio-dashboard,
.co360-audio-detail {
    font-size: 14px;
    line-height: 1.5;
    margin-bottom: 40px;
}
.co360-audio-dashboard h2,
.co360-audio-detail h2 {
    color: #E35053;
    margin-bottom: 10px;
}
.co360-audio-dashboard h3,
.co360-audio-detail h3 {
    color: #E6664D;
    margin-top: 25px;
    margin-bottom: 6px;
}
.co360-audio-dashboard a,
.co360-audio-detail a {
    color: #E35053;
    text-decoration: none;
}
.co360-audio-dashboard a:hover,
.co360-audio-detail a:hover {
    text-decoration: underline;
}

/* ─── CABECERA: caja filtros + badges ───────────────── */

.co360-audio-header {
    margin-bottom: 20px;
}

.co360-audio-filters-box {
    background: #fff;
    padding: 18px 20px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

/* El propio formulario actúa como contenedor flexible */
.co360-audio-filters {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 18px 24px;
    margin: 0;
}

/* Grupo de campos de fecha + botones (lado izq.) */
.co360-filter-group {
    display: flex;
    flex-wrap: wrap;
    gap: 15px 25px;
    align-items: flex-end;
}

.co360-filter-group label {
    display: flex;
    flex-direction: column;
    font-weight: 600;
    font-size: 12px;
    color: #555;
}

.co360-filter-group input[type="date"] {
    margin-top: 3px;
    padding: 6px 8px;
    border-radius: 8px;
    border: 1px solid #ccc;
    min-width: 150px;
    font-size: 13px;
}

.co360-filter-group button {
    padding: 8px 16px;
    border-radius: 8px;
    border: none;
    background: #E35053;
    color: #fff;
    font-weight: 600;
    cursor: pointer;
}
.co360-filter-group button:hover {
    background: #E6664D;
}

.co360-clear-filters {
    font-size: 13px;
}

/* Badges dentro de la misma caja (lado dcho.) */
.co360-audio-summary-inside {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-left: auto;
}

.co360-summary-box {
    background: linear-gradient(135deg, #E35053, #EA7A72);
    color: #fff;
    padding: 8px 14px;
    border-radius: 999px;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    min-width: 170px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.12);
}

.co360-summary-label {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    opacity: 0.9;
}
.co360-summary-value {
    font-size: 16px;
    font-weight: 700;
}

/* ─── TABLAS ─────────────────────────────────────────── */

.co360-audio-table,
.co360-audio-detail-summary,
.co360-audio-detail-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 8px;
    background: #fff;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 1px 4px rgba(0,0,0,0.06);
}
.co360-audio-table th,
.co360-audio-detail-summary th,
.co360-audio-detail-table th {
    padding: 8px 10px;
    text-align: left;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    background: linear-gradient(90deg, #E35053, #E6664D);
    color: #fff;
}
.co360-audio-table td,
.co360-audio-detail-summary td,
.co360-audio-detail-table td {
    padding: 7px 10px;
    border-top: 1px solid #f1f1f1;
    font-size: 13px;
}
.co360-audio-table tbody tr:nth-child(even),
.co360-audio-detail-table tbody tr:nth-child(even) {
    background: #fafafa;
}
.co360-audio-table tbody tr:hover,
.co360-audio-detail-table tbody tr:hover {
    background: #fff5f5;
}
.co360-audio-detail-summary tbody tr {
    background: #fff8f7;
}
.co360-audio-detail-summary td:first-child {
    font-weight: 600;
}
.co360-audio-dashboard p,
.co360-audio-detail p {
    margin: 0 0 8px;
}

/* ─── ORDENACIÓN TABLA ──────────────────────────────── */

.co360-sortable {
    position: relative;
    user-select: none;
    cursor: pointer;
}
.co360-sortable::after {
    content: '↕';
    font-size: 10px;
    margin-left: 4px;
    opacity: 0.4;
}
.co360-sort-asc::after {
    content: '▲';
    opacity: 0.8;
}
.co360-sort-desc::after {
    content: '▼';
    opacity: 0.8;
}

/* ─── GRÁFICAS ───────────────────────────────────────── */

.co360-chart-wrapper {
    margin-top: 10px;
    width: 100%;
}
.co360-chart-wrapper canvas {
    width: 100% !important;
    max-width: 100%;
}
.co360-audio-dashboard canvas,
.co360-audio-detail canvas {
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.06);
    padding: 10px;
}

/* ─── RESPONSIVE ────────────────────────────────────── */

@media (max-width: 800px) {
    .co360-audio-filters {
        flex-direction: column;
        align-items: stretch;
    }
    .co360-audio-summary-inside {
        justify-content: space-between;
    }
}
@media (max-width: 600px) {
    .co360-filter-group {
        flex-direction: column;
        align-items: stretch;
    }
    .co360-filter-group button {
        width: 100%;
    }
    .co360-summary-box {
        width: 100%;
    }
}
</style>


            <?php
        }

        global $post;
        $base_url = $post ? get_permalink($post) : home_url(add_query_arg([]));

        $audio_id  = isset($_GET['audio_id'])  ? sanitize_text_field($_GET['audio_id'])  : '';
        $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
        $date_to   = isset($_GET['date_to'])   ? sanitize_text_field($_GET['date_to'])   : '';

        ob_start();

        if ($audio_id) {
            $this->render_front_audio_detail($audio_id, $date_from, $date_to, $base_url);
        } else {
            $this->render_front_audio_list($date_from, $date_to, $base_url);
        }

        return ob_get_clean();
    }

    // ============================
    // Vista listado frontend
    // ============================
    private function render_front_audio_list($date_from, $date_to, $base_url) {
        $rows = $this->get_stats($date_from, $date_to);

        $data = [];
        $total_plays   = 0;
        $total_seconds = 0;

        foreach ($rows as $r) {
            $plays   = (int) $r->plays;
            $seconds = (int) $r->seconds;
            $avg     = $plays > 0 ? $seconds / $plays : 0;

            $data[] = [
                'audio_id'      => $r->audio_id,
                'title'         => $r->title,
                'duration'      => (int) $r->duration,
                'plays'         => $plays,
                'unique_plays'  => (int) $r->unique_plays,
                'seconds'       => $seconds,
                'avg'           => $avg,
            ];

            $total_plays   += $plays;
            $total_seconds += $seconds;
        }

        $total_hms = $total_seconds ? gmdate('H:i:s', $total_seconds) : '00:00:00';

        $csv_url = add_query_arg([
            'co360_front_export' => 'csv',
            'date_from'          => $date_from,
            'date_to'            => $date_to,
        ], $base_url);

        $excel_url = add_query_arg([
            'co360_front_export' => 'excel',
            'date_from'          => $date_from,
            'date_to'            => $date_to,
        ], $base_url);

        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js',
            [],
            '4.4.0',
            true
        );

        wp_enqueue_script(
            'co360-audio-analytics-admin',
            plugins_url('admin.js', __FILE__),
            ['chartjs'],
            '1.0.4',
            true
        );

        wp_localize_script('co360-audio-analytics-admin', 'CO360AUDIOADMIN', [
            'items'     => $data,
            'detail'    => null,
            'date_from' => $date_from,
            'date_to'   => $date_to,
        ]);
        ?>
        <div class="co360-audio-dashboard">
            <h2><?php esc_html_e('Informe de Audio Analytics', 'co360'); ?></h2>

<div class="co360-audio-header">
    <div class="co360-audio-filters-box">

        <form method="get" action="<?php echo esc_url($base_url); ?>" class="co360-audio-filters">
            <div class="co360-filter-group">
                <label>
                    <?php esc_html_e('Desde', 'co360'); ?>:
                    <input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>" />
                </label>

                <label>
                    <?php esc_html_e('Hasta', 'co360'); ?>:
                    <input type="date" name="date_to" value="<?php echo esc_attr($date_to); ?>" />
                </label>

                <button type="submit">
                    <?php esc_html_e('Aplicar filtros', 'co360'); ?>
                </button>

                <?php if ($date_from || $date_to): ?>
                    <a href="<?php echo esc_url($base_url); ?>" class="co360-clear-filters">
                        <?php esc_html_e('Quitar filtros', 'co360'); ?>
                    </a>
                <?php endif; ?>
            </div>

            <div class="co360-audio-summary-inside">
                <div class="co360-summary-box">
                    <span class="co360-summary-label"><?php esc_html_e('Reproducciones totales', 'co360'); ?></span>
                    <span class="co360-summary-value"><?php echo esc_html($total_plays); ?></span>
                </div>
                <div class="co360-summary-box">
                    <span class="co360-summary-label"><?php esc_html_e('Tiempo total reproducido', 'co360'); ?></span>
                    <span class="co360-summary-value"><?php echo esc_html($total_hms); ?></span>
                </div>
            </div>

        </form>
    </div>
</div>

</div>

<p>
    <?php esc_html_e('Exportar datos:', 'co360'); ?>
    <a href="<?php echo esc_url($csv_url); ?>"><?php esc_html_e('Exportar CSV', 'co360'); ?></a> |
    <a href="<?php echo esc_url($excel_url); ?>"><?php esc_html_e('Exportar Excel', 'co360'); ?></a>
</p>


            <h3><?php esc_html_e('Tabla de audios', 'co360'); ?></h3>

            <table class="co360-audio-table">
                <thead>
                    <tr>
                        <th class="co360-sortable" data-sort-type="string"><?php esc_html_e('Título del audio', 'co360'); ?></th>
                        <th class="co360-sortable" data-sort-type="string"><?php esc_html_e('ID de audio', 'co360'); ?></th>
                        <th class="co360-sortable" data-sort-type="time"><?php esc_html_e('Duración (H:M:S)', 'co360'); ?></th>
                        <th class="co360-sortable" data-sort-type="number"><?php esc_html_e('Reproducciones', 'co360'); ?></th>
                        <th class="co360-sortable" data-sort-type="number"><?php esc_html_e('Reproducciones únicas', 'co360'); ?></th>
                        <th class="co360-sortable" data-sort-type="time"><?php esc_html_e('Tiempo total reproducido (H:M:S)', 'co360'); ?></th>
                        <th class="co360-sortable" data-sort-type="time"><?php esc_html_e('Media por reproducción (H:M:S)', 'co360'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php
                if ($rows) {
                    foreach ($rows as $r) {
                        $plays    = (int) $r->plays;
                        $uplays   = (int) $r->unique_plays;
                        $seconds  = (int) $r->seconds;
                        $avg      = $plays > 0 ? (int) round($seconds / $plays) : 0;

                        $dur_hms   = $r->duration ? gmdate('H:i:s', (int)$r->duration) : '-';
                        $total_hms = $seconds ? gmdate('H:i:s', $seconds) : '-';
                        $avg_hms   = $avg ? gmdate('H:i:s', $avg) : '-';

                        $detail_url = add_query_arg([
                            'audio_id'  => $r->audio_id,
                            'date_from' => $date_from,
                            'date_to'   => $date_to,
                        ], $base_url);

                        echo '<tr>';
                        echo '<td><a href="' . esc_url($detail_url) . '">' . esc_html($r->title) . '</a></td>';
                        echo '<td><code>' . esc_html($r->audio_id) . '</code></td>';
                        echo '<td>' . esc_html($dur_hms) . '</td>';
                        echo '<td>' . esc_html($plays) . '</td>';
                        echo '<td>' . esc_html($uplays) . '</td>';
                        echo '<td>' . esc_html($total_hms) . '</td>';
                        echo '<td>' . esc_html($avg_hms) . '</td>';
                        echo '</tr>';
                    }
                } else {
                    echo '<tr><td colspan="7">' . esc_html__('No hay datos para el rango seleccionado.', 'co360') . '</td></tr>';
                }
                ?>
                </tbody>
            </table>

            <?php if (!empty($data)): ?>
                <h3 style="margin-top:30px;"><?php esc_html_e('Gráfica por audio', 'co360'); ?></h3>
                <p><?php esc_html_e('Comparativa de número de reproducciones y tiempo total reproducido.', 'co360'); ?></p>

                <div class="co360-chart-wrapper">
                    <canvas id="co360-audio-chart"></canvas>
                </div>
            <?php endif; ?>
        </div>
        <?php
        // JS de ordenación de columnas (solo una vez)
        static $co360_sort_js_printed = false;
        if (!$co360_sort_js_printed) {
            $co360_sort_js_printed = true;
            ?>
            <script>
            document.addEventListener('DOMContentLoaded', function () {
                function co360GetCellValue(row, index, type) {
                    var cell = row.children[index];
                    if (!cell) return '';
                    var text = (cell.textContent || '').trim();
                    if (type === 'number') {
                        var n = parseFloat(text.replace(',', '.'));
                        return isNaN(n) ? 0 : n;
                    }
                    if (type === 'time') {
                        var parts = text.split(':');
                        if (parts.length !== 3) return 0;
                        var h = parseInt(parts[0], 10) || 0;
                        var m = parseInt(parts[1], 10) || 0;
                        var s = parseInt(parts[2], 10) || 0;
                        return h * 3600 + m * 60 + s;
                    }
                    return text.toLowerCase();
                }

                document.querySelectorAll('.co360-audio-table').forEach(function (table) {
                    var headers = table.querySelectorAll('th.co360-sortable');
                    headers.forEach(function (th, index) {
                        th.addEventListener('click', function () {
                            var type = th.dataset.sortType || 'string';
                            var tbody = table.tBodies[0];
                            if (!tbody) return;
                            var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
                            var currentOrder = th.dataset.sortOrder === 'asc' ? 'asc'
                                              : th.dataset.sortOrder === 'desc' ? 'desc'
                                              : null;
                            var newOrder = currentOrder === 'asc' ? 'desc' : 'asc';

                            headers.forEach(function (h) {
                                h.dataset.sortOrder = '';
                                h.classList.remove('co360-sort-asc', 'co360-sort-desc');
                            });
                            th.dataset.sortOrder = newOrder;
                            th.classList.add(newOrder === 'asc' ? 'co360-sort-asc' : 'co360-sort-desc');

                            rows.sort(function (a, b) {
                                var va = co360GetCellValue(a, index, type);
                                var vb = co360GetCellValue(b, index, type);
                                if (va === vb) return 0;
                                if (va > vb) return newOrder === 'asc' ? 1 : -1;
                                return newOrder === 'asc' ? -1 : 1;
                            });

                            rows.forEach(function (row) {
                                tbody.appendChild(row);
                            });
                        });
                    });
                });
            });
            </script>
            <?php
        }
    }

    // ============================
    // Vista detalle frontend
    // ============================
    private function render_front_audio_detail($audio_id, $date_from, $date_to, $base_url) {
        $meta = $this->get_audio_meta($audio_id);
        $rows = $this->get_audio_daily_stats($audio_id, $date_from, $date_to);

        $total_plays   = 0;
        $total_uplays  = 0;
        $total_seconds = 0;

        $detail_items = [];

        foreach ($rows as $r) {
            $total_plays   += (int) $r->plays;
            $total_uplays  += (int) $r->unique_plays;
            $total_seconds += (int) $r->seconds;

            $detail_items[] = [
                'date'         => $r->date,
                'plays'        => (int) $r->plays,
                'unique_plays' => (int) $r->unique_plays,
                'seconds'      => (int) $r->seconds,
            ];
        }

        $avg = $total_plays > 0 ? (int) round($total_seconds / $total_plays) : 0;

        $dur_hms   = $meta->duration ? gmdate('H:i:s', (int) $meta->duration) : '-';
        $total_hms = $total_seconds ? gmdate('H:i:s', $total_seconds) : '-';
        $avg_hms   = $avg ? gmdate('H:i:s', $avg) : '-';

        $back_url = add_query_arg([
            'date_from' => $date_from,
            'date_to'   => $date_to,
        ], $base_url);

        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js',
            [],
            '4.4.0',
            true
        );

        wp_enqueue_script(
            'co360-audio-analytics-admin',
            plugins_url('admin.js', __FILE__),
            ['chartjs'],
            '1.0.4',
            true
        );

        wp_localize_script('co360-audio-analytics-admin', 'CO360AUDIOADMIN', [
            'items'     => [],
            'date_from' => $date_from,
            'date_to'   => $date_to,
            'detail'    => [
                'audio_id' => $audio_id,
                'title'    => $meta->title,
                'items'    => $detail_items,
            ],
        ]);
        ?>
        <div class="co360-audio-detail">
            <p>
                <a href="<?php echo esc_url($back_url); ?>">← <?php esc_html_e('Volver al listado general', 'co360'); ?></a>
            </p>

            <h2><?php echo esc_html($meta->title); ?></h2>
            <p>
                <strong><?php esc_html_e('ID de audio:', 'co360'); ?></strong>
                <code><?php echo esc_html($meta->audio_id); ?></code>
            </p>

            <div class="co360-audio-filters">
                <form method="get" action="<?php echo esc_url($base_url); ?>">
                    <input type="hidden" name="audio_id" value="<?php echo esc_attr($audio_id); ?>" />

                    <label>
                        <?php esc_html_e('Desde', 'co360'); ?>:
                        <input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>" />
                    </label>

                    <label>
                        <?php esc_html_e('Hasta', 'co360'); ?>:
                        <input type="date" name="date_to" value="<?php echo esc_attr($date_to); ?>" />
                    </label>

                    <button type="submit">
                        <?php esc_html_e('Aplicar filtros', 'co360'); ?>
                    </button>

                    <?php if ($date_from || $date_to): ?>
                        <a href="<?php echo esc_url(add_query_arg(['audio_id' => $audio_id], $base_url)); ?>">
                            <?php esc_html_e('Quitar filtros', 'co360'); ?>
                        </a>
                    <?php endif; ?>
                </form>
            </div>

            <h3><?php esc_html_e('Resumen', 'co360'); ?></h3>
            <table class="co360-audio-detail-summary">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Duración (H:M:S)', 'co360'); ?></th>
                        <th><?php esc_html_e('Reproducciones', 'co360'); ?></th>
                        <th><?php esc_html_e('Reproducciones únicas', 'co360'); ?></th>
                        <th><?php esc_html_e('Tiempo total (H:M:S)', 'co360'); ?></th>
                        <th><?php esc_html_e('Media por reproducción (H:M:S)', 'co360'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?php echo esc_html($dur_hms); ?></td>
                        <td><?php echo esc_html($total_plays); ?></td>
                        <td><?php echo esc_html($total_uplays); ?></td>
                        <td><?php echo esc_html($total_hms); ?></td>
                        <td><?php echo esc_html($avg_hms); ?></td>
                    </tr>
                </tbody>
            </table>

            <h3 style="margin-top:30px;"><?php esc_html_e('Evolución por fecha', 'co360'); ?></h3>
            <p><?php esc_html_e('Gráfica diaria de reproducciones y tiempo total escuchado.', 'co360'); ?></p>

            <div class="co360-chart-wrapper">
                <canvas id="co360-audio-detail-chart"></canvas>
            </div>

            <h3 style="margin-top:30px;"><?php esc_html_e('Detalle diario', 'co360'); ?></h3>
            <table class="co360-audio-detail-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Fecha', 'co360'); ?></th>
                        <th><?php esc_html_e('Reproducciones', 'co360'); ?></th>
                        <th><?php esc_html_e('Reproducciones únicas', 'co360'); ?></th>
                        <th><?php esc_html_e('Tiempo total (H:M:S)', 'co360'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php
                if ($rows) {
                    foreach ($rows as $r) {
                        $sec = (int) $r->seconds;
                        $hms = $sec ? gmdate('H:i:s', $sec) : '-';

                        echo '<tr>';
                        echo '<td>' . esc_html($r->date) . '</td>';
                        echo '<td>' . esc_html((int) $r->plays) . '</td>';
                        echo '<td>' . esc_html((int) $r->unique_plays) . '</td>';
                        echo '<td>' . esc_html($hms) . '</td>';
                        echo '</tr>';
                    }
                } else {
                    echo '<tr><td colspan="4">' . esc_html__('No hay datos para este audio en el rango seleccionado.', 'co360') . '</td></tr>';
                }
                ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /*=====================================================
     * DATA HELPERS
     *====================================================*/

    private function is_valid_date($date) {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
    }

    private function get_all_audio_meta() {
        global $wpdb;
        $meta = $wpdb->prefix . self::DB_META;

        return $wpdb->get_results("SELECT audio_id, COALESCE(title, audio_id) AS title FROM $meta ORDER BY title ASC");
    }

    private function delete_all_stats() {
        global $wpdb;
        $totals   = $wpdb->prefix . self::DB_TOT;
        $sessions = $wpdb->prefix . self::DB_SES;

        $wpdb->query("DELETE FROM $totals");
        $wpdb->query("DELETE FROM $sessions");
    }

    private function delete_stats_by_date($from, $to) {
        global $wpdb;
        $totals   = $wpdb->prefix . self::DB_TOT;
        $sessions = $wpdb->prefix . self::DB_SES;

        $wpdb->query($wpdb->prepare("DELETE FROM $totals WHERE date BETWEEN %s AND %s", $from, $to));
        $wpdb->query($wpdb->prepare("DELETE FROM $sessions WHERE date BETWEEN %s AND %s", $from, $to));
    }

    private function delete_stats_by_audio($audio_id) {
        global $wpdb;
        $totals   = $wpdb->prefix . self::DB_TOT;
        $sessions = $wpdb->prefix . self::DB_SES;

        $wpdb->delete($totals, ['audio_id' => $audio_id], ['%s']);
        $wpdb->delete($sessions, ['audio_id' => $audio_id], ['%s']);
    }

    private function get_stats($date_from = '', $date_to = '') {
        global $wpdb;
        $totals = $wpdb->prefix . self::DB_TOT;
        $meta   = $wpdb->prefix . self::DB_META;

        $where  = '1=1';
        $params = [];

        if ($date_from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
            $where   .= ' AND t.date >= %s';
            $params[] = $date_from;
        }
        if ($date_to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
            $where   .= ' AND t.date <= %s';
            $params[] = $date_to;
        }

        $sql = "
            SELECT
                t.audio_id,
                COALESCE(m.title, t.audio_id) AS title,
                COALESCE(m.duration, 0) AS duration,
                SUM(t.plays) AS plays,
                SUM(t.unique_plays) AS unique_plays,
                SUM(t.seconds) AS seconds
            FROM $totals t
            LEFT JOIN $meta m ON t.audio_id = m.audio_id
            WHERE $where
            GROUP BY t.audio_id, title, duration
            ORDER BY plays DESC
        ";

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, $params);
        }

        return $wpdb->get_results($sql);
    }

    private function get_audio_meta($audio_id) {
        global $wpdb;
        $meta_table = $wpdb->prefix . self::DB_META;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT audio_id, COALESCE(title, audio_id) AS title, COALESCE(duration, 0) AS duration 
                 FROM $meta_table 
                 WHERE audio_id = %s 
                 LIMIT 1",
                $audio_id
            )
        );

        if ($row) {
            return $row;
        }

        return (object) [
            'audio_id' => $audio_id,
            'title'    => $audio_id,
            'duration' => 0,
        ];
    }

    private function get_audio_daily_stats($audio_id, $date_from = '', $date_to = '') {
        global $wpdb;
        $totals = $wpdb->prefix . self::DB_TOT;

        $where  = 'audio_id = %s';
        $params = [$audio_id];

        if ($date_from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
            $where   .= ' AND date >= %s';
            $params[] = $date_from;
        }
        if ($date_to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
            $where   .= ' AND date <= %s';
            $params[] = $date_to;
        }

        $sql = "
            SELECT 
                date,
                SUM(plays) AS plays,
                SUM(unique_plays) AS unique_plays,
                SUM(seconds) AS seconds
            FROM $totals
            WHERE $where
            GROUP BY date
            ORDER BY date ASC
        ";

        $prepared = $wpdb->prepare($sql, $params);

        return $wpdb->get_results($prepared);
    }

    private function export_stats($rows, $type = 'csv') {
        $now = current_time('Ymd_His');

        if ($type === 'excel') {
            $filename = "audio_stats_$now.xls";
            header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        } else {
            $filename = "audio_stats_$now.csv";
            header('Content-Type: text/csv; charset=utf-8');
        }

        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $headers = [
            'audio_id',
            'titulo',
            'duracion_segundos',
            'duracion_hms',
            'reproducciones',
            'reproducciones_unicas',
            'tiempo_total_segundos',
            'tiempo_total_hms',
            'media_segundos',
            'media_hms',
        ];

        if ($type === 'excel') {
            echo "<table border=\"1\">\n<tr>";
            foreach ($headers as $h) {
                echo "<th>".esc_html($h)."</th>";
            }
            echo "</tr>\n";

            foreach ($rows as $r) {
                $plays    = (int) $r->plays;
                $uplays   = (int) $r->unique_plays;
                $seconds  = (int) $r->seconds;
                $avg      = $plays > 0 ? (int) round($seconds / $plays) : 0;

                $dur_hms   = $r->duration ? gmdate('H:i:s', (int)$r->duration) : '00:00:00';
                $total_hms = $seconds ? gmdate('H:i:s', $seconds) : '00:00:00';
                $avg_hms   = $avg ? gmdate('H:i:s', $avg) : '00:00:00';

                echo "<tr>";
                echo "<td>".esc_html($r->audio_id)."</td>";
                echo "<td>".esc_html($r->title)."</td>";
                echo "<td>".(int)$r->duration."</td>";
                echo "<td>".esc_html($dur_hms)."</td>";
                echo "<td>".$plays."</td>";
                echo "<td>".$uplays."</td>";
                echo "<td>".$seconds."</td>";
                echo "<td>".esc_html($total_hms)."</td>";
                echo "<td>".$avg."</td>";
                echo "<td>".esc_html($avg_hms)."</td>";
                echo "</tr>\n";
            }
            echo "</table>";
        } else {
            $fh = fopen('php://output', 'w');

            fprintf($fh, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8

            fputcsv($fh, $headers, ';');

            foreach ($rows as $r) {
                $plays    = (int) $r->plays;
                $uplays   = (int) $r->unique_plays;
                $seconds  = (int) $r->seconds;
                $avg      = $plays > 0 ? (int) round($seconds / $plays) : 0;

                $dur_hms   = $r->duration ? gmdate('H:i:s', (int)$r->duration) : '00:00:00';
                $total_hms = $seconds ? gmdate('H:i:s', $seconds) : '00:00:00';
                $avg_hms   = $avg ? gmdate('H:i:s', $avg) : '00:00:00';

                fputcsv($fh, [
                    $r->audio_id,
                    $r->title,
                    (int)$r->duration,
                    $dur_hms,
                    $plays,
                    $uplays,
                    $seconds,
                    $total_hms,
                    $avg,
                    $avg_hms,
                ], ';');
            }

            fclose($fh);
        }
    }
}

new CO360_Audio_Analytics();
