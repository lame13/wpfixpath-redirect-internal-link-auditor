=== IndexLane Redirect & Internal Link Auditor ===
Contributors: wpfixpath
Tags: redirects, broken links, internal links, migration, audit
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find broken, redirected, old-domain, and staging-domain links inside WordPress content.

== Description ==

IndexLane Redirect & Internal Link Auditor finds internal content links that return 404/410, redirect through 301/302, or still point to old, staging, or development domains.

It runs from inside WordPress admin, stays read-only, and produces evidence a site owner or developer can review or export. It does not auto-fix content or mutate the database.

Learn more at [IndexLane](https://indexlane.dev/plugins/redirect-internal-link-auditor).

Version 0.3 can:

* Scan all published content or a numeric limit of the newest content.
* Scan any registered public post type, not only posts, pages, and products.
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
* Show live content, link, unique-destination, HTTP-request, and actionable-issue progress.
* Pause, continue, cancel, and resume a saved scan after reloading the page.
* Provide translation-ready administrator, progress, evidence, JavaScript, and CSV strings through the WordPress.org text domain.

The destination-impact view is derived from the completed occurrence rows. It does not make more HTTP requests. Two links to one destination in the same page count as two occurrences and one affected content item.

Destination grouping normalizes scheme and host case, fragments, and default ports. Paths, query strings, schemes, non-default ports, and trailing slashes remain distinct because they can return different evidence.

HTTP requests are made only to the current site. Links to old, staging, or development domains are still reported for review.

== Data handling ==

Scans run on demand from WordPress admin through authenticated AJAX batches. The active or completed session is stored in a per-user WordPress transient for up to 24 hours after its last activity. Abandoned sessions expire automatically, and completed-session CSV exports reuse the exact displayed evidence without scanning again.

The plugin does not create an account, call an IndexLane service, or add frontend tracking.

== Limits ==

Version 0.3 scans links found in the `post_content` field of selected public post types. It does not crawl menus, widgets, theme templates, page-builder metadata, shortcode output, or rendered frontend pages.

Each AJAX batch makes at most five outbound HTTP requests. A session begins with an explicit allowance of 250 actual requests, including redirect hops. When that allowance is reached, the administrator can grant another 250 requests and continue without losing progress or recording incomplete evidence.

== Installation ==

1. Upload the `indexlane-redirect-internal-link-auditor` folder to `/wp-content/plugins/`.
2. Activate the plugin in WordPress admin.
3. Go to `Tools -> Redirect & Internal Link Auditor`.
4. Select the public content types and choose all published content or a numeric limit.
5. Start the scan, keep the page open while it runs, or pause and return later.
6. Export the destination-impact or detailed-row CSV report after the session completes.

== Frequently Asked Questions ==

= Does this plugin change links or content? =

No. Version 0.3 is read-only and diagnostic only.

= Does this plugin store scan results? =

Only temporarily. One active or completed session per administrator is stored in a WordPress transient for up to 24 hours after its last activity. The plugin does not create custom database tables.

= Does it use an external API? =

No. It uses WordPress HTTP requests and local WordPress content only.

= Does this plugin check external links? =

No. Old, staging, and development-domain links are flagged but not fetched. A same-site redirect that points outside the site is reported without fetching the external destination.

== Screenshots ==

1. Scan setup with all-published-content scope and public post-type selection.
2. A resumable scan session showing progress, request allowance, and lifecycle controls.
3. Completed destination-impact and occurrence evidence with exact-session CSV exports.

== Changelog ==

= 0.3.0 =

* Added complete, resumable scan sessions driven by authenticated WordPress AJAX batches.
* Added all-published-content scope and selection for any public post type.
* Added live progress, pause, continue, cancel, reload recovery, and request-allowance extensions.
* Added whole-session request deduplication and completed-session-only CSV exports.
* Added automatic per-user session expiry after 24 hours of inactivity.
* Made all plugin-owned visible strings translation-ready with the WordPress.org slug as the text domain.

= 0.2.2 =

* Moved the admin page CSS into a stylesheet enqueued only on the plugin's Tools screen.

= 0.2.1 =

* Renamed the pre-approval plugin to IndexLane Redirect & Internal Link Auditor and aligned its slug and text domain.
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
