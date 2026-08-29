<?php
if (!defined('ABSPATH')) {
    exit;
}

class Video_Scanner_Fix_Admin {

    public function add_menu_page() {
        add_menu_page(
            __('Video Scanner Fix', 'video-scanner-fix'),
            __('Video Scanner', 'video-scanner-fix'),
            'manage_options',
            'video-scanner-fix',
            array($this, 'render_admin_page'),
            'dashicons-video-alt3',
            80
        );
    }

    public function add_dashboard_widget() {
        $settings = get_option('vsf_settings', array());
        $show_widget = !isset($settings['show_dashboard_widget']) || !empty($settings['show_dashboard_widget']);

        if ($show_widget) {
            wp_add_dashboard_widget(
                'vsf_dashboard_widget',
                __('Video Scanner Fix - Overview', 'video-scanner-fix'),
                array($this, 'render_dashboard_widget')
            );
        }
    }

    public static function get_last_scan_display() {
        $last_scan = get_option('vsf_last_scan_time');
        $timestamp = 0;

        if (!empty($last_scan)) {
            if (is_numeric($last_scan)) {
                $timestamp = intval($last_scan);
            } else {
                $timestamp = strtotime($last_scan);
            }
        }

        // If no option or invalid timestamp, fall back to MAX(created_at) from vsf_logs table.
        // NOTE: created_at is stored via current_time('mysql'), i.e. already in the SITE'S
        // local timezone. WordPress forces PHP's default timezone to UTC, so a plain
        // strtotime() on that string would misinterpret it as UTC and get shifted again
        // by wp_date() below, producing a value off by 2x the site's UTC offset.
        // We first convert the local MySQL string to a true GMT/UTC timestamp with
        // get_gmt_from_date(), then strtotime() it safely as UTC.
        if (!$timestamp) {
            global $wpdb;
            $table_name = Video_Scanner_Fix_Logger::get_table_name();
            if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name) {
                $max_created = $wpdb->get_var("SELECT MAX(created_at) FROM {$table_name}");
                if ($max_created) {
                    $gmt_created = function_exists('get_gmt_from_date') ? get_gmt_from_date($max_created) : $max_created;
                    $timestamp = strtotime($gmt_created . ' UTC');
                }
            }
        }

        if (!$timestamp) {
            return __('Never', 'video-scanner-fix');
        }

        $date_format = get_option('date_format') . ' ' . get_option('time_format');
        if (function_exists('wp_date')) {
            return wp_date($date_format, $timestamp);
        }
        return date_i18n($date_format, $timestamp);
    }

    public static function get_next_scan_display() {
        $settings = get_option('vsf_settings', array());
        if (empty($settings['cron_enabled'])) {
            return __('Disabled', 'video-scanner-fix');
        }

        $timestamp = wp_next_scheduled('vsf_cron_scan_event');
        if (!$timestamp) {
            Video_Scanner_Fix_Cron::register_schedule();
            $timestamp = wp_next_scheduled('vsf_cron_scan_event');
        }

        if (!$timestamp) {
            return __('Not Scheduled', 'video-scanner-fix');
        }

        $date_format = get_option('date_format') . ' ' . get_option('time_format');
        if (function_exists('wp_date')) {
            return wp_date($date_format, $timestamp);
        }
        return date_i18n($date_format, $timestamp);
    }

    public function render_dashboard_widget() {
        $stats = Video_Scanner_Fix_Logger::get_stats();
        $admin_url = admin_url('admin.php?page=video-scanner-fix');
        ?>
        <div class="vsf-dashboard-widget-content" style="padding:5px 0;">
            <p style="margin-top:0; font-weight:600; color:#444;">
                <?php _e('Current video scan status across your site:', 'video-scanner-fix'); ?>
            </p>
            <div style="display:grid; grid-template-columns: repeat(5, 1fr); gap:10px; margin:15px 0; text-align:center;">
                <div style="background:#f0f6fc; border:1px solid #c8d7e8; border-radius:6px; padding:10px 5px;">
                    <span style="font-size:18px; font-weight:bold; color:#1d2327; display:block;"><?php echo esc_html($stats['total']); ?></span>
                    <span style="font-size:11px; color:#646970; text-transform:uppercase; font-weight:600;"><?php _e('Total', 'video-scanner-fix'); ?></span>
                </div>
                <div style="background:#fcf0f2; border:1px solid #f2c7ce; border-radius:6px; padding:10px 5px;">
                    <span style="font-size:18px; font-weight:bold; color:#d63638; display:block;"><?php echo esc_html($stats['broken']); ?></span>
                    <span style="font-size:11px; color:#d63638; text-transform:uppercase; font-weight:600;"><?php _e('Broken', 'video-scanner-fix'); ?></span>
                </div>
                <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:6px; padding:10px 5px;">
                    <span style="font-size:18px; font-weight:bold; color:#008a20; display:block;"><?php echo esc_html($stats['valid']); ?></span>
                    <span style="font-size:11px; color:#008a20; text-transform:uppercase; font-weight:600;"><?php _e('Valid', 'video-scanner-fix'); ?></span>
                </div>
                <div style="background:#f3e8ff; border:1px solid #d8b4fe; border-radius:6px; padding:10px 5px;">
                    <span style="font-size:18px; font-weight:bold; color:#7e22ce; display:block;"><?php echo esc_html(isset($stats['geo_restricted']) ? $stats['geo_restricted'] : 0); ?></span>
                    <span style="font-size:11px; color:#7e22ce; text-transform:uppercase; font-weight:600;"><?php _e('Geo Blocked', 'video-scanner-fix'); ?></span>
                </div>
                <div style="background:#fffbeb; border:1px solid #fde68a; border-radius:6px; padding:10px 5px;">
                    <span style="font-size:18px; font-weight:bold; color:#b45309; display:block;"><?php echo esc_html(isset($stats['ignored']) ? $stats['ignored'] : 0); ?></span>
                    <span style="font-size:11px; color:#b45309; text-transform:uppercase; font-weight:600;"><?php _e('Ignored', 'video-scanner-fix'); ?></span>
                </div>
            </div>
            <div style="margin:10px 0; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:8px 12px; font-size:12px; color:#475569; display:flex; justify-content:space-between; flex-wrap:wrap; gap:8px;">
                <div>
                    <strong><?php _e('Last Scan:', 'video-scanner-fix'); ?></strong>
                    <span><?php echo esc_html(self::get_last_scan_display()); ?></span>
                </div>
                <div>
                    <strong><?php _e('Next Scheduled Scan:', 'video-scanner-fix'); ?></strong>
                    <span><?php echo esc_html(self::get_next_scan_display()); ?></span>
                </div>
            </div>
            <div style="display:flex; gap:10px; margin-top:12px; border-top:1px solid #f0f0f1; padding-top:10px; flex-wrap:wrap;">
                <a href="<?php echo esc_url($admin_url . '&tab=scanner'); ?>" class="button button-primary button-small"><?php _e('Run Manual Scan', 'video-scanner-fix'); ?></a>
                <a href="<?php echo esc_url($admin_url . '&tab=logs'); ?>" class="button button-secondary button-small"><?php _e('View Logs', 'video-scanner-fix'); ?></a>
                <a href="<?php echo esc_url($admin_url); ?>" class="button button-secondary button-small" style="margin-left:auto;"><?php _e('Settings', 'video-scanner-fix'); ?></a>
            </div>
        </div>
        <?php
    }

    public function add_plugin_action_links($links) {
        $settings_link = '<a href="' . esc_url(admin_url('admin.php?page=video-scanner-fix')) . '">' . __('Settings', 'video-scanner-fix') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    public function enqueue_assets($hook) {
        // Enqueue on settings page and post edit screens
        if ($hook === 'toplevel_page_video-scanner-fix' || $hook === 'post.php' || $hook === 'post-new.php') {
            wp_enqueue_style(
                'vsf-admin-style',
                VSF_PLUGIN_URL . 'assets/css/admin.css',
                array(),
                VSF_VERSION
            );

            wp_enqueue_script(
                'vsf-admin-script',
                VSF_PLUGIN_URL . 'assets/js/admin.js',
                array('jquery'),
                VSF_VERSION,
                true
            );

            wp_localize_script('vsf-admin-script', 'vsf_vars', array(
                'ajax_url'    => admin_url('admin-ajax.php'),
                'admin_nonce' => wp_create_nonce('vsf_admin_nonce'),
                'meta_nonce'  => wp_create_nonce('vsf_meta_nonce'),
                'strings'     => array(
                    'scanning'              => __('Scanning in progress...', 'video-scanner-fix'),
                    'scan_complete'         => __('Scan completed successfully!', 'video-scanner-fix'),
                    'confirm_clear'         => __('Are you sure you want to clear all log entries?', 'video-scanner-fix'),
                    'error_occurred'        => __('An error occurred while scanning.', 'video-scanner-fix'),
                    'no_logs_found'         => __('No log entries found matching criteria.', 'video-scanner-fix'),
                    'broken'                => __('Broken', 'video-scanner-fix'),
                    'geo_blocked'           => __('Geo Blocked', 'video-scanner-fix'),
                    'ignored'               => __('Ignored', 'video-scanner-fix'),
                    'valid'                 => __('Valid', 'video-scanner-fix'),
                    'recheck'               => __('Re-check', 'video-scanner-fix'),
                    'ignore'                => __('Ignore', 'video-scanner-fix'),
                    'no_title'              => __('(No Title)', 'video-scanner-fix'),
                    'edit_post'             => __('Edit Post', 'video-scanner-fix'),
                    'view_public'           => __('View Public', 'video-scanner-fix'),
                    'edit_post_in_admin'    => __('Edit Post in WP Admin', 'video-scanner-fix'),
                    'recheck_video_link'    => __('Re-check this video link', 'video-scanner-fix'),
                    'total_entries'         => __('Total entries: %1$s (Page %2$s of %3$s)', 'video-scanner-fix'),
                    'error_loading_logs'    => __('Error loading logs', 'video-scanner-fix'),
                    'ajax_error_logs'       => __('AJAX Error loading log history.', 'video-scanner-fix'),
                )
            ));
        }
    }

    public function add_meta_box($post_type) {
        $settings = get_option('vsf_settings', array());
        $allowed_types = isset($settings['scan_post_types']) ? (array)$settings['scan_post_types'] : array('post', 'page');

        if (in_array($post_type, $allowed_types)) {
            add_meta_box(
                'vsf_post_metabox',
                __('Video Scanner Fix - Single Post Scan', 'video-scanner-fix'),
                array($this, 'render_meta_box'),
                $post_type,
                'side',
                'high'
            );
        }
    }

    public function render_meta_box($post) {
        ?>
        <div class="vsf-metabox-wrapper">
            <p class="vsf-metabox-desc"><?php _e('Scan this post for broken video embeds and links instantly.', 'video-scanner-fix'); ?></p>
            <button type="button" class="button button-primary button-large vsf-btn-full" id="vsf-btn-scan-single" data-post-id="<?php echo esc_attr($post->ID); ?>">
                <span class="dashicons dashicons-search" style="margin-top: 3px;"></span> <?php _e('Scan Post Now', 'video-scanner-fix'); ?>
            </button>
            <div id="vsf-metabox-results" class="vsf-metabox-results-box" style="display:none;"></div>
        </div>
        <?php
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'video-scanner-fix'));
        }

        // Handle settings form save
        if (isset($_POST['vsf_save_settings']) && check_admin_referer('vsf_settings_save_action', 'vsf_settings_nonce')) {
            $this->save_settings();
            echo '<div class="notice notice-success is-dismissible"><p><strong>' . __('Settings updated successfully!', 'video-scanner-fix') . '</strong></p></div>';
        }

        $settings = get_option('vsf_settings', array());
        $stats    = Video_Scanner_Fix_Logger::get_stats();
        $logs     = Video_Scanner_Fix_Logger::get_logs(50);
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'dashboard';
        ?>
        <div class="wrap vsf-admin-wrap">
            <div class="vsf-header">
                <div class="vsf-header-title">
                    <span class="dashicons dashicons-video-alt3 vsf-header-icon"></span>
                    <h1>Video Scanner Fix <span class="vsf-version-badge">v<?php echo VSF_VERSION; ?></span></h1>
                </div>
                <div class="vsf-header-meta">
                    <span class="vsf-author-tag"><?php _e('Author:', 'video-scanner-fix'); ?> <strong>peopleinside</strong></span>
                </div>
            </div>

            <!-- Dashboard Navigation Tabs -->
            <h2 class="nav-tab-wrapper vsf-nav-tabs">
                <a href="?page=video-scanner-fix&tab=dashboard" class="nav-tab <?php echo $active_tab === 'dashboard' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-dashboard"></span> <?php _e('Dashboard', 'video-scanner-fix'); ?>
                </a>
                <a href="?page=video-scanner-fix&tab=scanner" class="nav-tab <?php echo $active_tab === 'scanner' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-controls-play"></span> <?php _e('Manual Scanner', 'video-scanner-fix'); ?>
                </a>
                <a href="?page=video-scanner-fix&tab=platforms" class="nav-tab <?php echo $active_tab === 'platforms' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-share"></span> <?php _e('Video Platforms', 'video-scanner-fix'); ?>
                </a>
                <a href="?page=video-scanner-fix&tab=filters" class="nav-tab <?php echo $active_tab === 'filters' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-filter"></span> <?php _e('Post & Field Filters', 'video-scanner-fix'); ?>
                </a>
                <a href="?page=video-scanner-fix&tab=actions" class="nav-tab <?php echo $active_tab === 'actions' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-admin-settings"></span> <?php _e('Actions & Alerts', 'video-scanner-fix'); ?>
                </a>
                <a href="?page=video-scanner-fix&tab=logs" class="nav-tab <?php echo $active_tab === 'logs' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-list-view"></span> <?php _e('Log History', 'video-scanner-fix'); ?>
                </a>
            </h2>

            <div class="vsf-tab-content">
                <div id="vsf-tab-dashboard" class="vsf-tab-panel" style="<?php echo $active_tab === 'dashboard' ? '' : 'display:none;'; ?>">
                    <?php $this->render_dashboard_tab($stats, $settings); ?>
                </div>
                <div id="vsf-tab-scanner" class="vsf-tab-panel" style="<?php echo $active_tab === 'scanner' ? '' : 'display:none;'; ?>">
                    <?php $this->render_scanner_tab($settings); ?>
                </div>
                <div id="vsf-tab-platforms" class="vsf-tab-panel" style="<?php echo $active_tab === 'platforms' ? '' : 'display:none;'; ?>">
                    <?php $this->render_platforms_tab($settings); ?>
                </div>
                <div id="vsf-tab-filters" class="vsf-tab-panel" style="<?php echo $active_tab === 'filters' ? '' : 'display:none;'; ?>">
                    <?php $this->render_filters_tab($settings); ?>
                </div>
                <div id="vsf-tab-actions" class="vsf-tab-panel" style="<?php echo $active_tab === 'actions' ? '' : 'display:none;'; ?>">
                    <?php $this->render_actions_tab($settings); ?>
                </div>
                <div id="vsf-tab-logs" class="vsf-tab-panel" style="<?php echo $active_tab === 'logs' ? '' : 'display:none;'; ?>">
                    <?php $this->render_logs_tab($logs); ?>
                </div>
            </div>
        </div>
        <?php
    }

    protected function render_dashboard_tab($stats, $settings) {
        ?>
        <div class="vsf-dashboard-grid">
            <div class="vsf-card vsf-stat-card vsf-stat-total vsf-stat-clickable" data-filter="all" style="cursor:pointer;" title="<?php _e('Click to view all logs', 'video-scanner-fix'); ?>">
                <div class="vsf-stat-icon"><span class="dashicons dashicons-video-alt2"></span></div>
                <div class="vsf-stat-data">
                    <span class="vsf-stat-number"><?php echo esc_html($stats['total']); ?></span>
                    <span class="vsf-stat-label"><?php _e('Total Videos Checked', 'video-scanner-fix'); ?></span>
                </div>
            </div>

            <div class="vsf-card vsf-stat-card vsf-stat-broken vsf-stat-clickable" data-filter="broken" style="cursor:pointer;" title="<?php _e('Click to view broken videos in logs', 'video-scanner-fix'); ?>">
                <div class="vsf-stat-icon"><span class="dashicons dashicons-warning"></span></div>
                <div class="vsf-stat-data">
                    <span class="vsf-stat-number"><?php echo esc_html($stats['broken']); ?></span>
                    <span class="vsf-stat-label"><?php _e('Broken Links Detected', 'video-scanner-fix'); ?></span>
                </div>
            </div>

            <div class="vsf-card vsf-stat-card vsf-stat-valid vsf-stat-clickable" data-filter="valid" style="cursor:pointer;" title="<?php _e('Click to view valid videos in logs', 'video-scanner-fix'); ?>">
                <div class="vsf-stat-icon"><span class="dashicons dashicons-yes-alt"></span></div>
                <div class="vsf-stat-data">
                    <span class="vsf-stat-number"><?php echo esc_html($stats['valid']); ?></span>
                    <span class="vsf-stat-label"><?php _e('Valid Video Embeds', 'video-scanner-fix'); ?></span>
                </div>
            </div>

            <div class="vsf-card vsf-stat-card vsf-stat-clickable" data-filter="geo_restricted" style="border-left-color: #9333ea; cursor:pointer;" title="<?php _e('Click to view geo-restricted videos in logs', 'video-scanner-fix'); ?>">
                <div class="vsf-stat-icon"><span class="dashicons dashicons-admin-site-alt3" style="color:#9333ea;"></span></div>
                <div class="vsf-stat-data">
                    <span class="vsf-stat-number"><?php echo esc_html(isset($stats['geo_restricted']) ? $stats['geo_restricted'] : 0); ?></span>
                    <span class="vsf-stat-label"><?php _e('Geo-Restricted Videos', 'video-scanner-fix'); ?></span>
                </div>
            </div>

            <div class="vsf-card vsf-stat-card vsf-stat-cron vsf-stat-clickable" data-filter="ignored" style="border-left-color: #f59f00; cursor:pointer;" title="<?php _e('Click to view ignored videos in logs', 'video-scanner-fix'); ?>">
                <div class="vsf-stat-icon"><span class="dashicons dashicons-hidden"></span></div>
                <div class="vsf-stat-data">
                    <span class="vsf-stat-number"><?php echo esc_html(isset($stats['ignored']) ? $stats['ignored'] : 0); ?></span>
                    <span class="vsf-stat-label"><?php _e('Ignored Videos', 'video-scanner-fix'); ?></span>
                </div>
            </div>

            <div class="vsf-card vsf-stat-card vsf-stat-cron">
                <div class="vsf-stat-icon"><span class="dashicons dashicons-clock"></span></div>
                <div class="vsf-stat-data">
                    <span class="vsf-stat-status"><?php echo !empty($settings['cron_enabled']) ? __('Active', 'video-scanner-fix') : __('Disabled', 'video-scanner-fix'); ?></span>
                    <span class="vsf-stat-label"><?php _e('WP-Cron Schedule', 'video-scanner-fix'); ?></span>
                </div>
            </div>
        </div>

        <div style="display:flex; gap:20px; background:#f8fafc; border:1px solid #cbd5e1; border-radius:8px; padding:14px 20px; margin-top:20px; align-items:center; flex-wrap:wrap;">
            <div style="display:flex; align-items:center; gap:10px;">
                <span class="dashicons dashicons-calendar-alt" style="color:#0284c7; font-size:22px; width:22px; height:22px;"></span>
                <div>
                    <div style="font-size:11px; text-transform:uppercase; color:#64748b; font-weight:600;"><?php _e('Last Scan Date & Time', 'video-scanner-fix'); ?></div>
                    <div style="font-size:15px; font-weight:700; color:#0f172a;" id="vsf-last-scan-display"><?php echo esc_html(self::get_last_scan_display()); ?></div>
                </div>
            </div>
            <div style="display:flex; align-items:center; gap:10px; margin-left:auto;">
                <span class="dashicons dashicons-backup" style="color:#16a34a; font-size:22px; width:22px; height:22px;"></span>
                <div>
                    <div style="font-size:11px; text-transform:uppercase; color:#64748b; font-weight:600;"><?php _e('Next Scheduled Scan', 'video-scanner-fix'); ?></div>
                    <div style="font-size:15px; font-weight:700; color:#0f172a;" id="vsf-next-scan-display"><?php echo esc_html(self::get_next_scan_display()); ?></div>
                </div>
            </div>
        </div>

        <div class="vsf-card vsf-welcome-card" style="margin-top:20px;">
            <h2><?php _e('Quick Start & Overview', 'video-scanner-fix'); ?></h2>
            <p><?php _e('Video Scanner Fix validates video links across your WordPress posts, pages, and custom meta keys. Runs smoothly without freezing the WordPress admin dashboard.', 'video-scanner-fix'); ?></p>
            <div class="vsf-actions-bar">
                <a href="?page=video-scanner-fix&tab=scanner" class="button button-primary button-hero">
                    <span class="dashicons dashicons-controls-play" style="margin-top: 5px;"></span> <?php _e('Start Manual Site Scan', 'video-scanner-fix'); ?>
                </a>
                <a href="?page=video-scanner-fix&tab=platforms" class="button button-secondary button-hero">
                    <span class="dashicons dashicons-admin-settings" style="margin-top: 5px;"></span> <?php _e('Configure Platforms & Keys', 'video-scanner-fix'); ?>
                </a>
            </div>
        </div>
        <?php
    }

    protected function render_scanner_tab($settings) {
        ?>
        <div class="vsf-card">
            <h2><span class="dashicons dashicons-controls-play"></span> <?php _e('Real-Time Manual Site Scanner', 'video-scanner-fix'); ?></h2>
            <p><?php _e('Run a complete manual scan across your WordPress site posts. Batched requests prevent timeouts and server lockups.', 'video-scanner-fix'); ?></p>

            <div class="vsf-scanner-controls">
                <div class="vsf-form-group-inline">
                    <label for="vsf-batch-size"><strong><?php _e('Posts per Batch:', 'video-scanner-fix'); ?></strong></label>
                    <input type="number" id="vsf-batch-size" value="<?php echo esc_attr(isset($settings['batch_size']) ? $settings['batch_size'] : 15); ?>" min="1" max="100" class="small-text" />
                </div>
                <button type="button" class="button button-primary button-large" id="vsf-start-scan-btn">
                    <span class="dashicons dashicons-search"></span> <?php _e('Start Batch Scan Now', 'video-scanner-fix'); ?>
                </button>
                <button type="button" class="button button-secondary button-large" id="vsf-stop-scan-btn" style="display:none;">
                    <span class="dashicons dashicons-dismiss"></span> <?php _e('Stop Scan', 'video-scanner-fix'); ?>
                </button>
            </div>

            <!-- Progress Bar -->
            <div id="vsf-progress-container" style="display:none; margin-top:20px;">
                <div class="vsf-progress-bar-bg">
                    <div id="vsf-progress-bar-fill" class="vsf-progress-bar-fill" style="width: 0%;"></div>
                </div>
                <div class="vsf-progress-status">
                    <span id="vsf-progress-text"><?php _e('Initializing scan...', 'video-scanner-fix'); ?></span>
                    <strong id="vsf-progress-percent">0%</strong>
                </div>
            </div>

            <!-- Realtime Scan Output Box -->
            <div id="vsf-scan-results-box" class="vsf-log-box" style="margin-top:20px; display:none;">
                <div class="vsf-log-header">
                    <h3><?php _e('Live Scan Results', 'video-scanner-fix'); ?></h3>
                    <span class="vsf-live-tag"><?php _e('Live', 'video-scanner-fix'); ?></span>
                </div>
                <div id="vsf-scan-log-feed" class="vsf-log-feed"></div>
            </div>
        </div>
        <?php
    }

    protected function render_platforms_tab($settings) {
        $platforms = array(
            'youtube'     => 'YouTube (Videos, Shorts, Playlists)',
            'vimeo'       => 'Vimeo (Player & Standard Links)',
            'dailymotion' => 'DailyMotion (Embeds & Links)',
            'googledrive' => 'Google Drive (Shared Video Files)',
            'archive'     => 'Archive.org (Public Media Embeds)',
            'doodstream'  => 'DoodStream (Embedded Players)',
            'mixcloud'    => 'MixCloud (Audio/Video Streams)'
        );
        $enabled = isset($settings['enabled_platforms']) ? (array)$settings['enabled_platforms'] : array_keys($platforms);
        ?>
        <form method="post" action="">
            <?php wp_nonce_field('vsf_settings_save_action', 'vsf_settings_nonce'); ?>
            <input type="hidden" name="vsf_form_tab" value="platforms" />
            <div class="vsf-card">
                <h2><span class="dashicons dashicons-share"></span> <?php _e('Video Platforms & API Settings', 'video-scanner-fix'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Enabled Platforms', 'video-scanner-fix'); ?></th>
                        <td>
                            <fieldset>
                                <?php foreach ($platforms as $key => $label): ?>
                                    <label style="display:block; margin-bottom:8px;">
                                        <input type="checkbox" name="vsf_platforms[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $enabled)); ?> />
                                        <?php echo esc_html($label); ?>
                                    </label>
                                <?php endforeach; ?>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="youtube_api_key"><?php _e('YouTube Data API v3 Key', 'video-scanner-fix'); ?></label></th>
                        <td>
                            <input type="text" name="youtube_api_key" id="youtube_api_key" value="<?php echo esc_attr(isset($settings['youtube_api_key']) ? $settings['youtube_api_key'] : ''); ?>" class="regular-text" />
                            <p class="description"><?php _e('Optional. If provided, enables instant official YouTube API quota checks.', 'video-scanner-fix'); ?></p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" name="vsf_save_settings" class="button button-primary" value="<?php _e('Save Platform Settings', 'video-scanner-fix'); ?>" />
                </p>
            </div>
        </form>
        <?php
    }

    protected function render_filters_tab($settings) {
        $all_post_types = get_post_types(array('public' => true), 'objects');
        $selected_types = isset($settings['scan_post_types']) ? (array)$settings['scan_post_types'] : array('post', 'page');
        $selected_statuses = isset($settings['scan_post_statuses']) ? (array)$settings['scan_post_statuses'] : array('publish');
        ?>
        <form method="post" action="">
            <?php wp_nonce_field('vsf_settings_save_action', 'vsf_settings_nonce'); ?>
            <input type="hidden" name="vsf_form_tab" value="filters" />
            <div class="vsf-card">
                <h2><span class="dashicons dashicons-filter"></span> <?php _e('Post Types & Field Filters', 'video-scanner-fix'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Post Types to Scan', 'video-scanner-fix'); ?></th>
                        <td>
                            <fieldset>
                                <?php foreach ($all_post_types as $pt): ?>
                                    <label style="display:block; margin-bottom:6px;">
                                        <input type="checkbox" name="vsf_post_types[]" value="<?php echo esc_attr($pt->name); ?>" <?php checked(in_array($pt->name, $selected_types)); ?> />
                                        <?php echo esc_html($pt->label); ?> (<code><?php echo esc_html($pt->name); ?></code>)
                                    </label>
                                <?php endforeach; ?>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Post Statuses', 'video-scanner-fix'); ?></th>
                        <td>
                            <fieldset>
                                <label><input type="checkbox" name="vsf_statuses[]" value="publish" <?php checked(in_array('publish', $selected_statuses)); ?> /> <?php _e('Published', 'video-scanner-fix'); ?></label><br/>
                                <label><input type="checkbox" name="vsf_statuses[]" value="draft" <?php checked(in_array('draft', $selected_statuses)); ?> /> <?php _e('Drafts', 'video-scanner-fix'); ?></label><br/>
                                <label><input type="checkbox" name="vsf_statuses[]" value="pending" <?php checked(in_array('pending', $selected_statuses)); ?> /> <?php _e('Pending Review', 'video-scanner-fix'); ?></label>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="scan_meta_keys"><?php _e('Custom Meta Keys', 'video-scanner-fix'); ?></label></th>
                        <td>
                            <input type="text" name="scan_meta_keys" id="scan_meta_keys" value="<?php echo esc_attr(isset($settings['scan_meta_keys']) ? $settings['scan_meta_keys'] : ''); ?>" class="large-text" placeholder="video_url, embed_code, custom_video" />
                            <p class="description"><?php _e('Comma separated list of custom field keys to scan for video links in addition to post content.', 'video-scanner-fix'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ignored_videos"><?php _e('Ignored Videos List', 'video-scanner-fix'); ?></label></th>
                        <td>
                            <textarea name="ignored_videos" id="ignored_videos" rows="5" class="large-text code" placeholder="https://www.youtube.com/watch?v=EXAMPLE&#10;https://vimeo.com/123456789"><?php echo esc_textarea(isset($settings['ignored_videos']) ? $settings['ignored_videos'] : ''); ?></textarea>
                            <p class="description"><?php _e('Enter video URLs or Video IDs to ignore during scans (one per line or separated by newlines). These will be skipped and marked as Ignored.', 'video-scanner-fix'); ?></p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" name="vsf_save_settings" class="button button-primary" value="<?php _e('Save Filter Settings', 'video-scanner-fix'); ?>" />
                </p>
            </div>
        </form>
        <?php
    }

    protected function render_actions_tab($settings) {
        $status_action = isset($settings['on_broken_status']) ? $settings['on_broken_status'] : 'no_change';
        $cron_enabled  = !empty($settings['cron_enabled']);
        $cron_interval = isset($settings['cron_interval']) ? $settings['cron_interval'] : 'daily';
        ?>
        <form method="post" action="">
            <?php wp_nonce_field('vsf_settings_save_action', 'vsf_settings_nonce'); ?>
            <input type="hidden" name="vsf_form_tab" value="actions" />
            <div class="vsf-card">
                <h2><span class="dashicons dashicons-admin-settings"></span> <?php _e('Automatic Actions & Alerts', 'video-scanner-fix'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="on_broken_status"><?php _e('Action on Broken Video Post', 'video-scanner-fix'); ?></label></th>
                        <td>
                            <select name="on_broken_status" id="on_broken_status">
                                <option value="no_change" <?php selected($status_action, 'no_change'); ?>><?php _e('Do Nothing (Log Only)', 'video-scanner-fix'); ?></option>
                                <option value="draft" <?php selected($status_action, 'draft'); ?>><?php _e('Change Post Status to Draft', 'video-scanner-fix'); ?></option>
                                <option value="private" <?php selected($status_action, 'private'); ?>><?php _e('Change Post Status to Private', 'video-scanner-fix'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="on_broken_tag"><?php _e('Add Tag to Broken Post', 'video-scanner-fix'); ?></label></th>
                        <td>
                            <input type="text" name="on_broken_tag" id="on_broken_tag" value="<?php echo esc_attr(isset($settings['on_broken_tag']) ? $settings['on_broken_tag'] : ''); ?>" class="regular-text" placeholder="broken-video" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Email Alerts', 'video-scanner-fix'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="notify_on_broken" value="1" <?php checked(!empty($settings['notify_on_broken'])); ?> />
                                <?php _e('Send a single summary email report at the end of automated scans (WP-Cron)', 'video-scanner-fix'); ?>
                            </label>
                            <p class="description"><?php _e('Note: Only 1 summary email is sent upon completion of automated background scans. Manual scans do not trigger email notifications.', 'video-scanner-fix'); ?></p>
                            <br/>
                            <label>
                                <input type="checkbox" name="notify_ignore_geo_restricted" value="1" <?php checked(!empty($settings['notify_ignore_geo_restricted'])); ?> />
                                <?php _e('Do NOT include geo-restricted videos in email reports (Mute geo-block alerts)', 'video-scanner-fix'); ?>
                            </label>
                            <br/><br/>
                            <input type="email" name="notify_email" value="<?php echo esc_attr(isset($settings['notify_email']) ? $settings['notify_email'] : get_option('admin_email')); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Country Geo-Restriction Check', 'video-scanner-fix'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="check_geo_restriction" value="1" <?php checked(!isset($settings['check_geo_restriction']) || !empty($settings['check_geo_restriction'])); ?> />
                                <?php _e('Detect videos blocked/restricted in specific target countries', 'video-scanner-fix'); ?>
                            </label>
                            <br/><br/>
                            <label for="target_country"><strong><?php _e('Target Country Code (ISO 2-letter):', 'video-scanner-fix'); ?></strong></label><br/>
                            <input type="text" name="target_country" id="target_country" value="<?php echo esc_attr(isset($settings['target_country']) && !empty($settings['target_country']) ? $settings['target_country'] : 'IT'); ?>" class="small-text" style="text-transform:uppercase; font-weight:bold;" maxlength="2" placeholder="IT" />
                            <p class="description"><?php _e('Default: IT (Italy). Checks YouTube API regionRestrictions to flag videos non-playable in Italy or your designated region.', 'video-scanner-fix'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Automated WP-Cron Scan', 'video-scanner-fix'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="cron_enabled" value="1" <?php checked($cron_enabled); ?> />
                                <?php _e('Enable background recurring scan', 'video-scanner-fix'); ?>
                            </label>
                            <br/><br/>
                            <select name="cron_interval">
                                <option value="hourly" <?php selected($cron_interval, 'hourly'); ?>><?php _e('Hourly', 'video-scanner-fix'); ?></option>
                                <option value="twicedaily" <?php selected($cron_interval, 'twicedaily'); ?>><?php _e('Twice Daily', 'video-scanner-fix'); ?></option>
                                <option value="daily" <?php selected($cron_interval, 'daily'); ?>><?php _e('Daily', 'video-scanner-fix'); ?></option>
                                <option value="weekly" <?php selected($cron_interval, 'weekly'); ?>><?php _e('Weekly', 'video-scanner-fix'); ?></option>
                                <option value="monthly" <?php selected($cron_interval, 'monthly'); ?>><?php _e('Monthly (30 Days)', 'video-scanner-fix'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('WP Dashboard Widget', 'video-scanner-fix'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="show_dashboard_widget" value="1" <?php checked(!isset($settings['show_dashboard_widget']) || !empty($settings['show_dashboard_widget'])); ?> />
                                <?php _e('Display Video Scanner summary widget on the main WordPress Admin Dashboard', 'video-scanner-fix'); ?>
                            </label>
                            <p class="description"><?php _e('Active by default. Displays real-time counts of scanned, valid, broken, and ignored videos right on the WP Admin homepage.', 'video-scanner-fix'); ?></p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" name="vsf_save_settings" class="button button-primary" value="<?php _e('Save Actions & Schedule', 'video-scanner-fix'); ?>" />
                </p>
            </div>
        </form>
        <?php
    }

    protected function render_logs_tab($logs) {
        $total_items = Video_Scanner_Fix_Logger::get_logs_count('all', '');
        ?>
        <div class="vsf-card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; flex-wrap:wrap; gap:10px;">
                <h2><span class="dashicons dashicons-list-view"></span> <?php _e('Scan History & Logs', 'video-scanner-fix'); ?></h2>
                <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                    <button type="button" class="button button-secondary" id="vsf-refresh-logs-btn">
                        <span class="dashicons dashicons-update" style="margin-top:3px;"></span> <?php _e('Refresh Logs', 'video-scanner-fix'); ?>
                    </button>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=vsf_export_logs_csv'), 'vsf_export_logs_csv_action', 'vsf_export_nonce')); ?>" class="button button-secondary">
                        <span class="dashicons dashicons-media-spreadsheet" style="margin-top:3px;"></span> <?php _e('Export CSV', 'video-scanner-fix'); ?>
                    </a>
                    <button type="button" class="button button-secondary" id="vsf-clear-logs-btn">
                        <span class="dashicons dashicons-trash"></span> <?php _e('Clear Log History', 'video-scanner-fix'); ?>
                    </button>
                </div>
            </div>

            <!-- Controls bar: Filters, Search & Items per page -->
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; background:#f8fafc; padding:12px; border:1px solid #e2e8f0; border-radius:6px; flex-wrap:wrap; gap:10px;">
                <div class="vsf-log-filters button-group">
                    <button type="button" class="button button-secondary vsf-log-filter-btn active" data-filter="all"><?php _e('All', 'video-scanner-fix'); ?></button>
                    <button type="button" class="button button-secondary vsf-log-filter-btn" data-filter="broken"><?php _e('Broken', 'video-scanner-fix'); ?></button>
                    <button type="button" class="button button-secondary vsf-log-filter-btn" data-filter="valid"><?php _e('Valid', 'video-scanner-fix'); ?></button>
                    <button type="button" class="button button-secondary vsf-log-filter-btn" data-filter="geo_restricted"><?php _e('Geo-Restricted', 'video-scanner-fix'); ?></button>
                    <button type="button" class="button button-secondary vsf-log-filter-btn" data-filter="ignored"><?php _e('Ignored', 'video-scanner-fix'); ?></button>
                </div>

                <div style="display:flex; gap:10px; align-items:center;">
                    <input type="text" id="vsf-log-search-input" placeholder="<?php _e('Search post title, URL...', 'video-scanner-fix'); ?>" class="regular-text" style="height:30px;" />
                    
                    <select id="vsf-log-limit-select" style="height:30px;">
                        <option value="25">25 <?php _e('per page', 'video-scanner-fix'); ?></option>
                        <option value="50" selected>50 <?php _e('per page', 'video-scanner-fix'); ?></option>
                        <option value="100">100 <?php _e('per page', 'video-scanner-fix'); ?></option>
                        <option value="250">250 <?php _e('per page', 'video-scanner-fix'); ?></option>
                        <option value="500">500 <?php _e('per page', 'video-scanner-fix'); ?></option>
                        <option value="-1"><?php _e('Show All', 'video-scanner-fix'); ?></option>
                    </select>
                </div>
            </div>

            <table class="wp-list-table widefat fixed striped" id="vsf-logs-table">
                <thead>
                    <tr>
                        <th style="width:110px;"><?php _e('Status', 'video-scanner-fix'); ?></th>
                        <th style="width:110px;"><?php _e('Platform', 'video-scanner-fix'); ?></th>
                        <th><?php _e('Post Title', 'video-scanner-fix'); ?></th>
                        <th><?php _e('Video URL', 'video-scanner-fix'); ?></th>
                        <th><?php _e('Response / Note', 'video-scanner-fix'); ?></th>
                        <th style="width:140px;"><?php _e('Date', 'video-scanner-fix'); ?></th>
                    </tr>
                </thead>
                <tbody id="vsf-logs-tbody">
                    <?php if (empty($logs)): ?>
                        <tr class="vsf-no-logs">
                            <td colspan="6" style="text-align:center; padding:20px; color:#888;">
                                <?php _e('No log entries found. Run a manual scan to inspect video links.', 'video-scanner-fix'); ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): 
                            $edit_link = $log['post_id'] ? get_edit_post_link($log['post_id'], 'raw') : '';
                            $permalink = $log['post_id'] ? get_permalink($log['post_id']) : '';
                        ?>
                            <tr data-status="<?php echo esc_attr(strtolower($log['status'])); ?>">
                                <td>
                                    <?php if ($log['status'] === 'broken'): ?>
                                        <span class="vsf-badge vsf-badge-danger"><?php _e('Broken', 'video-scanner-fix'); ?></span>
                                    <?php elseif ($log['status'] === 'geo_restricted'): ?>
                                        <span class="vsf-badge" style="background:#f3e8ff; color:#7e22ce; border:1px solid #d8b4fe;"><?php _e('Geo Blocked', 'video-scanner-fix'); ?></span>
                                    <?php elseif ($log['status'] === 'ignored'): ?>
                                        <span class="vsf-badge vsf-badge-warning"><?php _e('Ignored', 'video-scanner-fix'); ?></span>
                                    <?php else: ?>
                                        <span class="vsf-badge vsf-badge-success"><?php _e('Valid', 'video-scanner-fix'); ?></span>
                                    <?php endif; ?>

                                    <div style="margin-top:6px; display:flex; gap:4px; flex-wrap:wrap;">
                                        <button type="button" class="button button-small vsf-recheck-btn" data-log-id="<?php echo esc_attr($log['id']); ?>" data-post-id="<?php echo esc_attr($log['post_id']); ?>" data-url="<?php echo esc_attr($log['video_url']); ?>" title="<?php _e('Re-check this video link', 'video-scanner-fix'); ?>">
                                            <span class="dashicons dashicons-update" style="font-size:12px; width:12px; height:12px; line-height:20px;"></span> <?php _e('Re-check', 'video-scanner-fix'); ?>
                                        </button>
                                        <?php if ($log['status'] !== 'ignored'): ?>
                                            <button type="button" class="button button-small vsf-ignore-video-btn" data-url="<?php echo esc_attr($log['video_url']); ?>" data-log-id="<?php echo esc_attr($log['id']); ?>">
                                                <?php _e('Ignore', 'video-scanner-fix'); ?>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td><strong><?php echo esc_html(strtoupper($log['platform'])); ?></strong></td>
                                <td>
                                    <?php if ($log['post_id']): ?>
                                        <a href="<?php echo esc_url($edit_link); ?>" target="_blank" title="<?php _e('Edit Post in WP Admin', 'video-scanner-fix'); ?>">
                                            <strong><?php echo esc_html($log['post_title'] ? $log['post_title'] : __('(No Title)', 'video-scanner-fix')); ?></strong>
                                        </a>
                                        <div style="margin-top:4px; display:flex; gap:4px; align-items:center;">
                                            <?php if ($edit_link): ?>
                                                <a href="<?php echo esc_url($edit_link); ?>" target="_blank" class="button button-small" style="font-size:11px; padding:0 6px; height:22px; line-height:20px;">
                                                    <span class="dashicons dashicons-edit" style="font-size:12px; vertical-align:middle; width:12px; height:12px; line-height:20px;"></span> <?php _e('Edit Post', 'video-scanner-fix'); ?>
                                                </a>
                                            <?php endif; ?>
                                            <?php if ($permalink): ?>
                                                <a href="<?php echo esc_url($permalink); ?>" target="_blank" class="button button-small" style="font-size:11px; padding:0 6px; height:22px; line-height:20px;">
                                                    <span class="dashicons dashicons-external" style="font-size:12px; vertical-align:middle; width:12px; height:12px; line-height:20px;"></span> <?php _e('View Public', 'video-scanner-fix'); ?>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <?php echo esc_html($log['post_title']); ?>
                                    <?php endif; ?>
                                </td>
                                <td><a href="<?php echo esc_url($log['video_url']); ?>" target="_blank" class="vsf-url-truncate"><?php echo esc_html($log['video_url']); ?></a></td>
                                <td><code><?php echo esc_html($log['response_code'] . ' - ' . $log['error_message']); ?></code></td>
                                <td><?php echo esc_html($log['created_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Pagination Bar -->
            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:15px; padding-top:10px; border-top:1px solid #eee; flex-wrap:wrap; gap:10px;">
                <div id="vsf-pagination-info" style="font-weight:600; color:#555;">
                    <?php printf(__('Total entries in log: %d', 'video-scanner-fix'), $total_items); ?>
                </div>
                <div class="vsf-pagination-controls" style="display:flex; gap:5px; align-items:center;">
                    <button type="button" class="button button-secondary button-small vsf-page-nav-btn" id="vsf-page-first" data-page="1" disabled>&laquo;</button>
                    <button type="button" class="button button-secondary button-small vsf-page-nav-btn" id="vsf-page-prev" data-page="1" disabled>&lsaquo;</button>
                    <span style="margin:0 8px; font-size:13px;">
                        <?php _e('Page', 'video-scanner-fix'); ?> <strong id="vsf-page-current-num">1</strong> <?php _e('of', 'video-scanner-fix'); ?> <strong id="vsf-page-total-num">1</strong>
                    </span>
                    <button type="button" class="button button-secondary button-small vsf-page-nav-btn" id="vsf-page-next" data-page="1">&rsaquo;</button>
                    <button type="button" class="button button-secondary button-small vsf-page-nav-btn" id="vsf-page-last" data-page="1">&raquo;</button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Streams the full Log History table as a CSV download.
     * Hooked to admin-post.php?action=vsf_export_logs_csv
     */
    public function export_logs_csv() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to perform this action.', 'video-scanner-fix'));
        }

        check_admin_referer('vsf_export_logs_csv_action', 'vsf_export_nonce');

        // limit = 0 -> Video_Scanner_Fix_Logger::get_logs() returns every row, unpaginated.
        $logs = Video_Scanner_Fix_Logger::get_logs(0, 0, 'all', '');

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=video-scanner-fix-logs-' . gmdate('Y-m-d') . '.csv');

        $output = fopen('php://output', 'w');

        // UTF-8 BOM so the file opens correctly with accented characters in Excel.
        fwrite($output, "\xEF\xBB\xBF");

        fputcsv($output, array(
            __('ID', 'video-scanner-fix'),
            __('Status', 'video-scanner-fix'),
            __('Platform', 'video-scanner-fix'),
            __('Post ID', 'video-scanner-fix'),
            __('Post Title', 'video-scanner-fix'),
            __('Video URL', 'video-scanner-fix'),
            __('Response Code', 'video-scanner-fix'),
            __('Error Message', 'video-scanner-fix'),
            __('Checked At', 'video-scanner-fix'),
        ));

        foreach ($logs as $log) {
            fputcsv($output, array(
                $log['id'],
                $log['status'],
                $log['platform'],
                $log['post_id'],
                $log['post_title'],
                $log['video_url'],
                $log['response_code'],
                $log['error_message'],
                $log['created_at'],
            ));
        }

        fclose($output);
        exit;
    }

    protected function save_settings() {
        $settings = get_option('vsf_settings', array());
        $previous_cron_enabled  = !empty($settings['cron_enabled']);
        $previous_cron_interval = isset($settings['cron_interval']) ? $settings['cron_interval'] : 'daily';
        $tab = isset($_POST['vsf_form_tab']) ? sanitize_text_field($_POST['vsf_form_tab']) : '';

        // Save platform settings if platforms form submitted
        if ($tab === 'platforms' || isset($_POST['youtube_api_key']) || isset($_POST['vsf_platforms'])) {
            $settings['enabled_platforms'] = isset($_POST['vsf_platforms']) ? array_map('sanitize_text_field', $_POST['vsf_platforms']) : array();
            $settings['youtube_api_key']   = isset($_POST['youtube_api_key']) ? sanitize_text_field($_POST['youtube_api_key']) : '';
        }

        // Save filter settings if filters form submitted
        if ($tab === 'filters' || isset($_POST['scan_meta_keys']) || isset($_POST['ignored_videos']) || isset($_POST['vsf_post_types'])) {
            $settings['scan_post_types']    = isset($_POST['vsf_post_types']) ? array_map('sanitize_text_field', $_POST['vsf_post_types']) : array();
            $settings['scan_post_statuses'] = isset($_POST['vsf_statuses']) ? array_map('sanitize_text_field', $_POST['vsf_statuses']) : array();
            $settings['scan_meta_keys']     = isset($_POST['scan_meta_keys']) ? sanitize_text_field($_POST['scan_meta_keys']) : '';
            $settings['ignored_videos']     = isset($_POST['ignored_videos']) ? sanitize_textarea_field($_POST['ignored_videos']) : '';
        }

        // Save action settings if actions form submitted
        if ($tab === 'actions' || isset($_POST['on_broken_status']) || isset($_POST['target_country']) || isset($_POST['notify_email'])) {
            $settings['on_broken_status']   = isset($_POST['on_broken_status']) ? sanitize_text_field($_POST['on_broken_status']) : 'no_change';
            $settings['on_broken_tag']      = isset($_POST['on_broken_tag']) ? sanitize_text_field($_POST['on_broken_tag']) : '';
            $settings['notify_on_broken']   = isset($_POST['notify_on_broken']) ? 1 : 0;
            $settings['notify_ignore_geo_restricted'] = isset($_POST['notify_ignore_geo_restricted']) ? 1 : 0;
            $settings['notify_email']       = isset($_POST['notify_email']) ? sanitize_email($_POST['notify_email']) : get_option('admin_email');
            $settings['check_geo_restriction'] = isset($_POST['check_geo_restriction']) ? 1 : 0;
            $settings['target_country']     = isset($_POST['target_country']) ? strtoupper(sanitize_text_field($_POST['target_country'])) : 'IT';
            $settings['cron_enabled']       = isset($_POST['cron_enabled']) ? 1 : 0;
            $settings['cron_interval']      = isset($_POST['cron_interval']) ? sanitize_text_field($_POST['cron_interval']) : 'daily';
            $settings['show_dashboard_widget'] = isset($_POST['show_dashboard_widget']) ? 1 : 0;
        }

        update_option('vsf_settings', $settings);

        // Only touch the cron schedule if cron-related settings actually changed.
        // Previously this ran unconditionally on every settings save (even for
        // unrelated tabs like the YouTube API key), which reset the "next run"
        // anchor to now+60s every time and broke the intended weekly/monthly
        // cadence. Now we only reschedule when enabled state or interval changed.
        $new_cron_enabled  = !empty($settings['cron_enabled']);
        $new_cron_interval = isset($settings['cron_interval']) ? $settings['cron_interval'] : 'daily';
        $cron_settings_changed = ($new_cron_enabled !== $previous_cron_enabled) || ($new_cron_interval !== $previous_cron_interval);

        if ($cron_settings_changed) {
            Video_Scanner_Fix_Cron::clear_schedule();
            if ($new_cron_enabled) {
                Video_Scanner_Fix_Cron::register_schedule();
            }
        }
    }
}
