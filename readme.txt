=== WPFixPath Redirect & Internal Link Auditor ===
Contributors: wpfixpath
Tags: redirects, broken links, internal links, migration, audit
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find broken, redirected, old-domain, and staging-domain links inside WordPress content.

== Description ==

WPFixPath Redirect & Internal Link Auditor finds internal content links that return 404/410, redirect through 301/302, or still point to old, staging, or development domains.

It runs from inside WordPress admin, stays read-only, and produces evidence a site owner or developer can review or export. It does not auto-fix content or mutate the database.

Version 0.2 can:

* Scan published posts, pages, and products when those post types exist.
* Extract links from post content.
* Check same-site link status.
* Flag 404/410 responses.
* Flag 301/302 redirects and redirect chains.
* Flag links to old domains supplied by the administrator.
* Flag common staging and development-domain links.
* Show the source post/page.
* Group broken/error and redirected destinations by occurrence count and distinct affected content count.
* Show status, redirect, effective-final-URL, warning, and result evidence for each destination.
* Export separate destination-impact and detailed-row CSV reports.

The destination-impact view is derived from the completed occurrence rows. It does not make more HTTP requests. Two links to one destination in the same page count as two occurrences and one affected content item.

Destination grouping normalizes scheme and host case, fragments, and default ports. Paths, query strings, schemes, non-default ports, and trailing slashes remain distinct because they can return different evidence.

HTTP requests are made only to the current site. Links to old, staging, or development domains are still reported for review.

== Data handling ==

Scans run on demand from WordPress admin. Completed results are stored in a per-user WordPress transient for up to one hour so both CSV exports can reuse the displayed evidence without scanning again.

The plugin does not create an account, call an IndexLane/WPFixPath service, or add frontend tracking.

== Limits ==

Version 0.2 scans links found in WordPress post, page, and product content. It does not crawl menus, widgets, theme templates, page-builder metadata, shortcode output, or rendered frontend pages.

== Installation ==

1. Upload the `wpfixpath-redirect-internal-link-auditor` folder to `/wp-content/plugins/`.
2. Activate the plugin in WordPress admin.
3. Go to `Tools -> Redirect & Internal Link Auditor`.
4. Select the content types and scan limits.
5. Run checks or export the destination-impact or detailed-row CSV report.

== Frequently Asked Questions ==

= Does this plugin change links or content? =

No. Version 0.2 is read-only and diagnostic only.

= Does this plugin store scan results? =

Only temporarily. The latest completed result set is cached for up to one hour for per-user CSV export. The plugin does not create custom database tables.

= Does it use an external API? =

No. It uses WordPress HTTP requests and local WordPress content only.

= Does this plugin check external links? =

No in v0.2. Old, staging, and development-domain links are flagged but not fetched.

== Screenshots ==

1. Admin scan settings for content type selection, old-domain input, scan limits, and status-check options.
2. Occurrence-level results with source URLs, status evidence, redirect details, and warnings.

== Changelog ==

= 0.2.1 =

* Prepared plugin metadata and packaging for the WordPress.org Plugin Directory.
* Removed the third-party Update URI so WordPress.org can deliver plugin updates.
* Declared compatibility testing through WordPress 7.1.
* Corrected the WordPress.org contributor metadata.
* Resolved Plugin Check findings for translation loading, nonce verification, translatable labels, and CSV output.

= 0.2.0 =

* Added a destination-centric impact view for broken/error and redirected targets.
* Added occurrence and distinct affected-content counts.
* Added deterministic severity and impact ordering.
* Added a separate destination-impact CSV while preserving detailed-row export behavior.
* Kept aggregation read-only and derived from the exact saved scan.
* Strengthened CSV formula-injection protection for values with leading whitespace.

= 0.1.3 =

* Kept trailing-slash URL variants distinct in request caching and redirect-loop detection.
* Made the 250-request limit count every actual outbound HTTP request, including redirect hops.
* Replaced HEAD-derived status claims with bounded GET verification through the safe WordPress HTTP API.
* Exported the exact completed scan from short-lived per-user storage instead of rescanning content and URLs.

= 0.1.2 =

* Rewrote README, readme, and plugin metadata copy in a less defensive voice.

= 0.1.1 =

* Updated public screenshots from the current WordPress admin UI.

= 0.1.0 =

* Initial diagnostic release.
