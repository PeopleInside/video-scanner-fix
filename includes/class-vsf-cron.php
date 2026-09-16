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
        add_action('vsf_cron_scan_batch_event', array($this, 'run_cron_scan_batch'));
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

    // Same batch size used by the manual scan (see class-vsf-ajax.php default).
    private static $batch_size = 15;

    /**
     * Entry point fired by the weekly/monthly WP-Cron event.
     * Builds the full queue of posts to scan and kicks off batch processing.
     * BUGFIX (1.0.6): previously this scanned everything in a single request
     * capped at 240s with no resume, so on many hosts (real PHP time limits
     * are often lower) the run got cut off and never covered every post,
     * unlike the manual scan which loops in batches until truly complete.
     */
    public function run_cron_scan() {
        $settings = get_option('vsf_settings', array());
        if (empty($settings['cron_enabled'])) {
            return;
        }

        // Avoid starting a second full run while a previous automated scan
        // is still chaining through its batches.
        if (get_transient('vsf_cron_scan_lock')) {
            return;
        }
        set_transient('vsf_cron_scan_lock', 1, 6 * HOUR_IN_SECONDS);

        $post_types = isset($settings['scan_post_types']) ? (array)$settings['scan_post_types'] : array('post', 'page');
        $statuses   = isset($settings['scan_post_statuses']) ? (array)$settings['scan_post_statuses'] : array('publish');

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

        update_option('vsf_cron_scan_queue', $post_ids, false);
        update_option('vsf_cron_scan_progress', array(
            'scanned_posts'  => 0,
            'videos_checked' => 0,
            'broken_found'   => 0,
            'issues'         => array(),
        ), false);

        self::process_next_batch();
    }

    /**
     * Fired by the short-lived chained event; just continues the queue.
     */
    public function run_cron_scan_batch() {
        self::process_next_batch();
    }

    /**
     * Processes one batch of posts (like a single manual-scan AJAX call).
     * If posts remain in the queue afterwards, schedules the next batch a
     * few seconds later so the scan keeps going until nothing is left,
     * regardless of any single request's execution-time limit.
     */
    private static function process_next_batch() {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }

        $queue    = get_option('vsf_cron_scan_queue', array());
        $progress = get_option('vsf_cron_scan_progress', array(
            'scanned_posts'  => 0,
            'videos_checked' => 0,
            'broken_found'   => 0,
            'issues'         => array(),
        ));

        if (empty($queue)) {
            self::finish_scan($progress);
            return;
        }

        $batch = array_splice($queue, 0, self::$batch_size);
        update_option('vsf_cron_scan_queue', $queue, false);

        $scanner = new Video_Scanner_Fix_Scanner();
        $start_time = time();
        $max_batch_time = 60; // keep each chained request short and safe on any host

        foreach ($batch as $index => $pid) {
            if ((time() - $start_time) > $max_batch_time) {
                // Put the un-processed remainder of this batch back at the front of the queue.
                $queue = array_merge(array_slice($batch, $index), $queue);
                update_option('vsf_cron_scan_queue', $queue, false);
                break;
            }

            $post = get_post($pid);
            if (!$post) {
                continue;
            }

            $res = $scanner->scan_post($post);
            $progress['scanned_posts']++;

            if (isset($res['results']) && is_array($res['results'])) {
                foreach ($res['results'] as $item) {
                    $progress['videos_checked']++;
                    if ($item['status'] === 'broken') {
                        $progress['broken_found']++;
                        $progress['issues'][] = $item;
                    } elseif ($item['status'] === 'geo_restricted') {
                        $progress['issues'][] = $item;
                    }
                }
            }
        }

        update_option('vsf_cron_scan_progress', $progress, false);

        $remaining = get_option('vsf_cron_scan_queue', array());
        if (!empty($remaining)) {
            wp_schedule_single_event(time() + 10, 'vsf_cron_scan_batch_event');
        } else {
            self::finish_scan($progress);
        }
    }

    /**
     * Finalizes the automated scan once the whole queue has been processed:
     * sends the summary email and records last-scan time/stats, exactly as
     * a completed manual scan would.
     */
    private static function finish_scan($progress) {
        delete_option('vsf_cron_scan_queue');
        delete_option('vsf_cron_scan_progress');
        delete_transient('vsf_cron_scan_lock');

        if (!empty($progress['issues'])) {
            $scanner = new Video_Scanner_Fix_Scanner();
            $scanner->send_automated_scan_summary_email($progress['issues']);
        }

        update_option('vsf_last_scan_time', time());
        update_option('vsf_last_scan_stats', array(
            'posts_scanned'   => $progress['scanned_posts'],
            'videos_checked'  => $progress['videos_checked'],
            'broken_found'    => $progress['broken_found'],
            'completed_at'    => time(),
            'type'            => 'cron'
        ));
    }
}
