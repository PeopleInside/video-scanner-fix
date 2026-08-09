<?php
if (!defined('ABSPATH')) {
    exit;
}

class Video_Scanner_Fix_Logger {

    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'vsf_logs';
    }

    public static function create_tables() {
        global $wpdb;
        $table_name = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned DEFAULT 0,
            post_title text DEFAULT '',
            platform varchar(50) NOT NULL DEFAULT '',
            video_url text NOT NULL,
            video_id varchar(255) DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'broken',
            response_code varchar(20) DEFAULT '',
            error_message text DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY post_id (post_id),
            KEY status (status),
            KEY platform (platform)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public static function log($data) {
        global $wpdb;
        $table = self::get_table_name();

        $post_id   = isset($data['post_id']) ? intval($data['post_id']) : 0;
        $video_url = isset($data['video_url']) ? esc_url_raw($data['video_url']) : '';

        // Check if log entry for this post and video already exists
        $existing_id = false;
        if ($post_id > 0 && !empty($video_url)) {
            $existing_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE post_id = %d AND video_url = %s",
                $post_id,
                $video_url
            ));
        }

        if ($existing_id) {
            $wpdb->update(
                $table,
                array(
                    'post_title'    => isset($data['post_title']) ? sanitize_text_field($data['post_title']) : '',
                    'platform'      => isset($data['platform']) ? sanitize_text_field($data['platform']) : 'unknown',
                    'video_id'      => isset($data['video_id']) ? sanitize_text_field($data['video_id']) : '',
                    'status'        => isset($data['status']) ? sanitize_text_field($data['status']) : 'broken',
                    'response_code' => isset($data['response_code']) ? sanitize_text_field($data['response_code']) : '',
                    'error_message' => isset($data['error_message']) ? sanitize_text_field($data['error_message']) : '',
                    'created_at'    => current_time('mysql')
                ),
                array('id' => $existing_id),
                array('%s', '%s', '%s', '%s', '%s', '%s', '%s'),
                array('%d')
            );
        } else {
            $wpdb->insert(
                $table,
                array(
                    'post_id'       => $post_id,
                    'post_title'    => isset($data['post_title']) ? sanitize_text_field($data['post_title']) : '',
                    'platform'      => isset($data['platform']) ? sanitize_text_field($data['platform']) : 'unknown',
                    'video_url'     => $video_url,
                    'video_id'      => isset($data['video_id']) ? sanitize_text_field($data['video_id']) : '',
                    'status'        => isset($data['status']) ? sanitize_text_field($data['status']) : 'broken',
                    'response_code' => isset($data['response_code']) ? sanitize_text_field($data['response_code']) : '',
                    'error_message' => isset($data['error_message']) ? sanitize_text_field($data['error_message']) : '',
                    'created_at'    => current_time('mysql')
                ),
                array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
            );
        }
    }

    public static function get_logs($limit = 50, $offset = 0, $status_filter = '', $search = '') {
        global $wpdb;
        $table = self::get_table_name();

        $where = array();
        $params = array();

        if (!empty($status_filter) && $status_filter !== 'all') {
            $where[] = "status = %s";
            $params[] = $status_filter;
        }

        if (!empty($search)) {
            $where[] = "(post_title LIKE %s OR video_url LIKE %s OR response_code LIKE %s)";
            $like = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        if ($limit > 0) {
            $query = "SELECT * FROM {$table} {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
            $params[] = $limit;
            $params[] = $offset;
        } else {
            $query = "SELECT * FROM {$table} {$where_sql} ORDER BY id DESC";
        }

        if (!empty($params)) {
            $sql = $wpdb->prepare($query, $params);
        } else {
            $sql = $query;
        }

        return $wpdb->get_results($sql, ARRAY_A);
    }

    public static function get_logs_count($status_filter = '', $search = '') {
        global $wpdb;
        $table = self::get_table_name();

        $where = array();
        $params = array();

        if (!empty($status_filter) && $status_filter !== 'all') {
            $where[] = "status = %s";
            $params[] = $status_filter;
        }

        if (!empty($search)) {
            $where[] = "(post_title LIKE %s OR video_url LIKE %s OR response_code LIKE %s)";
            $like = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $query = "SELECT COUNT(*) FROM {$table} {$where_sql}";

        if (!empty($params)) {
            $sql = $wpdb->prepare($query, $params);
        } else {
            $sql = $query;
        }

        return intval($wpdb->get_var($sql));
    }

    public static function get_stats() {
        global $wpdb;
        $table = self::get_table_name();
        
        $total          = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $broken         = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'broken'");
        $valid          = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'valid'");
        $ignored        = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'ignored'");
        $geo_restricted = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'geo_restricted'");

        return array(
            'total'          => intval($total),
            'broken'         => intval($broken),
            'valid'          => intval($valid),
            'ignored'        => intval($ignored),
            'geo_restricted' => intval($geo_restricted)
        );
    }

    public static function mark_as_ignored($video_url, $log_id = 0) {
        global $wpdb;
        $table = self::get_table_name();

        if ($log_id > 0) {
            $wpdb->update(
                $table,
                array(
                    'status'        => 'ignored',
                    'response_code' => 'Ignored',
                    'error_message' => 'Added to ignore list'
                ),
                array('id' => $log_id),
                array('%s', '%s', '%s'),
                array('%d')
            );
        } else if (!empty($video_url)) {
            $wpdb->update(
                $table,
                array(
                    'status'        => 'ignored',
                    'response_code' => 'Ignored',
                    'error_message' => 'Added to ignore list'
                ),
                array('video_url' => $video_url),
                array('%s', '%s', '%s'),
                array('%s')
            );
        }
    }

    public static function get_log($id) {
        global $wpdb;
        $table = self::get_table_name();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);
    }

    public static function delete_log($id) {
        global $wpdb;
        $table = self::get_table_name();
        return $wpdb->delete($table, array('id' => $id), array('%d'));
    }

    public static function clear_all_logs() {
        global $wpdb;
        $table = self::get_table_name();
        return $wpdb->query("TRUNCATE TABLE {$table}");
    }
}
