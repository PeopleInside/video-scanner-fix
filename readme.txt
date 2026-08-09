=== Video Scanner Fix ===
Contributors: peopleinside
Tags: video link checker, broken video scanner, youtube scanner, vimeo, video embed checker
Requires at least: 5.6
Tested up to: 6.7
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically & manually scan WordPress posts and custom fields for broken or missing video embeds and links.

== Description ==

Video Scanner Fix is a powerful, lightweight, and modern WordPress plugin built by peopleinside to identify broken, deleted, or blocked video links across YouTube, Vimeo, DailyMotion, Google Drive, Archive.org, DoodStream, and MixCloud.

== Features ==
* Real-Time Manual Batch Scanner with progress updates (No Admin freezes)
* Single Post Meta Box Scanner
* Automated WP-Cron Background Schedule (Hourly, Twice Daily, Daily)
* Automatic actions: Mark post as draft/private, tag broken posts, send email notifications
* Custom Meta Key Scanning
* Detailed Log History with CSV Export and Clear Log capabilities
* Automatic plugin updates from GitHub Releases

== Installation ==

1. Upload the `video-scanner-fix` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Configure settings under Video Scanner in your WordPress admin bar.

== Changelog ==

= 1.0.1 =
* Added: CSV export of the Log History table (Log History tab > Export CSV).
* Added: automatic plugin updates via GitHub Releases.
* Fixed: YouTube API key and video ID are now properly URL-encoded when calling the YouTube Data API.
