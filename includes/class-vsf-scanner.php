<?php
if (!defined('ABSPATH')) {
    exit;
}

class Video_Scanner_Fix_Scanner {

    protected $settings;

    public function __construct() {
        $this->settings = get_option('vsf_settings', array());
    }

    /**
     * Regex Patterns for supported video platforms
     */
    public function get_patterns() {
        return array(
            'youtube' => array(
                'label' => 'YouTube',
                'regex' => array(
                    '/(?:https?:)?\/\/(?:www\.|m\.)?youtube(?:-nocookie)?\.com\/(?:watch\?(?:[^\s\'"<>]*&)?v=|embed\/|v\/|shorts\/)([\w_-]+)/i',
                    '/(?:https?:)?\/\/youtu\.be\/([\w_-]+)/i'
                )
            ),
            'vimeo' => array(
                'label' => 'Vimeo',
                'regex' => array(
                    '/(?:https?:)?\/\/(?:www\.|player\.)?vimeo\.com\/(?:video\/|channels\/[^\/\s\'"]+\/|groups\/[^\/\s\'"]+\/videos\/|manage\/videos\/)?([0-9]+)/i',
                    '/(?:https?:)?\/\/vimeo\.com\/([0-9]+)/i'
                )
            ),
            'dailymotion' => array(
                'label' => 'DailyMotion',
                'regex' => array(
                    '/(?:https?:)?\/\/(?:www\.|geo\.)?dailymotion\.com\/(?:embed\/video\/|video\/)([a-zA-Z0-9]+)/i',
                    '/(?:https?:)?\/\/dai\.ly\/([a-zA-Z0-9]+)/i'
                )
            ),
            'googledrive' => array(
                'label' => 'Google Drive',
                'regex' => array(
                    '/(?:https?:)?\/\/drive\.google\.com\/file\/d\/([a-zA-Z0-9_-]{25,})/i'
                )
            ),
            'archive' => array(
                'label' => 'Archive.org',
                'regex' => array(
                    '/(?:https?:)?\/\/(?:www\.)?archive\.org\/(?:embed|details|download)\/([a-zA-Z0-9_\%-\.]+)/i'
                )
            ),
            'doodstream' => array(
                'label' => 'DoodStream',
                'regex' => array(
                    '/(?:https?:)?\/\/(?:www\.)?(?:doodstream\.com|d0000d\.com|dood\.to|dood\.watch)\/[ed]\/([a-zA-Z0-9_-]+)/i'
                )
            ),
            'mixcloud' => array(
                'label' => 'MixCloud',
                'regex' => array(
                    '/(?:https?:)?\/\/(?:www\.)?mixcloud\.com\/(?:widget\/iframe\/\?[^\s\'"]*feed=)?([a-zA-Z0-9_\%-\/]+)/i'
                )
            )
        );
    }

    /**
     * Scan a single WP_Post object
     */
    public function scan_post($post) {
        if (!$post || is_wp_error($post)) {
            return array('error' => 'Invalid Post');
        }

        $all_platform_keys = array_keys($this->get_patterns());
        $enabled_platforms = (isset($this->settings['enabled_platforms']) && is_array($this->settings['enabled_platforms']) && !empty($this->settings['enabled_platforms']))
            ? $this->settings['enabled_platforms']
            : $all_platform_keys;
        $found_videos = array();

        // 1. Scan Post Content
        $content = $post->post_content;
        $found_videos = array_merge($found_videos, $this->extract_videos_from_text($content, $enabled_platforms));

        // 2. Scan Custom Meta Keys if configured
        $meta_keys = isset($this->settings['scan_meta_keys']) ? trim($this->settings['scan_meta_keys']) : '';
        if (!empty($meta_keys)) {
            $keys = array_map('trim', explode(',', $meta_keys));
            foreach ($keys as $key) {
                if (empty($key)) continue;
                $meta_value = get_post_meta($post->ID, $key, true);
                if (is_string($meta_value) && !empty($meta_value)) {
                    $found_videos = array_merge($found_videos, $this->extract_videos_from_text($meta_value, $enabled_platforms));
                }
            }
        }

        // De-duplicate found videos by URL
        $unique_videos = array();
        foreach ($found_videos as $vid) {
            $unique_videos[$vid['url']] = $vid;
        }

        $results = array();
        $post_has_broken = false;
        $post_has_geo    = false;

        foreach ($unique_videos as $video) {
            if ($this->is_video_ignored($video['url'], $video['video_id'])) {
                $check = array(
                    'platform'      => $video['platform'],
                    'video_url'     => $video['url'],
                    'video_id'      => $video['video_id'],
                    'status'        => 'ignored',
                    'response_code' => 'Ignored',
                    'error_message' => 'Video link ignored by user settings',
                    'post_id'       => $post->ID,
                    'post_title'    => $post->post_title
                );
            } else {
                $check = $this->verify_video($video);
                $check['post_id'] = $post->ID;
                $check['post_title'] = $post->post_title;

                if ($check['status'] === 'broken') {
                    $post_has_broken = true;
                } else if ($check['status'] === 'geo_restricted') {
                    $post_has_geo = true;
                }
            }

            // Save to Log table
            Video_Scanner_Fix_Logger::log($check);
            $results[] = $check;
        }

        // Apply post-scan actions if broken or geo-restricted videos detected
        if ($post_has_broken || $post_has_geo) {
            $this->apply_broken_actions($post, $post_has_broken, $post_has_geo);
        }

        // Clean up old log entries for this post that are no longer broken/geo_restricted (fixed or removed)
        if ($post->ID > 0) {
            global $wpdb;
            $table = Video_Scanner_Fix_Logger::get_table_name();
            $old_logs = $wpdb->get_results($wpdb->prepare("SELECT id, video_url FROM {$table} WHERE post_id = %d AND status IN ('broken', 'geo_restricted')", $post->ID), ARRAY_A);
            if (!empty($old_logs)) {
                $current_unhealthy_urls = array();
                foreach ($results as $res) {
                    if ($res['status'] === 'broken' || $res['status'] === 'geo_restricted') {
                        $current_unhealthy_urls[] = $res['video_url'];
                    }
                }
                foreach ($old_logs as $old_log) {
                    if (!in_array($old_log['video_url'], $current_unhealthy_urls)) {
                        Video_Scanner_Fix_Logger::delete_log($old_log['id']);
                    }
                }
            }
        }

        return array(
            'post_id'       => $post->ID,
            'post_title'    => $post->post_title,
            'videos_found'  => count($results),
            'results'       => $results,
            'has_broken'    => $post_has_broken
        );
    }

    /**
     * Parse text content for video URLs
     */
    public function extract_videos_from_text($text, $enabled_platforms) {
        $all_patterns = $this->get_patterns();
        $videos = array();

        if (empty($text) || !is_string($text)) {
            return $videos;
        }

        // Decode HTML entities so encoded URLs like &amp; or quote entities are parsed cleanly
        $decoded_text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        foreach ($enabled_platforms as $platform_key) {
            if (!isset($all_patterns[$platform_key])) continue;

            $config = $all_patterns[$platform_key];
            foreach ($config['regex'] as $pattern) {
                if (preg_match_all($pattern, $decoded_text, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $match) {
                        $full_url = $match[0];
                        $video_id = isset($match[1]) ? $match[1] : '';

                        // Format full URL if missing protocol
                        if (strpos($full_url, 'http') !== 0) {
                            $full_url = 'https:' . $full_url;
                        }

                        $videos[] = array(
                            'platform' => $platform_key,
                            'url'      => $full_url,
                            'video_id' => $video_id
                        );
                    }
                }
            }
        }

        return $videos;
    }

    /**
     * Check if a video link is alive/valid
     */
    public function verify_video($video) {
        $platform = $video['platform'];
        $url      = $video['url'];
        $video_id = $video['video_id'];

        switch ($platform) {
            case 'youtube':
                return $this->verify_youtube($url, $video_id);
            case 'vimeo':
                return $this->verify_vimeo($url, $video_id);
            default:
                return $this->verify_oembed_or_http($url, $platform, $video_id);
        }
    }

    protected function verify_youtube($url, $video_id) {
        $api_key        = isset($this->settings['youtube_api_key']) ? trim($this->settings['youtube_api_key']) : '';
        $target_country = isset($this->settings['target_country']) && !empty($this->settings['target_country']) ? strtoupper(trim($this->settings['target_country'])) : 'IT';
        $check_geo      = !isset($this->settings['check_geo_restriction']) || !empty($this->settings['check_geo_restriction']);
        
        // If API key is present, use official YouTube v3 API
        if (!empty($api_key) && !empty($video_id)) {
            $api_url = "https://www.googleapis.com/youtube/v3/videos?id={$video_id}&key={$api_key}&part=contentDetails,status,id";
            $response = wp_remote_get($api_url, array('timeout' => 8));

            if (!is_wp_error($response)) {
                $code = wp_remote_retrieve_response_code($response);
                if ($code === 200) {
                    $body = json_decode(wp_remote_retrieve_body($response), true);
                    if (isset($body['items']) && count($body['items']) > 0) {
                        $item = $body['items'][0];
                        
                        // Check region restriction if enabled
                        if ($check_geo && isset($item['contentDetails']['regionRestriction'])) {
                            $restriction = $item['contentDetails']['regionRestriction'];
                            $is_restricted = false;

                            if (isset($restriction['blocked']) && is_array($restriction['blocked']) && in_array($target_country, $restriction['blocked'])) {
                                $is_restricted = true;
                            } else if (isset($restriction['allowed']) && is_array($restriction['allowed']) && !in_array($target_country, $restriction['allowed'])) {
                                $is_restricted = true;
                            }

                            if ($is_restricted) {
                                return array(
                                    'platform'      => 'youtube',
                                    'video_url'     => $url,
                                    'video_id'      => $video_id,
                                    'status'        => 'geo_restricted',
                                    'response_code' => '403 Geo-Blocked',
                                    'error_message' => sprintf('Restricted in target country (%s)', $target_country)
                                );
                            }
                        }

                        return array(
                            'platform'      => 'youtube',
                            'video_url'     => $url,
                            'video_id'      => $video_id,
                            'status'        => 'valid',
                            'response_code' => '200 OK',
                            'error_message' => 'Video exists'
                        );
                    } else {
                        return array(
                            'platform'      => 'youtube',
                            'video_url'     => $url,
                            'video_id'      => $video_id,
                            'status'        => 'broken',
                            'response_code' => '404 Not Found',
                            'error_message' => 'Video removed or private (YouTube API)'
                        );
                    }
                }
            }
        }

        // Fallback: YouTube oEmbed validation (does not require API key)
        if (!empty($video_id)) {
            $check_url = "https://www.youtube.com/watch?v=" . $video_id;
            $oembed_url = "https://www.youtube.com/oembed?url=" . urlencode($check_url) . "&format=json";
            $response = wp_remote_get($oembed_url, array('timeout' => 8));

            if (!is_wp_error($response)) {
                $code = wp_remote_retrieve_response_code($response);
                if ($code === 200) {
                    return array(
                        'platform'      => 'youtube',
                        'video_url'     => $url,
                        'video_id'      => $video_id,
                        'status'        => 'valid',
                        'response_code' => '200 OK',
                        'error_message' => 'YouTube video exists (oEmbed)'
                    );
                } else if ($code === 404) {
                    return array(
                        'platform'      => 'youtube',
                        'video_url'     => $url,
                        'video_id'      => $video_id,
                        'status'        => 'broken',
                        'response_code' => '404 Not Found',
                        'error_message' => 'YouTube video deleted, removed, or invalid ID'
                    );
                } else if ($code === 401 || $code === 403) {
                    return array(
                        'platform'      => 'youtube',
                        'video_url'     => $url,
                        'video_id'      => $video_id,
                        'status'        => 'broken',
                        'response_code' => (string)$code . ' Forbidden',
                        'error_message' => 'YouTube video private or embedding disabled'
                    );
                }
            }
        }

        // Fallback to oEmbed check or HTTP head
        return $this->verify_oembed_or_http($url, 'youtube', $video_id);
    }

    protected function verify_vimeo($url, $video_id) {
        $check_url = !empty($video_id) ? "https://vimeo.com/" . $video_id : $url;
        $oembed_url = "https://vimeo.com/api/oembed.json?url=" . urlencode($check_url);
        $response = wp_remote_get($oembed_url, array('timeout' => 8));

        if (is_wp_error($response)) {
            return array(
                'platform' => 'vimeo',
                'video_url' => $url,
                'video_id' => $video_id,
                'status'   => 'broken',
                'response_code' => 'HTTP Connection Failed',
                'error_message' => $response->get_error_message()
            );
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code === 200) {
            return array(
                'platform' => 'vimeo',
                'video_url' => $url,
                'video_id' => $video_id,
                'status'   => 'valid',
                'response_code' => '200 OK',
                'error_message' => 'Vimeo oEmbed OK'
            );
        } else {
            return array(
                'platform' => 'vimeo',
                'video_url' => $url,
                'video_id' => $video_id,
                'status'   => 'broken',
                'response_code' => (string)$code . ' Not Found',
                'error_message' => 'Vimeo video unavailable, private, or deleted'
            );
        }
    }

    protected function verify_oembed_or_http($url, $platform, $video_id) {
        // HTTP HEAD request to check availability
        $response = wp_remote_head($url, array(
            'timeout' => 8,
            'redirection' => 5,
            'user-agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) VideoScannerFix/1.0'
        ));

        if (is_wp_error($response)) {
            // Try GET request fallback
            $response = wp_remote_get($url, array('timeout' => 8, 'redirection' => 5));
        }

        if (is_wp_error($response)) {
            return array(
                'platform' => $platform,
                'video_url' => $url,
                'video_id' => $video_id,
                'status'   => 'broken',
                'response_code' => 'Connection Error',
                'error_message' => $response->get_error_message()
            );
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 400) {
            return array(
                'platform' => $platform,
                'video_url' => $url,
                'video_id' => $video_id,
                'status'   => 'valid',
                'response_code' => $code . ' OK',
                'error_message' => 'Resource accessible'
            );
        } else {
            return array(
                'platform' => $platform,
                'video_url' => $url,
                'video_id' => $video_id,
                'status'   => 'broken',
                'response_code' => (string)$code,
                'error_message' => 'HTTP status code error'
            );
        }
    }

    /**
     * Check if a video URL or ID is in the ignore list
     */
    public function is_video_ignored($url, $video_id = '') {
        $ignored_raw = isset($this->settings['ignored_videos']) ? trim($this->settings['ignored_videos']) : '';
        if (empty($ignored_raw)) {
            return false;
        }

        $lines = array_map('trim', explode("\n", str_replace("\r", "", $ignored_raw)));
        foreach ($lines as $line) {
            if (empty($line)) continue;
            if ($line === $url || (!empty($video_id) && $line === $video_id)) {
                return true;
            }
            if (!empty($url) && strpos($url, $line) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Actions to apply on broken or geo-restricted post
     */
    protected function apply_broken_actions($post, $has_broken = true, $has_geo = false) {
        $status_action = isset($this->settings['on_broken_status']) ? $this->settings['on_broken_status'] : 'no_change';
        $tag_action    = isset($this->settings['on_broken_tag']) ? trim($this->settings['on_broken_tag']) : '';

        // 1. Change Post Status if broken links exist
        if ($has_broken && in_array($status_action, array('draft', 'private', 'pending'))) {
            wp_update_post(array(
                'ID'          => $post->ID,
                'post_status' => $status_action
            ));
        }

        // 2. Add Tag if configured
        if (($has_broken || $has_geo) && !empty($tag_action)) {
            wp_set_post_tags($post->ID, $tag_action, true);
        }
    }

    /**
     * Send a single consolidated summary email at the end of an automated scan
     */
    public function send_automated_scan_summary_email($scan_issues) {
        if (empty($this->settings['notify_on_broken']) || empty($this->settings['notify_email']) || empty($scan_issues)) {
            return false;
        }

        $mute_geo_email = !empty($this->settings['notify_ignore_geo_restricted']);
        $filtered_issues = array();

        foreach ($scan_issues as $issue) {
            // Filter out geo-restricted items if muted
            if ($issue['status'] === 'geo_restricted' && $mute_geo_email) {
                continue;
            }
            $filtered_issues[] = $issue;
        }

        if (empty($filtered_issues)) {
            return false;
        }

        // Group issues by Post ID
        $grouped_posts = array();
        foreach ($filtered_issues as $issue) {
            $pid = $issue['post_id'];
            if (!isset($grouped_posts[$pid])) {
                $grouped_posts[$pid] = array(
                    'post_title' => isset($issue['post_title']) ? $issue['post_title'] : get_the_title($pid),
                    'permalink'  => get_permalink($pid),
                    'items'      => array()
                );
            }
            $grouped_posts[$pid]['items'][] = $issue;
        }

        $to      = $this->settings['notify_email'];
        $subject = sprintf('[Video Scanner Fix] Automated Scan Summary: %d issue(s) detected in %d post(s)', count($filtered_issues), count($grouped_posts));

        $message  = "Hello,\n\n";
        $message .= "The automated scan by Video Scanner Fix has completed and detected broken or restricted video links in your content.\n\n";
        $message .= "SUMMARY REPORT:\n";
        $message .= "--------------------------------------------------\n";

        foreach ($grouped_posts as $pid => $data) {
            $message .= sprintf("POST: %s (ID: %d)\n", $data['post_title'], $pid);
            if (!empty($data['permalink'])) {
                $message .= sprintf("URL:  %s\n", $data['permalink']);
            }
            $message .= "ISSUES FOUND:\n";

            foreach ($data['items'] as $item) {
                $status_label = ($item['status'] === 'geo_restricted') ? 'Geo-Blocked' : 'Broken';
                $message .= sprintf(
                    " - [%s] %s (%s - %s)\n",
                    $status_label,
                    $item['video_url'],
                    $item['response_code'],
                    $item['error_message']
                );
            }
            $message .= "--------------------------------------------------\n";
        }

        $message .= "\nPlease log into your WordPress admin dashboard to review and manage these videos.\n";
        $message .= "\nRegards,\nVideo Scanner Fix";

        return wp_mail($to, $subject, $message);
    }
}
