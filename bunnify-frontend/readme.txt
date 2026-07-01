=== Bunnify Frontend ===
Contributors: anthonythorne
Tags: cdn, bunnycdn, images, performance, media
Requires at least: 6.3
Tested up to: 6.9
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight, frontend-only BunnyCDN image delivery for WordPress. Rewrites media URLs to your Bunny pull zone with on-the-fly resizing.

== Description ==

Bunnify Frontend is a deliberately small, frontend-only integration for
[BunnyCDN](https://bunny.net/). It rewrites the URLs WordPress emits for media
so images are served from your Bunny pull zone, with width/height (and other)
transforms appended as query parameters.

It does **not** upload, sync, or manage files on the CDN. It assumes your
images already live in `wp-content/uploads/` and that Bunny is configured as a
pull zone against your origin. That single responsibility keeps it fast and
predictable.

**What it does**

* Rewrites attachment URLs (`image_downsize`, `wp_get_attachment_image*`,
  responsive `srcset`/`sizes`) to your CDN hostname.
* Rewrites images inside post content, blocks, galleries and image widgets.
* Preserves the original (non-resized) filename and requests dimensions from
  the CDN, so you get one cacheable source per image instead of many crops.
* Adds a `preconnect` resource hint for the CDN origin (and removes the
  redundant `dns-prefetch`) to improve Largest Contentful Paint.
* Ships a rich filter API so themes and companion plugins can extend behaviour
  without touching the plugin (see the FAQ and the project wiki).

**What it does not do**

* No media offloading, no storage-zone management, no image regeneration.
* No content rewriting when the CDN hostname is empty — it silently falls back
  to origin URLs, so it is safe to leave installed while disabled.

== Installation ==

1. Upload the `bunnify-frontend` folder to `/wp-content/plugins/`, or install
   the zip via **Plugins → Add New → Upload Plugin**.
2. Activate the plugin through the **Plugins** menu.
3. Go to **Media → BunnyCDN** and set your **BunnyCDN Hostname**
   (for example `cdn.example.com`). Leaving it empty keeps origin URLs.

Requires PHP 8.2+ and WordPress 6.3+.

== Frequently Asked Questions ==

= How do I disable the CDN temporarily? =

Clear the hostname under **Media → BunnyCDN**, or define
`BUNNIFY_DISABLE` as `true` in `wp-config.php`.

= Can I serve theme assets (not just uploads) through the CDN? =

Yes, opt in per-path with the `bunnify_allow_non_upload_url` filter. By default
only `/wp-content/uploads/` URLs are processed.

= Does it work in local development? =

Enable **Local Development Mode** under **Media → BunnyCDN**. When a file exists
locally, the CDN rewrite is skipped so you keep working offline.

= Where is the developer documentation? =

Hooks, image-processing flow, logging and troubleshooting live in the project
wiki: https://github.com/anthonythorne/bunnify-frontend

== Screenshots ==

1. The BunnyCDN settings screen under Media → BunnyCDN.

== Changelog ==

= 1.0.0 =
* Initial public release.
* Frontend media URL rewriting for attachments, content, blocks, galleries and widgets.
* Responsive srcset/sizes rewriting with aspect-ratio-correct dimensions.
* `preconnect` resource hint for the CDN origin.
* `bunnify_allow_non_upload_url` filter for opt-in non-upload asset paths.
* Pass-through of additional Bunny transform arguments (quality, format, …).

== Upgrade Notice ==

= 1.0.0 =
Initial public release.
