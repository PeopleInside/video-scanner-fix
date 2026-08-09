/**
 * Video Scanner Fix - Admin Script
 * Author: peopleinside
 */
(function($) {
    'use strict';

    var isScanning = false;
    var currentPage = 1;

    // Log History Pagination & Filter State
    var logCurrentPage = 1;
    var logLimit = 50;
    var logStatusFilter = 'all';
    var logSearch = '';
    var logSearchTimer = null;

    $(document).ready(function() {

        // Tab switching via JS without page reload to keep active scan running
        $('.vsf-nav-tabs a.nav-tab').on('click', function(e) {
            var href = $(this).attr('href');
            if (href && href.indexOf('tab=') !== -1) {
                var match = href.match(/tab=([a-z_]+)/);
                if (match && match[1]) {
                    var targetTab = match[1];
                    if ($('#vsf-tab-' + targetTab).length > 0) {
                        e.preventDefault();
                        $('.vsf-nav-tabs a.nav-tab').removeClass('nav-tab-active');
                        $(this).addClass('nav-tab-active');
                        $('.vsf-tab-panel').hide();
                        $('#vsf-tab-' + targetTab).show();

                        if (window.history && window.history.replaceState) {
                            window.history.replaceState(null, '', href);
                        }

                        // Auto-refresh when switching tabs
                        if (targetTab === 'dashboard') {
                            fetchDashboardStats();
                        } else if (targetTab === 'logs') {
                            loadLogsPage();
                        }
                    }
                }
            }
        });

        // Update Dashboard Stats Card numbers & Scan dates
        function updateDashboardStats(stats) {
            if (!stats) return;
            if (stats.total !== undefined) $('.vsf-stat-total .vsf-stat-number').text(stats.total);
            if (stats.broken !== undefined) $('.vsf-stat-broken .vsf-stat-number').text(stats.broken);
            if (stats.valid !== undefined) $('.vsf-stat-valid .vsf-stat-number').text(stats.valid);
            if (stats.geo_restricted !== undefined) $('.vsf-stat-clickable[data-filter="geo_restricted"] .vsf-stat-number').text(stats.geo_restricted);
            if (stats.ignored !== undefined) $('.vsf-stat-clickable[data-filter="ignored"] .vsf-stat-number').text(stats.ignored);
            if (stats.last_scan !== undefined) $('#vsf-last-scan-display').text(stats.last_scan);
            if (stats.next_scan !== undefined) $('#vsf-next-scan-display').text(stats.next_scan);
        }

        function fetchDashboardStats() {
            $.post(vsf_vars.ajax_url, {
                action: 'vsf_get_stats',
                nonce: vsf_vars.admin_nonce
            }, function(response) {
                if (response.success) {
                    updateDashboardStats(response.data);
                }
            });
        }

        // Load Paginated & Filtered Logs via AJAX
        function loadLogsPage() {
            var $tbody = $('#vsf-logs-tbody');
            $tbody.css('opacity', '0.5');

            $.post(vsf_vars.ajax_url, {
                action: 'vsf_get_logs_page',
                nonce: vsf_vars.admin_nonce,
                page: logCurrentPage,
                limit: logLimit,
                status_filter: logStatusFilter,
                search: logSearch
            }, function(response) {
                $tbody.css('opacity', '1');

                if (response.success) {
                    var data = response.data;
                    var logs = data.logs || [];

                    updateDashboardStats(data.stats);

                    if (logs.length === 0) {
                        $tbody.html('<tr class="vsf-no-logs"><td colspan="6" style="text-align:center; padding:20px; color:#888;">' + (vsf_vars.strings.no_logs_found || 'No log entries found matching criteria.') + '</td></tr>');
                    } else {
                        var html = '';
                        logs.forEach(function(log) {
                            var statusLower = (log.status || 'unknown').toLowerCase();
                            var badgeHtml = '';
                            var actionBtns = '';

                            if (statusLower === 'broken') {
                                badgeHtml = '<span class="vsf-badge vsf-badge-danger">' + (vsf_vars.strings.broken || 'Broken') + '</span>';
                            } else if (statusLower === 'geo_restricted') {
                                badgeHtml = '<span class="vsf-badge" style="background:#f3e8ff; color:#7e22ce; border:1px solid #d8b4fe;">' + (vsf_vars.strings.geo_blocked || 'Geo Blocked') + '</span>';
                            } else if (statusLower === 'ignored') {
                                badgeHtml = '<span class="vsf-badge vsf-badge-warning">' + (vsf_vars.strings.ignored || 'Ignored') + '</span>';
                            } else {
                                badgeHtml = '<span class="vsf-badge vsf-badge-success">' + (vsf_vars.strings.valid || 'Valid') + '</span>';
                            }

                            var recheckBtn = '<button type="button" class="button button-small vsf-recheck-btn" data-log-id="' + escapeHtml(log.id) + '" data-post-id="' + escapeHtml(log.post_id || '') + '" data-url="' + escapeHtml(log.video_url) + '" title="' + escapeHtml(vsf_vars.strings.recheck_video_link || 'Re-check this video link') + '"><span class="dashicons dashicons-update" style="font-size:12px; width:12px; height:12px; line-height:20px;"></span> ' + escapeHtml(vsf_vars.strings.recheck || 'Re-check') + '</button>';

                            var ignoreBtn = '';
                            if (statusLower !== 'ignored') {
                                ignoreBtn = '<button type="button" class="button button-small vsf-ignore-video-btn" data-url="' + escapeHtml(log.video_url) + '" data-log-id="' + escapeHtml(log.id) + '">' + escapeHtml(vsf_vars.strings.ignore || 'Ignore') + '</button>';
                            }

                            actionBtns = '<div style="margin-top:6px; display:flex; gap:4px; flex-wrap:wrap;">' + recheckBtn + ignoreBtn + '</div>';

                            var titleText = escapeHtml(log.post_title || (vsf_vars.strings.no_title || '(No Title)'));
                            var titleHtml = titleText;

                            if (log.post_id) {
                                var editLink = log.edit_link ? escapeHtml(log.edit_link) : '';
                                var permalink = log.permalink ? escapeHtml(log.permalink) : '';

                                var titleLink = editLink ? '<a href="' + editLink + '" target="_blank" title="' + escapeHtml(vsf_vars.strings.edit_post_in_admin || 'Edit Post in WP Admin') + '"><strong>' + titleText + '</strong></a>' : '<strong>' + titleText + '</strong>';

                                var subLinks = [];
                                if (editLink) {
                                    subLinks.push('<a href="' + editLink + '" target="_blank" class="button button-small" style="font-size:11px; padding:0 6px; height:22px; line-height:20px;"><span class="dashicons dashicons-edit" style="font-size:12px; vertical-align:middle; width:12px; height:12px; line-height:20px; margin-right:2px;"></span>' + escapeHtml(vsf_vars.strings.edit_post || 'Edit Post') + '</a>');
                                }
                                if (permalink) {
                                    subLinks.push('<a href="' + permalink + '" target="_blank" class="button button-small" style="font-size:11px; padding:0 6px; height:22px; line-height:20px;"><span class="dashicons dashicons-external" style="font-size:12px; vertical-align:middle; width:12px; height:12px; line-height:20px; margin-right:2px;"></span>' + escapeHtml(vsf_vars.strings.view_public || 'View Public') + '</a>');
                                }

                                titleHtml = titleLink + '<div style="margin-top:4px; display:flex; gap:4px; align-items:center;">' + subLinks.join('') + '</div>';
                            }

                            html += '<tr data-status="' + statusLower + '">' +
                                '<td>' + badgeHtml + actionBtns + '</td>' +
                                '<td><strong>' + escapeHtml(log.platform || 'unknown').toUpperCase() + '</strong></td>' +
                                '<td>' + titleHtml + '</td>' +
                                '<td><a href="' + escapeHtml(log.video_url || '') + '" target="_blank" class="vsf-url-truncate">' + escapeHtml(log.video_url || '') + '</a></td>' +
                                '<td><code>' + escapeHtml((log.response_code || '') + ' - ' + (log.error_message || '')) + '</code></td>' +
                                '<td>' + escapeHtml(log.created_at || '') + '</td>' +
                                '</tr>';
                        });
                        $tbody.html(html);
                    }

                    // Update Pagination UI
                    var totalMsg = (vsf_vars.strings.total_entries || 'Total entries: %1$s (Page %2$s of %3$s)')
                        .replace('%1$s', data.total_items)
                        .replace('%2$s', data.page)
                        .replace('%3$s', data.total_pages);
                    $('#vsf-pagination-info').text(totalMsg);
                    $('#vsf-page-current-num').text(data.page);
                    $('#vsf-page-total-num').text(data.total_pages);

                    // Update nav buttons
                    $('#vsf-page-first, #vsf-page-prev').prop('disabled', data.page <= 1);
                    $('#vsf-page-next, #vsf-page-last').prop('disabled', data.page >= data.total_pages);

                    $('#vsf-page-first').data('page', 1);
                    $('#vsf-page-prev').data('page', Math.max(1, data.page - 1));
                    $('#vsf-page-next').data('page', Math.min(data.total_pages, data.page + 1));
                    $('#vsf-page-last').data('page', data.total_pages);
                } else {
                    $tbody.html('<tr><td colspan="6" style="color:red; padding:15px; text-align:center;">' + (response.data.message || (vsf_vars.strings.error_loading_logs || 'Error loading logs')) + '</td></tr>');
                }
            }).fail(function() {
                $tbody.css('opacity', '1');
                $tbody.html('<tr><td colspan="6" style="color:red; padding:15px; text-align:center;">' + (vsf_vars.strings.ajax_error_logs || 'AJAX Error loading log history.') + '</td></tr>');
            });
        }

        // Log Status Filter Buttons
        $('.vsf-log-filter-btn').on('click', function(e) {
            e.preventDefault();
            var filter = $(this).data('filter') || 'all';
            $('.vsf-log-filter-btn').removeClass('active button-primary').addClass('button-secondary');
            $(this).removeClass('button-secondary').addClass('active button-primary');

            logStatusFilter = filter;
            logCurrentPage = 1;
            loadLogsPage();
        });

        // Search Input with Debounce
        $('#vsf-log-search-input').on('keyup input', function() {
            clearTimeout(logSearchTimer);
            var val = $.trim($(this).val());
            logSearchTimer = setTimeout(function() {
                logSearch = val;
                logCurrentPage = 1;
                loadLogsPage();
            }, 300);
        });

        // Items Per Page Dropdown
        $('#vsf-log-limit-select').on('change', function() {
            logLimit = parseInt($(this).val(), 10) || 50;
            logCurrentPage = 1;
            loadLogsPage();
        });

        // Pagination Navigation Buttons
        $('.vsf-page-nav-btn').on('click', function(e) {
            e.preventDefault();
            if ($(this).prop('disabled')) return;
            var targetPage = parseInt($(this).data('page'), 10) || 1;
            logCurrentPage = targetPage;
            loadLogsPage();
        });

        // Refresh Logs Button
        $('#vsf-refresh-logs-btn').on('click', function(e) {
            e.preventDefault();
            loadLogsPage();
        });

        // Refresh Stats Button
        $('#vsf-refresh-stats-btn').on('click', function(e) {
            e.preventDefault();
            fetchDashboardStats();
        });

        // Clickable stat cards -> switch to logs tab & apply filter
        $('.vsf-stat-clickable').on('click', function() {
            var filter = $(this).data('filter') || 'all';
            $('.vsf-nav-tabs a.nav-tab').removeClass('nav-tab-active');
            $('.vsf-nav-tabs a.nav-tab[href*="tab=logs"]').addClass('nav-tab-active');
            $('.vsf-tab-panel').hide();
            $('#vsf-tab-logs').show();

            $('.vsf-log-filter-btn').removeClass('active button-primary').addClass('button-secondary');
            $('.vsf-log-filter-btn[data-filter="' + filter + '"]').removeClass('button-secondary').addClass('active button-primary');

            logStatusFilter = filter;
            logCurrentPage = 1;
            loadLogsPage();
        });

        // Start Batch Scan
        $('#vsf-start-scan-btn').on('click', function(e) {
            e.preventDefault();
            if (isScanning) return;

            isScanning = true;
            currentPage = 1;

            $('#vsf-start-scan-btn').prop('disabled', true);
            $('#vsf-stop-scan-btn').show();
            $('#vsf-progress-container').slideDown(200);
            $('#vsf-scan-results-box').slideDown(200);
            $('#vsf-scan-log-feed').html('<div class="vsf-log-entry vsf-log-entry-info">' + vsf_vars.strings.scanning + '</div>');

            runBatchScan();
        });

        // Stop Scan
        $('#vsf-stop-scan-btn').on('click', function(e) {
            e.preventDefault();
            isScanning = false;
            $('#vsf-start-scan-btn').prop('disabled', false);
            $('#vsf-stop-scan-btn').hide();
            appendLog('<div class="vsf-log-entry vsf-log-entry-info">Scan manually stopped by user.</div>');
            fetchDashboardStats();
            loadLogsPage();
        });

        // Clear Logs Button
        $('#vsf-clear-logs-btn').on('click', function(e) {
            e.preventDefault();
            if (!confirm(vsf_vars.strings.confirm_clear)) return;

            $.post(vsf_vars.ajax_url, {
                action: 'vsf_clear_logs',
                nonce: vsf_vars.admin_nonce
            }, function(response) {
                if (response.success) {
                    logCurrentPage = 1;
                    fetchDashboardStats();
                    loadLogsPage();
                } else {
                    alert(response.data.message || 'Error clearing logs');
                }
            });
        });

        // Helper to retrieve live active editor content (Gutenberg block editor or Classic editor)
        function getActiveEditorContent() {
            var content = null;
            try {
                if (window.wp && wp.data && wp.data.select && wp.data.select('core/editor')) {
                    var coreEditor = wp.data.select('core/editor');
                    if (coreEditor.getEditedPostContent) {
                        content = coreEditor.getEditedPostContent();
                    }
                }
            } catch(e) {}

            if (content === null || content === undefined || content === '') {
                if (window.tinymce && tinymce.get('content') && !tinymce.get('content').isHidden()) {
                    content = tinymce.get('content').getContent();
                } else if ($('#content').length > 0) {
                    content = $('#content').val();
                }
            }
            return content;
        }

        // Single Post Scan (Metabox)
        $('#vsf-btn-scan-single').on('click', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var postId = $btn.data('post-id');
            var $results = $('#vsf-metabox-results');

            $btn.prop('disabled', true).text('Scanning...');
            $results.html('<em>Analyzing post video links...</em>').show();

            var postData = {
                action: 'vsf_scan_single_post',
                nonce: vsf_vars.meta_nonce,
                post_id: postId
            };

            var editorContent = getActiveEditorContent();
            if (editorContent !== null && editorContent !== undefined) {
                postData.editor_content = editorContent;
            }

            $.post(vsf_vars.ajax_url, postData, function(response) {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-search"></span> Scan Post Now');
                if (response.success) {
                    var data = response.data;
                    var html = '<strong>Checked ' + data.videos_found + ' video(s).</strong>';
                    if (data.results && data.results.length > 0) {
                        html += '<ul style="margin-top:6px; padding-left:15px;">';
                        data.results.forEach(function(item) {
                            var color = item.status === 'broken' ? '#d63638' : '#00a32a';
                            html += '<li style="color:' + color + ';">' + item.platform.toUpperCase() + ': ' + item.status.toUpperCase() + ' (' + item.response_code + ')</li>';
                        });
                        html += '</ul>';
                    } else {
                        html += '<p style="margin-top:4px;">No videos detected in content or custom fields.</p>';
                    }
                    $results.html(html);
                } else {
                    $results.html('<span style="color:red;">' + (response.data.message || vsf_vars.strings.error_occurred) + '</span>');
                }
            }).fail(function() {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-search"></span> Scan Post Now');
                $results.html('<span style="color:red;">' + vsf_vars.strings.error_occurred + '</span>');
            });
        });

        // Delegated event listener for Re-check Video button
        $(document).on('click', '.vsf-recheck-btn', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var logId = $btn.data('log-id');
            var postId = $btn.data('post-id');
            var videoUrl = $btn.data('url');

            var originalHtml = $btn.html();
            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update" style="animation: rotation 1s infinite linear; font-size:12px; width:12px; height:12px; line-height:20px;"></span> Checking...');

            var postData = {
                action: 'vsf_recheck_log',
                nonce: vsf_vars.admin_nonce,
                log_id: logId,
                post_id: postId,
                video_url: videoUrl
            };

            var editorContent = getActiveEditorContent();
            if (editorContent !== null && editorContent !== undefined) {
                postData.editor_content = editorContent;
            }

            $.post(vsf_vars.ajax_url, postData, function(response) {
                if (response.success) {
                    var data = response.data;
                    if (data.resolved) {
                        alert(data.message || 'Video checked and issue resolved! Removed from log.');
                        loadLogsPage();
                    } else {
                        alert(data.message || 'Video checked. Issue still persists.');
                        loadLogsPage();
                    }
                } else {
                    alert(response.data.message || 'Error re-checking video.');
                    $btn.prop('disabled', false).html(originalHtml);
                }
            }).fail(function() {
                alert('AJAX error when re-checking video.');
                $btn.prop('disabled', false).html(originalHtml);
            });
        });

        // Delegated event listener for Ignore Video button
        $(document).on('click', '.vsf-ignore-video-btn', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var videoUrl = $btn.data('url');
            var logId = $btn.data('log-id');

            if (!confirm('Add this video URL to the ignore list?')) return;

            $btn.prop('disabled', true).text('Ignoring...');

            $.post(vsf_vars.ajax_url, {
                action: 'vsf_ignore_video',
                nonce: vsf_vars.admin_nonce,
                video_url: videoUrl,
                log_id: logId
            }, function(response) {
                if (response.success) {
                    $btn.replaceWith('<span class="vsf-badge vsf-badge-warning" style="margin-left:8px;">Ignored</span>');
                    fetchDashboardStats();
                } else {
                    alert(response.data.message || 'Error ignoring video');
                    $btn.prop('disabled', false).text('Ignore');
                }
            }).fail(function() {
                alert('AJAX error when ignoring video.');
                $btn.prop('disabled', false).text('Ignore');
            });
        });
    });

    function runBatchScan() {
        if (!isScanning) return;

        var batchSize = $('#vsf-batch-size').val() || 15;

        $.ajax({
            url: vsf_vars.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'vsf_manual_scan_batch',
                nonce: vsf_vars.admin_nonce,
                page: currentPage,
                batch_size: batchSize
            },
            success: function(response) {
                if (!isScanning) return;

                if (response.success) {
                    var data = response.data;

                    // Update Progress Bar
                    $('#vsf-progress-bar-fill').css('width', data.progress + '%');
                    $('#vsf-progress-percent').text(data.progress + '%');
                    $('#vsf-progress-text').text(data.message);

                    // Live update dashboard stats after every batch
                    if (data.stats) {
                        $('.vsf-stat-total .vsf-stat-number').text(data.stats.total);
                        $('.vsf-stat-broken .vsf-stat-number').text(data.stats.broken);
                        $('.vsf-stat-valid .vsf-stat-number').text(data.stats.valid);
                        $('.vsf-stat-clickable[data-filter="geo_restricted"] .vsf-stat-number').text(data.stats.geo_restricted);
                        $('.vsf-stat-clickable[data-filter="ignored"] .vsf-stat-number').text(data.stats.ignored);
                    }

                    // Render Logs Feed
                    if (data.logs && data.logs.length > 0) {
                        data.logs.forEach(function(log) {
                            var statusLower = (log.status || 'unknown').toLowerCase();
                            var cssClass = 'vsf-log-entry-valid';
                            if (statusLower === 'broken') {
                                cssClass = 'vsf-log-entry-broken';
                            } else if (statusLower === 'ignored') {
                                cssClass = 'vsf-log-entry-ignored';
                            }

                            var ignoreBtnHtml = '';
                            if (statusLower === 'broken') {
                                ignoreBtnHtml = ' <button type="button" class="button button-small vsf-ignore-video-btn" data-url="' + escapeHtml(log.video_url) + '" data-log-id="' + escapeHtml(log.id || '') + '">Ignore Video</button>';
                            }

                            var logHtml = '<div class="vsf-log-entry ' + cssClass + '">' +
                                '<strong>[' + escapeHtml(log.platform || 'unknown').toUpperCase() + ']</strong> ' +
                                'Post: <em>' + escapeHtml(log.post_title || '') + '</em> &mdash; ' +
                                'URL: ' + escapeHtml(log.video_url || '') + ' &mdash; ' +
                                'Status: <strong>' + escapeHtml(log.status || '').toUpperCase() + '</strong> (' + escapeHtml(log.response_code || '') + ')' +
                                ignoreBtnHtml +
                                '</div>';
                            appendLog(logHtml);
                        });
                    }

                    if (data.completed) {
                        isScanning = false;
                        $('#vsf-start-scan-btn').prop('disabled', false);
                        $('#vsf-stop-scan-btn').hide();
                        appendLog('<div class="vsf-log-entry vsf-log-entry-info"><strong>' + vsf_vars.strings.scan_complete + '</strong></div>');
                    } else {
                        currentPage = data.next_page;
                        setTimeout(runBatchScan, 200);
                    }
                } else {
                    isScanning = false;
                    $('#vsf-start-scan-btn').prop('disabled', false);
                    $('#vsf-stop-scan-btn').hide();
                    appendLog('<div class="vsf-log-entry vsf-log-entry-broken">Error: ' + escapeHtml(response.data.message || vsf_vars.strings.error_occurred) + '</div>');
                }
            },
            error: function() {
                isScanning = false;
                $('#vsf-start-scan-btn').prop('disabled', false);
                $('#vsf-stop-scan-btn').hide();
                appendLog('<div class="vsf-log-entry vsf-log-entry-broken">AJAX Request Failed. Server timeout or error.</div>');
            }
        });
    }

    function appendLog(html) {
        var $feed = $('#vsf-scan-log-feed');
        $feed.append(html);
        $feed.scrollTop($feed[0].scrollHeight);
    }

    function escapeHtml(text) {
        if (text === null || text === undefined) return '';
        return String(text)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

})(jQuery);
