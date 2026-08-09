<?php
if (!defined('ABSPATH')) {
    exit;
}

class Video_Scanner_Fix_Core {

    protected $admin;
    protected $ajax;
    protected $cron;

    public function __construct() {
        $this->admin = new Video_Scanner_Fix_Admin();
        $this->ajax  = new Video_Scanner_Fix_Ajax();
        $this->cron  = new Video_Scanner_Fix_Cron();
    }

    public function run() {
        // Load text domain immediately and on init
        $this->load_textdomain();
        add_action('init', array($this, 'load_textdomain'));

        // Admin hooks
        if (is_admin()) {
            add_action('admin_menu', array($this->admin, 'add_menu_page'));
            add_action('wp_dashboard_setup', array($this->admin, 'add_dashboard_widget'));
            add_action('admin_enqueue_scripts', array($this->admin, 'enqueue_assets'));
            add_action('add_meta_boxes', array($this->admin, 'add_meta_box'));
            add_filter('plugin_action_links_' . VSF_PLUGIN_BASENAME, array($this->admin, 'add_plugin_action_links'));
            add_action('admin_post_vsf_export_logs_csv', array($this->admin, 'export_logs_csv'));
        }

        // AJAX handlers
        $this->ajax->init_hooks();

        // Cron handlers
        $this->cron->init_hooks();
    }

    public function load_textdomain() {
        $loaded = load_plugin_textdomain(
            'video-scanner-fix',
            false,
            dirname(VSF_PLUGIN_BASENAME) . '/languages'
        );

        if (!$loaded) {
            $locale = function_exists('determine_locale') ? determine_locale() : (function_exists('get_user_locale') ? get_user_locale() : get_locale());
            $candidates = array(
                VSF_PLUGIN_DIR . 'languages/video-scanner-fix-' . $locale . '.mo',
                VSF_PLUGIN_DIR . 'languages/video-scanner-fix-it_IT.mo',
                VSF_PLUGIN_DIR . 'languages/it_IT.mo',
            );

            foreach ($candidates as $mofile) {
                if (file_exists($mofile)) {
                    if (load_textdomain('video-scanner-fix', $mofile)) {
                        break;
                    }
                }
            }
        }
    }
}
