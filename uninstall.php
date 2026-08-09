<?php
/**
 * Video Scanner Fix Uninstall Handler
 * Author: peopleinside
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Delete plugin options
delete_option('vsf_settings');

// Clear cron schedule
$timestamp = wp_next_scheduled('vsf_cron_scan_event');
if ($timestamp) {
    wp_unschedule_event($timestamp, 'vsf_cron_scan_event');
}

// Drop log table
$table_name = $wpdb->prefix . 'vsf_logs';
$wpdb->query("DROP TABLE IF EXISTS {$table_name}");
