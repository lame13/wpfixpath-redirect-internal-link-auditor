=== WPFixPath Redirect & Internal Link Auditor ===
Contributors: wpfixpath, indexlane
Tags: redirects, broken links, internal links, migration, audit
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 0.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find broken, redirected, old-domain, and staging-domain links inside WordPress content.

== Description ==

WPFixPath Redirect & Internal Link Auditor finds internal content links that return 404/410, redirect through 301/302, or still point to old, staging, or development domains.

It runs from inside WordPress admin, stays read-only, and produces evidence a site owner or developer can review or export. It does not auto-fix content or mutate the database.

Version 0.1 can:

* Scan published posts, pages, and products when those post types exist.
* Extract links from post content.
* Check same-site link status.
* Flag 404/410 responses.
* Flag 301/302 redirects and redirect chains.
* Flag links to old domains supplied by the administrator.
* Flag common staging and development-domain links.
* Show the source post/page.
* Export a CSV report.

HTTP requests are made only to the current site. Links to old, staging, or development domains are still reported for review.

== Data handling ==

Scans run on demand from WordPress admin. The plugin does not create an account, call an IndexLane/WPFixPath service, or add frontend tracking.

== Limits ==

Version 0.1 scans links found in WordPress post, page, and product content. It does not crawl menus, widgets, theme templates, page-builder metadata, shortcode output, or rendered frontend pages.

== Installation ==

1. Upload the `wpfixpath-redirect-internal-link-auditor` folder to `/wp-content/plugins/`.
2. Activate the plugin in WordPress admin.
3. Go to `Tools -> Redirect & Internal Link Auditor`.
4. Select the content types and scan limits.
5. Run checks or export a CSV report.

== Frequently Asked Questions ==

= Does this plugin change links or content? =

No. Version 0.1 is read-only and diagnostic only.

= Does this plugin store scan results? =

No. Version 0.1 does not create custom database tables or store scan results.

= Does it use an external API? =

No. It uses WordPress HTTP requests and local WordPress content only.

= Does this plugin check external links? =

No in v0.1. Old, staging, and development-domain links are flagged but not fetched.

== Screenshots ==

1. Admin scan settings for content type selection, old-domain input, scan limits, and status-check options.
2. Results table with source page, linked URL, status evidence, warnings, and CSV export.

== Changelog ==

= 0.1.2 =

* Rewrote README, readme, and plugin metadata copy in a less defensive voice.

= 0.1.1 =

* Updated public screenshots from the current WordPress admin UI.

= 0.1.0 =

* Initial diagnostic release.
