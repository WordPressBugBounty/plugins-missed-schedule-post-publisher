<?php
/*
 * Plugin Name: Missed Schedule Post Publisher
 * Description: Publishes missed scheduled posts automatically.
 * Plugin URI: https://www.zumbo.net/missed-schedule-post-publisher-wordpress-plugin/
 * Version: 1.0.6
 * Author: UfukArt
 * Author URI: https://www.zumbo.net
 * Text Domain: missed-schedule-post-publisher
 * Domain Path: /languages/
 * License: GPL2
 */
namespace MissedSchedulePostPublisher;

defined('ABSPATH') || exit;

final class Missed_Schedule_Post_Publisher {

    const OPTION_EXECUTE_TIME = 'mspp_execute_time';
    const OPTION_LAST_EXECUTE = 'mspp_last_execute_time';
    const DEFAULT_INTERVAL   = 20;
    const CRON_HOOK          = 'mspp_check_posts';
    const CRON_INTERVAL_KEY = 'mspp_custom_interval';

    public function __construct() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        add_filter('cron_schedules', [$this, 'register_cron_interval']);
        add_action(self::CRON_HOOK, [$this, 'publish_missed_posts']);

        add_action('admin_menu', [$this, 'add_admin_menu']);

        // Fail-safe: cron kaçırılırsa admin girişinde tek seferlik tetikle
        add_action('admin_init', [$this, 'failsafe_cron']);
    }

    /* -------------------------------------------------------------------------
     * Activation / Deactivation
     * ---------------------------------------------------------------------- */

    public function activate() {
        update_option(self::OPTION_EXECUTE_TIME, self::DEFAULT_INTERVAL);
        update_option(self::OPTION_LAST_EXECUTE, time());

        wp_clear_scheduled_hook(self::CRON_HOOK);

        wp_schedule_event(
            time() + 60,
            self::CRON_INTERVAL_KEY,
            self::CRON_HOOK
        );
    }

    public function deactivate() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /* -------------------------------------------------------------------------
     * Cron
     * ---------------------------------------------------------------------- */

    public function register_cron_interval($schedules) {
        $interval = max(1, (int) get_option(self::OPTION_EXECUTE_TIME, self::DEFAULT_INTERVAL));

        $schedules[self::CRON_INTERVAL_KEY] = [
            'interval' => $interval * 60,
            'display'  => sprintf(
                __('Every %d Minutes (MSPP)', 'missed-schedule-post-publisher'),
                $interval
            ),
        ];

        return $schedules;
    }

    public function failsafe_cron() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(
                time() + 30,
                self::CRON_INTERVAL_KEY,
                self::CRON_HOOK
            );
        }
    }

    /* -------------------------------------------------------------------------
     * Core Logic
     * ---------------------------------------------------------------------- */

    public function publish_missed_posts() {
        global $wpdb;

        $now = current_time('mysql', true);

        $post_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID
             FROM {$wpdb->posts}
             WHERE post_status = 'future'
             AND post_date_gmt <= %s
             AND post_type IN ('post','page')
             ORDER BY post_date_gmt ASC",
            $now
        ));

        if (!empty($post_ids)) {
            foreach ($post_ids as $post_id) {
                $result = wp_update_post([
                    'ID'          => (int) $post_id,
                    'post_status' => 'publish',
                ], true);

                if (is_wp_error($result)) {
                    error_log('MSPP: Failed to publish post ' . $post_id . ' - ' . $result->get_error_message());
                }
            }
        }

        update_option(self::OPTION_LAST_EXECUTE, time());
    }

    /* -------------------------------------------------------------------------
     * Admin UI
     * ---------------------------------------------------------------------- */

    public function add_admin_menu() {
        add_options_page(
            __('Missed Schedule Post Publisher', 'missed-schedule-post-publisher'),
            __('Missed Schedule', 'missed-schedule-post-publisher'),
            'manage_options',
            'missed-schedule-post-publisher',
            [$this, 'render_admin_page']
        );
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'missed-schedule-post-publisher'));
        }

        if (isset($_POST['action']) && $_POST['action'] === 'update') {
            $this->handle_settings_update();
        }

        $interval = (int) get_option(self::OPTION_EXECUTE_TIME, self::DEFAULT_INTERVAL);
        $last_run = (int) get_option(self::OPTION_LAST_EXECUTE, time());
        $next_run = wp_next_scheduled(self::CRON_HOOK);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Missed Schedule Post Publisher', 'missed-schedule-post-publisher'); ?></h1>

            <p>
                <?php printf(
                    esc_html__('Runs every %d minutes. Last run (GMT): %s', 'missed-schedule-post-publisher'),
                    $interval,
                    gmdate('Y-m-d H:i:s', $last_run)
                ); ?>
            </p>

            <?php if ($next_run): ?>
            <p>
                <?php printf(
                    esc_html__('Next scheduled run (GMT): %s', 'missed-schedule-post-publisher'),
                    gmdate('Y-m-d H:i:s', $next_run)
                ); ?>
            </p>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field('mspp_settings_update', 'mspp_nonce'); ?>
                <input type="hidden" name="action" value="update">

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="mspp_execute_time">
                                <?php esc_html_e('Run interval', 'missed-schedule-post-publisher'); ?>
                            </label>
                        </th>
                        <td>
                            <select name="mspp_execute_time" id="mspp_execute_time">
                                <?php $this->render_interval_options($interval); ?>
                            </select>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Save Settings', 'missed-schedule-post-publisher')); ?>
            </form>
        </div>
        <?php
    }

    private function render_interval_options($selected) {
        $options = [5, 10, 15, 20, 30, 60];

        foreach ($options as $value) {
            printf(
                '<option value="%d" %s>%d %s</option>',
                (int) $value,
                selected($value, $selected, false),
                (int) $value,
                esc_html__('Minutes', 'missed-schedule-post-publisher')
            );
        }
    }

    private function handle_settings_update() {
        if (
            !isset($_POST['mspp_nonce']) ||
            !wp_verify_nonce($_POST['mspp_nonce'], 'mspp_settings_update')
        ) {
            wp_die(__('Security check failed', 'missed-schedule-post-publisher'));
        }

        $interval = isset($_POST['mspp_execute_time'])
            ? absint($_POST['mspp_execute_time'])
            : self::DEFAULT_INTERVAL;

        update_option(self::OPTION_EXECUTE_TIME, max(1, $interval));

        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_schedule_event(
            time() + 60,
            self::CRON_INTERVAL_KEY,
            self::CRON_HOOK
        );

        add_settings_error(
            'mspp_messages',
            'mspp_message',
            __('Settings saved successfully', 'missed-schedule-post-publisher'),
            'updated'
        );
        settings_errors('mspp_messages');
    }
}

add_action('plugins_loaded', static function () {
    new Missed_Schedule_Post_Publisher();
});