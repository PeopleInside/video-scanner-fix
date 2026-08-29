<?php
if (!defined('ABSPATH')) {
    exit;
}

class Video_Scanner_Fix_Cron {

    public function __construct() {
        add_filter('cron_schedules', array(__CLASS__, 'add_cron_intervals'));
    }

    public static function register_schedule() {
        $settings = get_option('vsf_settings', array());
        if (empty($settings['cron_enabled'])) {
            self::clear_schedule();
            return;
        }

        $interval = isset($settings['cron_interval']) ? $settings['cron_interval'] : 'daily';

        // Ensure custom intervals are in schedules before checking/scheduling
        add_filter('cron_schedules', array(__CLASS__, 'add_cron_intervals'));

        $timestamp = wp_next_scheduled('vsf_cron_scan_event');
        $current_schedule = $timestamp ? wp_get_schedule('vsf_cron_scan_event') : false;

        // If not scheduled or scheduled with a different interval, re-schedule
        if (!$timestamp || $current_schedule !== $interval) {
            if ($timestamp) {
                wp_unschedule_event($timestamp, 'vsf_cron_scan_event');
            }
            wp_schedule_event(time() + 60, $interval, 'vsf_cron_scan_event');
        }
    }

    public static function clear_schedule() {
        $timestamp = wp_next_scheduled('vsf_cron_scan_event');
        while ($timestamp) {
            wp_unschedule_event($timestamp, 'vsf_cron_scan_event');
            $timestamp = wp_next_scheduled('vsf_cron_scan_event');
        }
    }

    public function init_hooks() {
        add_filter('cron_schedules', array(__CLASS__, 'add_cron_intervals'));
        add_action('vsf_cron_scan_event', array($this, 'run_cron_scan'));
    }

    public static function add_cron_intervals($schedules) {
        if (!isset($schedules['weekly'])) {
            $schedules['weekly'] = array(
                'interval' => 604800, // 7 days
                'display'  => __('Once Weekly', 'video-scanner-fix')
            );
        }
        if (!isset($schedules['monthly'])) {
            $schedules['monthly'] = array(
                'interval' => 2592000, // 30 days
                'display'  => __('Once Monthly (30 Days)', 'video-scanner-fix')
            );
        }
        return $schedules;
    }

    public function run_cron_scan() {
        $settings = get_option('vsf_settings', array());
        if (empty($settings['cron_enabled'])) {
            return;
        }

        // Prevent timeouts during background automated scan
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }

        $post_types = isset($settings['scan_post_types']) ? (array)$settings['scan_post_types'] : array('post', 'page');
        $statuses   = isset($settings['scan_post_statuses']) ? (array)$settings['scan_post_statuses'] : array('publish');

        // Query all matching posts to scan completely
        $query_args = array(
            'post_type'              => $post_types,
            'post_status'            => $statuses,
            'posts_per_page'         => -1,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        );

        $post_ids = get_posts($query_args);

        $scanner = new Video_Scanner_Fix_Scanner();
        $cron_issues = array();
        $scanned_posts_count = 0;
        $videos_checked_count = 0;
        $broken_found_count = 0;

        if (!empty($post_ids)) {
            $start_time = time();
            $max_execution_time = 240; // Max 4 minutes safety limit

            foreach ($post_ids as $pid) {
                if ((time() - $start_time) > $max_execution_time) {
                    break;
                }

                $post = get_post($pid);
                if (!$post) {
                    continue;
                }

                $res = $scanner->scan_post($post);
                $scanned_posts_count++;

                if (isset($res['results']) && is_array($res['results'])) {
                    foreach ($res['results'] as $item) {
                        $videos_checked_count++;
                        if ($item['status'] === 'broken') {
                            $broken_found_count++;
                            $cron_issues[] = $item;
                        } elseif ($item['status'] === 'geo_restricted') {
                            $cron_issues[] = $item;
                        }
                    }
                }
            }

            // Send a single summary email report at the end of the automated scan run
            if (!empty($cron_issues)) {
                $scanner->send_automated_scan_summary_email($cron_issues);
            }
        }

        // Update last scan timestamp and stats ONLY AFTER scan execution finishes
        update_option('vsf_last_scan_time', time());
        update_option('vsf_last_scan_stats', array(
            'posts_scanned'   => $scanned_posts_count,
            'videos_checked'  => $videos_checked_count,
            'broken_found'    => $broken_found_count,
            'completed_at'    => time(),
            'type'            => 'cron'
        ));
    }
}
