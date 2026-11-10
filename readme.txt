=== Video Scanner Fix ===
Contributors: peopleinside
Requires at least: 6.5
Tested up to: 7.1.4
Requires PHP: 7.4
Stable tag: 1.1.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Scans WordPress posts and custom fields for broken video links and embeds, featuring automated schedules, real-time manual scanning, and action triggers.

== Description ==

A comprehensive WordPress plugin to scan broken videos.

= Features =

* Scans posts and custom fields for broken video links and embeds
* Supports multiple platforms: YouTube, Vimeo, Dailymotion, Google Drive, Archive.org, Doodstream, Mixcloud
* Automated scanning with configurable schedules (hourly, twice daily, daily)
* Real-time manual scanning
* Action triggers: change post status, add tags, send notifications
* API integration for YouTube and Vimeo to check video availability
* Detailed logging of scan results
* Configurable scan parameters (post types, post statuses, meta keys, link types)
* Batch processing to handle large sites efficiently
* Automatic updates from GitHub Releases

= How it works =

The plugin scans your WordPress content for video links and embeds, then checks if they are still accessible. When a broken video is found, you can configure automatic actions such as changing the post status to draft or private, adding a tag, or sending an email notification.

= API Support =

* YouTube: Requires a YouTube Data API v3 key for accurate video status checking
* Vimeo: Requires a Vimeo API token for accurate video status checking
* Other platforms: Basic HTTP checks are performed

== Installation ==

1. Upload the plugin to the `/wp-content/plugins/` directory, or install it through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to Tools > Video Scanner Fix to configure the plugin.
4. Set up your API keys if you want to use YouTube or Vimeo API checks.
5. Configure the scan parameters and schedule according to your needs.

== Frequently Asked Questions ==

= Do I need API keys? =

API keys are optional but recommended for YouTube and Vimeo. Without API keys, the plugin performs basic HTTP checks which may not be as accurate for detecting private, deleted, or restricted videos.

= How does the automatic scheduling work? =

The plugin uses WordPress cron to run scans at your configured interval. You can choose hourly, twice daily, or daily scans. Note that WordPress cron is triggered by site visits, so very low-traffic sites may not run scans as frequently as configured.

= Can I exclude certain posts or post types? =

Yes, you can configure which post types and post statuses to scan. You can also specify which custom fields (meta keys) to scan for video links.

= What happens when a broken video is found? =

You can configure the plugin to:
* Change the post status (draft, private, or no change)
* Add a specific tag to the post
* Send an email notification to the admin

= Is the plugin compatible with multisite? =

Yes, the plugin works on multisite installations. Each site has its own configuration and scan results.

== Screenshots ==

1. Main dashboard showing scan statistics and recent activity
2. Settings page with scan configuration options
3. Scan results with detailed information about broken videos
4. Log viewer showing scan history

== Disclaimer ==

This software is provided "AS IS", without any warranty. While it has been tested and reasonable efforts are made to ensure security and reliability, no guarantees are provided. As an open project, anyone may contribute or report issues, but this does not imply endorsement or liability from the maintainers.

You use this software entirely at your own risk. The authors and contributors are not liable for any damages, data loss, or unexpected behavior resulting from its use, modification, or distribution. Always review and test the code independently before deploying it in critical or production environments.

== Changelog ==

= 1.1.6 =
* self-update process improvement

= 1.1.5 =
* self-update process improvement

= 1.1.4 =
* Fix: the GitHub package host allowlist now includes the release CDN host, which could silently block the download of the update package.
* Change: GitHub release cache reduced from 12 to 2 hours, so new releases are detected sooner.
* Tested up to WordPress 7.1.4.

= 1.1.3 =
* Track scan errors and prevent stale alerts.

= 1.0.5 =
* Initial stable release.

== Upgrade Notice ==

= 1.1.4 =
Important update: fixes an issue where GitHub release downloads could be silently blocked. All users should update to this version.
