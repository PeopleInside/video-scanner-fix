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

        // Post lifecycle handlers: auto-clean logs when posts are trashed, deleted, or updated
        add_action('wp_trash_post', array('Video_Scanner_Fix_Logger', 'on_post_trashed_or_deleted'));
        add_action('before_delete_post', array('Video_Scanner_Fix_Logger', 'on_post_trashed_or_deleted'));
        add_action('save_post', array($this, 'on_save_post'), 10, 2);
    }

    /**
     * When a post is saved or updated, automatically prune any logs for video URLs
     * that were removed or replaced by the user.
     */
    public function on_save_post($post_id, $post) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!$post || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        if ($post->post_status === 'trash' || $post->post_status === 'auto-draft') {
            Video_Scanner_Fix_Logger::delete_logs_by_post_id($post_id);
            return;
        }

        // If the post has existing logs, verify if any logged video URL was removed
        global $wpdb;
        $table = Video_Scanner_Fix_Logger::get_table_name();
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return;
        }

        $existing = $wpdb->get_results($wpdb->prepare("SELECT id, video_url FROM {$table} WHERE post_id = %d", $post_id), ARRAY_A);
        if (!empty($existing)) {
            $scanner = new Video_Scanner_Fix_Scanner();
            $all_platform_keys = array_keys($scanner->get_patterns());
            $found = $scanner->extract_videos_from_text($post->post_content, $all_platform_keys);
            $settings = get_option('vsf_settings', array());
            $meta_keys = isset($settings['scan_meta_keys']) ? trim($settings['scan_meta_keys']) : '';
            if (!empty($meta_keys)) {
                $keys = array_map('trim', explode(',', $meta_keys));
                foreach ($keys as $key) {
                    if (empty($key)) continue;
                    $meta_val = get_post_meta($post_id, $key, true);
                    if (is_string($meta_val) && !empty($meta_val)) {
                        $found = array_merge($found, $scanner->extract_videos_from_text($meta_val, $all_platform_keys));
                    }
                }
            }
            $found_urls = wp_list_pluck($found, 'url');
            $found_urls_raw = array_map('esc_url_raw', $found_urls);
            foreach ($existing as $row) {
                if (!in_array($row['video_url'], $found_urls, true) && !in_array(esc_url_raw($row['video_url']), $found_urls_raw, true)) {
                    Video_Scanner_Fix_Logger::delete_log($row['id']);
                }
            }
        }
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
