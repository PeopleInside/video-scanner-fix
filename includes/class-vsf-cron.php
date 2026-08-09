<?php
if (!defined('ABSPATH')) {
    exit;
}

class Video_Scanner_Fix_Cron {

    public static function register_schedule() {
        if (!wp_next_scheduled('vsf_cron_scan_event')) {
            $settings = get_option('vsf_settings', array());
            $interval = isset($settings['cron_interval']) ? $settings['cron_interval'] : 'daily';
            
            if (!empty($settings['cron_enabled'])) {
                wp_schedule_event(time() + 300, $interval, 'vsf_cron_scan_event');
            }
        }
    }

    public static function clear_schedule() {
        $timestamp = wp_next_scheduled('vsf_cron_scan_event');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'vsf_cron_scan_event');
        }
    }

    public function init_hooks() {
        add_filter('cron_schedules', array($this, 'add_cron_intervals'));
        add_action('vsf_cron_scan_event', array($this, 'run_cron_scan'));
    }

    public function add_cron_intervals($schedules) {
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
        $settings   = get_option('vsf_settings', array());
        if (empty($settings['cron_enabled'])) {
            return;
        }

        $post_types = isset($settings['scan_post_types']) ? (array)$settings['scan_post_types'] : array('post');
        $statuses   = isset($settings['scan_post_statuses']) ? (array)$settings['scan_post_statuses'] : array('publish');
        $batch_size = isset($settings['batch_size']) ? intval($settings['batch_size']) : 30;

        $query = new WP_Query(array(
            'post_type'      => $post_types,
            'post_status'    => $statuses,
            'posts_per_page' => $batch_size,
            'orderby'        => 'rand' // Pick random batch on each cron run
        ));

        if ($query->have_posts()) {
            $scanner = new Video_Scanner_Fix_Scanner();
            $cron_issues = array();

            foreach ($query->posts as $post) {
                $res = $scanner->scan_post($post);
                if (isset($res['results']) && is_array($res['results'])) {
                    foreach ($res['results'] as $item) {
                        if ($item['status'] === 'broken' || $item['status'] === 'geo_restricted') {
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
    }
}
