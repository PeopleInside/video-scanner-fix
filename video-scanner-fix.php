<?php
/**
 * Plugin Name: Video Scanner Fix
 * Plugin URI:  https://github.com/peopleinside/video-scanner-fix
 * Description: Scans WordPress posts and custom fields for broken video links and embeds, featuring automated schedules, real-time manual scanning, and action triggers.
 * Version:     1.0.7
 * Author:      peopleinside
 * Author URI:  https://github.com/peopleinside
 * License:     GPL-2.0-or-later
 * Text Domain: video-scanner-fix
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

define('VSF_VERSION', '1.0.7');
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

    $defaults = array(
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
    );

    // Merge defaults UNDER any existing settings, so this stays safe to run
    // more than once (e.g. if the activation hook ever re-fires on update or
    // reactivation): existing user-configured values (API keys, etc.) always
    // win, only genuinely-missing keys are filled in with defaults. This
    // never resets the whole option back to factory defaults.
    $existing = get_option('vsf_settings');
    if (!is_array($existing)) {
        $existing = array();
    }
    update_option('vsf_settings', array_merge($defaults, $existing));
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

    /*
     * L'updater NON va limitato a wp-admin.
     *
     * Gli aggiornamenti automatici girano dentro wp-cron.php (e in WP-CLI):
     * in entrambi i contesti is_admin() vale false. WP_Automatic_Updater::run()
     * chiama per prima cosa wp_update_plugins(), che RISCRIVE il transient
     * "update_plugins" facendo scattare pre_set_site_transient_update_plugins.
     * Se l'updater non è registrato in quel momento, la voce di aggiornamento
     * iniettata durante una precedente visita in bacheca viene sovrascritta e
     * sparisce: l'auto-updater legge il transient, non trova nulla per questo
     * plugin e non aggiorna niente, senza alcun errore. Risultato: il plugin
     * appare "da aggiornare" in bacheca ma l'auto-update non parte mai.
     *
     * Registrarlo sempre non ha costo sul front-end: i filtri agganciati sono
     * inerti finché WordPress non aggiorna il transient degli aggiornamenti o
     * non avvia un upgrade.
     */
    if (is_admin() || wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) {
        Video_Scanner_Fix_Updater::instance();
    }
}
add_action('plugins_loaded', 'vsf_init');
