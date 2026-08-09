<?php
/**
 * Plugin Name: Video Scanner Fix
 * Plugin URI:  https://github.com/peopleinside/video-scanner-fix
 * Description: Scans WordPress posts and custom fields for broken video links and embeds, featuring automated schedules, real-time manual scanning, and action triggers.
 * Version:     1.0.1
 * Author:      peopleinside
 * Author URI:  https://github.com/peopleinside
 * License:     GPL-2.0-or-later
 * Text Domain: video-scanner-fix
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

define('VSF_VERSION', '1.0.1');
define('VSF_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('VSF_PLUGIN_URL', plugin_dir_url(__FILE__));
define('VSF_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Require Core Classes
require_once VSF_PLUGIN_DIR . 'includes/class-vsf-logger.php';
require_once VSF_PLUGIN_DIR . 'includes/class-vsf-scanner.php';
require_once VSF_PLUGIN_DIR . 'includes/class-vsf-cron.php';
require_once VSF_PLUGIN_DIR . 'includes/class-vsf-ajax.php';
require_once VSF_PLUGIN_DIR . 'includes/class-vsf-admin.php';
require_once VSF_PLUGIN_DIR . 'includes/class-vsf-core.php';
require_once VSF_PLUGIN_DIR . 'includes/class-vsf-updater.php';

/**
 * Main plugin activation hook
 */
function vsf_activate_plugin() {
    Video_Scanner_Fix_Logger::create_tables();
    Video_Scanner_Fix_Cron::register_schedule();
    
    // Set default options if not existing
    if (!get_option('vsf_settings')) {
        update_option('vsf_settings', array(
            'enabled_platforms' => array('youtube', 'vimeo', 'dailymotion', 'googledrive', 'archive', 'doodstream', 'mixcloud'),
            'scan_post_types'   => array('post', 'page'),
            'scan_post_statuses'=> array('publish'),
            'scan_meta_keys'    => '',
            'scan_link_types'   => 'all', // 'all', 'embeds', 'links'
            'batch_size'        => 20,
            'cron_interval'     => 'daily', // 'hourly', 'twicedaily', 'daily'
            'cron_enabled'      => false,
            'on_broken_status'  => 'no_change', // 'draft', 'private', 'no_change'
            'on_broken_tag'     => '',
            'notify_email'      => get_option('admin_email'),
            'notify_on_broken'  => false,
            'youtube_api_key'   => '',
            'vimeo_token'       => '',
        ));
    }
}
register_activation_hook(__FILE__, 'vsf_activate_plugin');

/**
 * Plugin deactivation hook
 */
function vsf_deactivate_plugin() {
    Video_Scanner_Fix_Cron::clear_schedule();
}
register_deactivation_hook(__FILE__, 'vsf_deactivate_plugin');

/**
 * Initialize Video Scanner Fix
 */
function vsf_init() {
    $plugin = new Video_Scanner_Fix_Core();
    $plugin->run();

    // Gli hook di aggiornamento (transient, plugins_api, upgrader_*) servono
    // solo in wp-admin.
    if (is_admin()) {
        Video_Scanner_Fix_Updater::instance();
    }
}
add_action('plugins_loaded', 'vsf_init');
