=== IndexLane Redirect & Internal Link Auditor ===
Contributors: wpfixpath
Tags: redirects, broken links, internal links, migration, audit
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.6.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Audit redirects, broken links, and source-aware internal link coverage inside WordPress.

== Description ==

IndexLane Redirect & Internal Link Auditor finds broken, redirected, old-site, and staging links. It shows where links appear and checks whether your fixes worked against an explicitly saved scan.

It runs from inside WordPress admin, stays read-only, and produces results a site owner or developer can review or download. It does not auto-fix content or change the database.

Learn more at [IndexLane](https://indexlane.dev/plugins/redirect-internal-link-auditor).

Version 0.6 can:

* Scan all published content or a numeric limit of the newest content.
* Scan any registered public post type, not only posts, pages, and products.
* Select stored link sources independently.
* Inspect normal post content, classic menus, Navigation entities, synced patterns, block templates, template parts, and assigned block widgets.
* Check same-site link status.
* Flag 404/410 responses.
* Flag 301/302 redirects and redirect chains.
* Flag links to old domains supplied by the administrator.
* Flag common staging and development-domain links.
* Retain each source's exact identity, surface, edit URL, link text, and contextual or shared/global scope.
* Group broken/error and redirected URLs by times linked and editable sources affected.
* Show status, redirects, final URL, warnings, and outcome for each URL.
* Report one content-link coverage row per scanned published item.
* Count contextual links, navigation/shared links, distinct editable sources, outgoing links, distinct URLs, link-text variants, self-links, and direct versus redirected incoming links.
* Resolve redirects to their final published WordPress content item while retaining redirect results.
* Filter content with zero or one detected editable source and open every target's exact source details.
* Download separate content-coverage, problem-URL, and link-detail CSV reports.
* Save one explicitly selected completed scan for comparison per administrator.
* Download and upload strict, versioned, site-specific saved-scan JSON.
* Rerun the saved scan's exact scope as a fix check.
* Classify issue URLs as new, changed, resolved, or still present.
* Compare saved-scan and latest-scan status chains, redirects, final URLs, outcomes, times linked, and affected-source counts.
* Download the exact completed comparison as CSV.
* Delete the saved scan explicitly without changing current scan results.
* Show live stored sources checked, links found, unique URLs, and links needing attention.
* Pause, continue, cancel, and resume a saved scan after reloading the page.
* Provide translation-ready administrator, progress, result, JavaScript, and CSV strings through the WordPress.org text domain.

The problem-URL view is derived from the completed link results. It does not make more HTTP requests. A URL found once in a shared Footer template part is reported against that exact editable source, with a direct edit link.

The content-link coverage view is also derived from the exact completed scan without additional HTTP requests. “No incoming links detected in selected sources” means only that no links were found in the stored adapters chosen for that scan. Shortcode output, arbitrary metadata, proprietary page-builder storage, and rendered frontend output remain outside these results.

Third-party plugins can register reliable stored-source adapters through the `indexlane_rila_source_providers` filter. The provider supplies bounded snapshot and next-source callbacks plus stable source identity and edit evidence; this plugin does not maintain proprietary storage parsers.

URL grouping normalizes scheme and host case, fragments, and default ports. Paths, query strings, schemes, non-default ports, and trailing slashes remain distinct because they can return different results.

HTTP requests are made only to the current site. Links to old, staging, or development domains are still reported for review.

== Data handling ==

Scans run on demand from WordPress admin through authenticated AJAX batches. Each batch processes at most five stored sources and makes at most five outbound HTTP requests. The active or completed session is stored in a per-user WordPress transient for up to 24 hours after its last activity. Abandoned sessions expire automatically, and completed-session downloads reuse the exact displayed results without scanning again.

An administrator may explicitly save one site-specific scan in their WordPress user options. It remains until it is replaced or deleted. Portable saved-scan JSON is limited to 20 MB and validated against the exact supported format and current site URL before upload.

The plugin does not create an account, call an IndexLane service, or add frontend tracking.

== Limits ==

This is a stored-source scanner, not a rendered-site crawler. It reads only the selected built-in adapters or adapters registered by other plugins. It does not execute shortcodes, scan arbitrary metadata, render templates, inspect unsupported page-builder storage, or crawl frontend pages.

A scan can snapshot at most 100,000 stored sources. Block widgets are included only when their stored block structure is available and the widget is assigned to a widget area.

Each AJAX batch makes at most five outbound HTTP requests. A session begins with an explicit limit of 250 actual requests, including redirect hops. When that limit is reached, the administrator can increase it by 250 and continue without losing progress or recording incomplete results.

== Installation ==

1. Upload the `indexlane-redirect-internal-link-auditor` folder to `/wp-content/plugins/`.
2. Activate the plugin in WordPress admin.
3. Go to `Tools -> Redirect & Internal Link Auditor`.
4. Select the stored link sources, public content types, and content limit.
5. Start the scan, keep the page open while it runs, or pause and return later.
6. Review source-aware content coverage and download content-coverage, problem-URL, or link-detail CSV reports after the session completes.
7. Optionally save the completed scan for comparison, then check your fixes against the saved scan and download the comparison.

== Frequently Asked Questions ==

= Does this plugin change links or content? =

No. The plugin is read-only and diagnostic only.

= Does this plugin store scan results? =

One active or completed session per administrator is stored temporarily in a WordPress transient for up to 24 hours after its last activity. One opt-in saved scan per administrator is stored in WordPress user options until explicitly replaced or deleted. The plugin does not create custom database tables or retain scan history.

= Does it use an external API? =

No. It uses WordPress HTTP requests and local WordPress content only.

= Does this plugin check external links? =

No. Old-site, staging, and development-site links are flagged but not fetched. A same-site redirect that points outside the site is reported without fetching the external URL.

== Screenshots ==

1. Choose where to check for links across content, menus, Navigation blocks, patterns, templates, template parts, and widgets.
2. Pause or continue a scan while seeing stored sources checked, links found, URLs checked, and links needing attention.
3. Review source-aware coverage with individual-content and site-wide counts plus the exact place where each link can be edited.

== Changelog ==

= 0.6.0 =

* Added selectable adapters for content, classic menus, Navigation entities, synced patterns, block templates, template parts, and assigned block widgets.
* Retained exact source identity, edit links, link text, and individual-content or site-wide scope for every occurrence.
* Expanded coverage, problem-URL reports, comparisons, and CSV exports with exact editable-source evidence.
* Added a public stored-source provider filter while retaining the read-only, stored-data-only boundary.
* Upgraded resumable sessions and saved-scan evidence with strict legacy migration and bounded source processing.
* Simplified the new source controls for non-technical administrators and regenerated all WordPress screenshots.

= 0.5.1 =

* Replaced technical baseline and verification wording with saved scans, fix checks, results, URLs, link details, and downloads.
* Put first-time scan setup and current scan progress before saved-scan controls.
* Collapsed advanced request settings and moved request accounting, exact settings, and raw scan data into technical details.
* Simplified comparison, content-coverage, and problem-URL tables while keeping technical data in expandable details.
* Reorganized downloads, updated CSV headings, corrected summary plural handling, and regenerated all WordPress screenshots.

= 0.5.0 =

* Added one opt-in, site-specific baseline per administrator with strict JSON export, import, replacement, and deletion controls.
* Added exact-scope verification scans bound to the saved baseline revision.
* Added new, changed, resolved, and still-present issue classifications with before-and-after evidence and a comparison CSV export.
* Split the plugin implementation into focused modules and updated packaging, screenshots, documentation, translation auditing, and test coverage.

= 0.4.0 =

* Added content-link coverage for every published content item in a completed scan.
* Added incoming and outgoing counts, linking-source and destination counts, anchor variants, self-links, and direct-versus-redirected evidence.
* Added target details, a zero-or-one-source filter, and resolution of redirects to their final published WordPress content item.
* Added an exact-session target-coverage CSV export without additional HTTP requests.

= 0.3.1 =

* Fixed authenticated AJAX end-to-end release validation on standard GitHub-hosted runners.

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
