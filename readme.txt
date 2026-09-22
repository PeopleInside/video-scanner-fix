=== Video Scanner Fix ===
Contributors: peopleinside
Tags: video link checker, broken video scanner, youtube scanner, vimeo, video embed checker
Requires at least: 5.6
Tested up to: 7.1
Stable tag: 1.0.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically & manually scan WordPress posts and custom fields for broken or missing video embeds and links.

== Description ==

Video Scanner Fix is a powerful, lightweight, and modern WordPress plugin built by peopleinside to identify broken, deleted, or blocked video links across YouTube, Vimeo, DailyMotion, Google Drive, Archive.org, DoodStream, and MixCloud.

== Features ==
* Real-Time Manual Batch Scanner with progress updates (No Admin freezes)
* Single Post Meta Box Scanner
* Automated WP-Cron Background Schedule (Hourly, Twice Daily, Daily, Weekly, Monthly)
* Automatic actions: Mark post as draft/private, tag broken posts, send email notifications
* Custom Meta Key Scanning
* Detailed Log History with CSV Export and Clear Log capabilities
* Automatic plugin updates from GitHub Releases

== Installation ==

1. Upload the `video-scanner-fix` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Configure settings under Video Scanner in your WordPress admin bar.

== Changelog ==

= 1.0.9 =
* Improve update - fix

= 1.0.8 =
* Added: The automated scan summary email is now translated. When WordPress (and therefore the plugin) uses Italian, the email subject, body and the scan result messages are sent in Italian; all other languages keep the original English text.
* Fixed: On WordPress 6.5 and 6.6 sites using a language other than Italian, the plugin could be displayed in Italian.
* Removed: Unused, duplicate and invalid translation files. Italian is now provided only by `languages/video-scanner-fix-it_IT.po` and `languages/video-scanner-fix-it_IT.l10n.php`.

= 1.0.6 =
* Fixed: Automated (scheduled) scans now cover every matching post, just like a manual scan. Previously the automated run scanned everything in a single pass capped at 240 seconds with no resume mechanism, so on hosts with a lower real PHP execution limit — or on sites with many posts — it silently stopped partway through and never finished the full site. The scan now proceeds in short chained batches (like the manual scan) until the entire queue is processed, before recording "Last Scan" and sending the summary email.

= 1.0.5 =
* Fixed: "Last Scan" date/time could be off by twice the site's UTC offset when falling back to the log table timestamp (double timezone conversion between local `current_time('mysql')` values and `wp_date()`).
* Fixed: Saving any settings tab (e.g. Platforms or Filters) no longer resets the WP-Cron schedule anchor to "now"; the recurring schedule is only touched when the cron enabled state or interval actually changes, preserving the intended weekly/monthly cadence.

= 1.0.4 =
* Fixed: WP-Cron automated scheduled scan now reliably scans all configured post types and statuses instead of an arbitrary random subset.
* Fixed: Corrected Last Scan and Next Scheduled Scan date and time synchronization in the dashboard widget and settings page.
* Fixed: Registered custom WP-Cron intervals ('weekly' and 'monthly') globally to ensure recurring background scans execute reliably without dropped schedules.
* Fixed: Corrected database column query fallback (`created_at`) when reading the most recent scan entry from logs.
* Fixed: Scan timestamp (`vsf_last_scan_time`) is now accurately updated only upon successful completion of a scan with genuine log data.

= 1.0.3 =
* Added: Automatic fallback to latest log entry timestamp for last scan date display if empty.
* Fixed: Updated WP-Cron background scan execution to automatically update last scan date/time (`vsf_last_scan_time`).
* Fixed: Enhanced WordPress textdomain loading and full Italian localization strings coverage.

= 1.0.1 =
* Added: CSV export of the Log History table (Log History tab > Export CSV).
* Added: automatic plugin updates via GitHub Releases.
* Fixed: YouTube API key and video ID are now properly URL-encoded when calling the YouTube Data API.
