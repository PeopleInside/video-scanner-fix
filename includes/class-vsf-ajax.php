<?php
if (!defined('ABSPATH')) {
    exit;
}

class Video_Scanner_Fix_Ajax {

    public function init_hooks() {
        add_action('wp_ajax_vsf_manual_scan_batch', array($this, 'ajax_manual_scan_batch'));
        add_action('wp_ajax_vsf_scan_single_post', array($this, 'ajax_scan_single_post'));
        add_action('wp_ajax_vsf_clear_logs', array($this, 'ajax_clear_logs'));
        add_action('wp_ajax_vsf_ignore_video', array($this, 'ajax_ignore_video'));
        add_action('wp_ajax_vsf_get_stats', array($this, 'ajax_get_stats'));
        add_action('wp_ajax_vsf_get_logs_page', array($this, 'ajax_get_logs_page'));
        add_action('wp_ajax_vsf_recheck_log', array($this, 'ajax_recheck_log'));
    }

    /**
     * Handles batch manual scanning via AJAX
     */
    public function ajax_manual_scan_batch() {
        check_ajax_referer('vsf_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'video-scanner-fix')));
        }

        $settings   = get_option('vsf_settings', array());
        $page       = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
        $batch_size = isset($_POST['batch_size']) ? max(1, intval($_POST['batch_size'])) : 15;
        $post_types = isset($settings['scan_post_types']) ? (array)$settings['scan_post_types'] : array('post', 'page');
        $statuses   = isset($settings['scan_post_statuses']) ? (array)$settings['scan_post_statuses'] : array('publish');

        // Query total eligible posts
        $count_args = array(
            'post_type'      => $post_types,
            'post_status'    => $statuses,
            'posts_per_page' => -1,
            'fields'         => 'ids',
        );
        $total_posts_query = new WP_Query($count_args);
        $total_posts = $total_posts_query->post_count;

        if ($total_posts === 0) {
            wp_send_json_success(array(
                'completed'       => true,
                'progress'        => 100,
                'scanned_posts'   => 0,
                'total_posts'     => 0,
                'videos_checked'  => 0,
                'broken_found'    => 0,
                'message'         => __('No posts found matching the selected post types & statuses.', 'video-scanner-fix'),
                'logs'            => array()
            ));
        }

        // Fetch batch posts
        $query_args = array(
            'post_type'      => $post_types,
            'post_status'    => $statuses,
            'posts_per_page' => $batch_size,
            'paged'          => $page,
            'orderby'        => 'ID',
            'order'          => 'ASC'
        );
        $batch_query = new WP_Query($query_args);
        $scanner = new Video_Scanner_Fix_Scanner();

        $batch_logs = array();
        $batch_videos_checked = 0;
        $batch_broken_found = 0;

        if ($batch_query->have_posts()) {
            foreach ($batch_query->posts as $post) {
                $res = $scanner->scan_post($post);
                if (isset($res['results'])) {
                    foreach ($res['results'] as $item) {
                        $batch_videos_checked++;
                        if ($item['status'] === 'broken') {
                            $batch_broken_found++;
                        }
                        $batch_logs[] = $item;
                    }
                }
            }
        }

        $scanned_so_far = min($total_posts, $page * $batch_size);
        $is_completed   = ($scanned_so_far >= $total_posts) || !$batch_query->have_posts();
        $progress_pct   = $total_posts > 0 ? round(($scanned_so_far / $total_posts) * 100) : 100;

        wp_send_json_success(array(
            'completed'            => $is_completed,
            'next_page'            => $page + 1,
            'progress'             => $progress_pct,
            'scanned_so_far'       => $scanned_so_far,
            'total_posts'          => $total_posts,
            'batch_videos_checked' => $batch_videos_checked,
            'batch_broken_found'   => $batch_broken_found,
            'logs'                 => $batch_logs,
            'stats'                => Video_Scanner_Fix_Logger::get_stats(),
            'message'              => sprintf(
                __('Scanned %d of %d posts (%d%% complete)...', 'video-scanner-fix'),
                $scanned_so_far,
                $total_posts,
                $progress_pct
            )
        ));
    }

    /**
     * Handles single post manual scanning from Meta Box
     */
    public function ajax_scan_single_post() {
        check_ajax_referer('vsf_meta_nonce', 'nonce');

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error(array('message' => __('Invalid post or insufficient permissions.', 'video-scanner-fix')));
        }

        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error(array('message' => __('Post not found.', 'video-scanner-fix')));
        }

        if (isset($_POST['editor_content'])) {
            $post->post_content = wp_unslash($_POST['editor_content']);
        }

        $scanner = new Video_Scanner_Fix_Scanner();
        $result = $scanner->scan_post($post);

        wp_send_json_success($result);
    }

    /**
     * Clears scan logs table
     */
    public function ajax_clear_logs() {
        check_ajax_referer('vsf_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'video-scanner-fix')));
        }

        Video_Scanner_Fix_Logger::clear_all_logs();

        wp_send_json_success(array('message' => __('Logs successfully cleared.', 'video-scanner-fix')));
    }

    /**
     * Adds a video URL to the ignore list and updates log entry status
     */
    public function ajax_ignore_video() {
        check_ajax_referer('vsf_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'video-scanner-fix')));
        }

        $video_url = isset($_POST['video_url']) ? esc_url_raw($_POST['video_url']) : '';
        $log_id    = isset($_POST['log_id']) ? intval($_POST['log_id']) : 0;

        if (empty($video_url)) {
            wp_send_json_error(array('message' => __('No video URL provided.', 'video-scanner-fix')));
        }

        $settings = get_option('vsf_settings', array());
        $current_ignored = isset($settings['ignored_videos']) ? trim($settings['ignored_videos']) : '';

        // Check if already in ignored list
        $lines = array_filter(array_map('trim', explode("\n", str_replace("\r", "", $current_ignored))));
        if (!in_array($video_url, $lines)) {
            $lines[] = $video_url;
            $settings['ignored_videos'] = implode("\n", $lines);
            update_option('vsf_settings', $settings);
        }

        // Update DB log if log_id provided or by video_url
        Video_Scanner_Fix_Logger::mark_as_ignored($video_url, $log_id);

        wp_send_json_success(array('message' => __('Video successfully added to ignore list.', 'video-scanner-fix')));
    }

    /**
     * Gets latest database statistics
     */
    public function ajax_get_stats() {
        check_ajax_referer('vsf_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'video-scanner-fix')));
        }

        $stats = Video_Scanner_Fix_Logger::get_stats();
        $stats['last_scan'] = Video_Scanner_Fix_Admin::get_last_scan_display();
        $stats['next_scan'] = Video_Scanner_Fix_Admin::get_next_scan_display();

        wp_send_json_success($stats);
    }

    /**
     * Gets paginated and filtered logs for Log History tab
     */
    public function ajax_get_logs_page() {
        check_ajax_referer('vsf_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'video-scanner-fix')));
        }

        $page          = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
        $limit         = isset($_POST['limit']) ? intval($_POST['limit']) : 50;
        $status_filter = isset($_POST['status_filter']) ? sanitize_text_field($_POST['status_filter']) : 'all';
        $search        = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';

        if ($limit <= 0) {
            $limit = 10000;
        }

        $offset      = ($page - 1) * $limit;
        $total_items = Video_Scanner_Fix_Logger::get_logs_count($status_filter, $search);
        $total_pages = max(1, ceil($total_items / $limit));
        $logs        = Video_Scanner_Fix_Logger::get_logs($limit, $offset, $status_filter, $search);

        foreach ($logs as &$log) {
            $log['edit_link'] = $log['post_id'] ? get_edit_post_link($log['post_id'], 'raw') : '';
            $log['permalink'] = $log['post_id'] ? get_permalink($log['post_id']) : '';
        }

        $stats = Video_Scanner_Fix_Logger::get_stats();
        $stats['last_scan'] = Video_Scanner_Fix_Admin::get_last_scan_display();
        $stats['next_scan'] = Video_Scanner_Fix_Admin::get_next_scan_display();

        wp_send_json_success(array(
            'logs'        => $logs,
            'page'        => $page,
            'limit'       => $limit,
            'total_items' => $total_items,
            'total_pages' => $total_pages,
            'stats'       => $stats
        ));
    }

    /**
     * Re-checks a single video link or log entry and deletes or updates it
     */
    public function ajax_recheck_log() {
        check_ajax_referer('vsf_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'video-scanner-fix')));
        }

        $log_id    = isset($_POST['log_id']) ? intval($_POST['log_id']) : 0;
        $post_id   = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $video_url = isset($_POST['video_url']) ? esc_url_raw($_POST['video_url']) : '';

        if (!$log_id && empty($video_url) && !$post_id) {
            wp_send_json_error(array('message' => __('Invalid parameters for re-check.', 'video-scanner-fix')));
        }

        $log = $log_id ? Video_Scanner_Fix_Logger::get_log($log_id) : null;
        if ($log) {
            $post_id   = ($log['post_id'] > 0) ? intval($log['post_id']) : $post_id;
            $video_url = !empty($log['video_url']) ? $log['video_url'] : $video_url;
        }

        $scanner = new Video_Scanner_Fix_Scanner();

        if ($post_id > 0) {
            $post = get_post($post_id);
            if (!$post || $post->post_status === 'trash' || $post->post_status === 'auto-draft') {
                // Post no longer exists or is in trash -> remove log entry
                if ($log_id > 0) {
                    Video_Scanner_Fix_Logger::delete_log($log_id);
                } else {
                    global $wpdb;
                    $table = Video_Scanner_Fix_Logger::get_table_name();
                    if (!empty($video_url)) {
                        $wpdb->delete($table, array('post_id' => $post_id, 'video_url' => $video_url), array('%d', '%s'));
                    } else {
                        $wpdb->delete($table, array('post_id' => $post_id), array('%d'));
                    }
                }
                wp_send_json_success(array(
                    'resolved' => true,
                    'message'  => __('The post no longer exists or is in trash. Log entry removed.', 'video-scanner-fix'),
                    'stats'    => Video_Scanner_Fix_Logger::get_stats()
                ));
            }

            if (isset($_POST['editor_content'])) {
                $post->post_content = wp_unslash($_POST['editor_content']);
            }

            // Extract videos currently in post content & meta
            $all_platform_keys = array_keys($scanner->get_patterns());
            $found = $scanner->extract_videos_from_text($post->post_content, $all_platform_keys);

            $settings = get_option('vsf_settings', array());
            $meta_keys = isset($settings['scan_meta_keys']) ? trim($settings['scan_meta_keys']) : '';
            if (!empty($meta_keys)) {
                $keys = array_map('trim', explode(',', $meta_keys));
                foreach ($keys as $key) {
                    if (empty($key)) continue;
                    $meta_val = get_post_meta($post->ID, $key, true);
                    if (is_string($meta_val) && !empty($meta_val)) {
                        $found = array_merge($found, $scanner->extract_videos_from_text($meta_val, $all_platform_keys));
                    }
                }
            }

            // Check if $video_url is still present in post
            $log_video_id = ($log && !empty($log['video_id'])) ? $log['video_id'] : '';
            $still_present = false;
            $matching_video = null;
            foreach ($found as $v) {
                if (
                    $v['url'] === $video_url ||
                    esc_url_raw($v['url']) === esc_url_raw($video_url) ||
                    (!empty($v['video_id']) && !empty($log_video_id) && $v['video_id'] === $log_video_id)
                ) {
                    $still_present = true;
                    $matching_video = $v;
                    break;
                }
            }

            if (!$still_present) {
                // Video was removed from the post! Resolved!
                if ($log_id > 0) {
                    Video_Scanner_Fix_Logger::delete_log($log_id);
                }
                wp_send_json_success(array(
                    'resolved' => true,
                    'message'  => __('Video link was removed from post! Issue resolved and removed from log.', 'video-scanner-fix'),
                    'stats'    => Video_Scanner_Fix_Logger::get_stats()
                ));
            } else {
                // Video still present -> re-verify
                $check = $scanner->verify_video($matching_video);
                $check['post_id'] = $post->ID;
                $check['post_title'] = $post->post_title;

                // Update DB log with latest status (valid, broken, geo_restricted, etc.)
                $check['post_title'] = $post->post_title;
                Video_Scanner_Fix_Logger::log($check);

                $status_msg = sprintf(__('Video re-checked: status is %s (%s). Log updated.', 'video-scanner-fix'), strtoupper($check['status']), $check['response_code']);
                wp_send_json_success(array(
                    'resolved'      => ($check['status'] === 'valid'),
                    'message'       => $status_msg,
                    'status'        => $check['status'],
                    'response_code' => $check['response_code'],
                    'stats'         => Video_Scanner_Fix_Logger::get_stats()
                ));
            }
        } else {
            // No post_id, directly check video URL
            $platform = $log ? $log['platform'] : 'youtube';
            $video_id = $log ? $log['video_id'] : '';
            $check = $scanner->verify_video(array('platform' => $platform, 'url' => $video_url, 'video_id' => $video_id));

            Video_Scanner_Fix_Logger::log($check);

            $status_msg = sprintf(__('Video re-checked: status is %s (%s). Log updated.', 'video-scanner-fix'), strtoupper($check['status']), $check['response_code']);
            wp_send_json_success(array(
                'resolved'      => ($check['status'] === 'valid'),
                'message'       => $status_msg,
                'status'        => $check['status'],
                'response_code' => $check['response_code'],
                'stats'         => Video_Scanner_Fix_Logger::get_stats()
            ));
        }
    }
}
