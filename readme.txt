=== IndexLane Redirect & Internal Link Auditor ===
Contributors: wpfixpath
Tags: redirects, broken links, internal links, migration, audit
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.5.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Audit redirects, broken links, and content link coverage inside WordPress.

== Description ==

IndexLane Redirect & Internal Link Auditor finds broken, redirected, old-site, and staging links. It shows where links appear and checks whether your fixes worked against an explicitly saved scan.

It runs from inside WordPress admin, stays read-only, and produces results a site owner or developer can review or download. It does not auto-fix content or change the database.

Learn more at [IndexLane](https://indexlane.dev/plugins/redirect-internal-link-auditor).

Version 0.5 can:

* Scan all published content or a numeric limit of the newest content.
* Scan any registered public post type, not only posts, pages, and products.
* Extract links from post content.
* Check same-site link status.
* Flag 404/410 responses.
* Flag 301/302 redirects and redirect chains.
* Flag links to old domains supplied by the administrator.
* Flag common staging and development-domain links.
* Show the source post/page.
* Group broken/error and redirected URLs by times linked and content items affected.
* Show status, redirects, final URL, warnings, and outcome for each URL.
* Report one content-link coverage row per scanned published item.
* Count times linked, distinct linking items, outgoing links, distinct URLs, link-text variants, self-links, and direct versus redirected incoming links.
* Resolve redirects to their final published WordPress content item while retaining redirect results.
* Filter content with zero or one detected linking content item and open every target's link details.
* Download separate content-coverage, problem-URL, and link-detail CSV reports.
* Save one explicitly selected completed scan for comparison per administrator.
* Download and upload strict, versioned, site-specific saved-scan JSON.
* Rerun the saved scan's exact scope as a fix check.
* Classify issue URLs as new, changed, resolved, or still present.
* Compare saved-scan and latest-scan status chains, redirects, final URLs, outcomes, times linked, and affected content counts.
* Download the exact completed comparison as CSV.
* Delete the saved scan explicitly without changing current scan results.
* Show live content checked, links found, unique URLs, and links needing attention.
* Pause, continue, cancel, and resume a saved scan after reloading the page.
* Provide translation-ready administrator, progress, result, JavaScript, and CSV strings through the WordPress.org text domain.

The problem-URL view is derived from the completed link results. It does not make more HTTP requests. Two links to one URL in the same page count as two times linked and one affected content item.

The content-link coverage view is also derived from the exact completed scan without additional HTTP requests. “No incoming links detected in scanned content” means only that no links were found in the selected items' stored `post_content`; menus, templates, widgets, shortcode output, and rendered page-builder content are outside these results.

URL grouping normalizes scheme and host case, fragments, and default ports. Paths, query strings, schemes, non-default ports, and trailing slashes remain distinct because they can return different results.

HTTP requests are made only to the current site. Links to old, staging, or development domains are still reported for review.

== Data handling ==

Scans run on demand from WordPress admin through authenticated AJAX batches. The active or completed session is stored in a per-user WordPress transient for up to 24 hours after its last activity. Abandoned sessions expire automatically, and completed-session downloads reuse the exact displayed results without scanning again.

An administrator may explicitly save one site-specific scan in their WordPress user options. It remains until it is replaced or deleted. Portable saved-scan JSON is limited to 20 MB and validated against the exact supported format and current site URL before upload.

The plugin does not create an account, call an IndexLane service, or add frontend tracking.

== Limits ==

Version 0.5 scans links found in the `post_content` field of selected public post types. It does not crawl menus, widgets, theme templates, page-builder metadata, shortcode output, or rendered frontend pages.

Each AJAX batch makes at most five outbound HTTP requests. A session begins with an explicit limit of 250 actual requests, including redirect hops. When that limit is reached, the administrator can increase it by 250 and continue without losing progress or recording incomplete results.

== Installation ==

1. Upload the `indexlane-redirect-internal-link-auditor` folder to `/wp-content/plugins/`.
2. Activate the plugin in WordPress admin.
3. Go to `Tools -> Redirect & Internal Link Auditor`.
4. Select the public content types and choose all published content or a numeric limit.
5. Start the scan, keep the page open while it runs, or pause and return later.
6. Review content-link coverage and download content-coverage, problem-URL, or link-detail CSV reports after the session completes.
7. Optionally save the completed scan for comparison, then check your fixes against the saved scan and download the comparison.

== Frequently Asked Questions ==

= Does this plugin change links or content? =

No. Version 0.5 is read-only and diagnostic only.

= Does this plugin store scan results? =

One active or completed session per administrator is stored temporarily in a WordPress transient for up to 24 hours after its last activity. One opt-in saved scan per administrator is stored in WordPress user options until explicitly replaced or deleted. The plugin does not create custom database tables or retain scan history.

= Does it use an external API? =

No. It uses WordPress HTTP requests and local WordPress content only.

= Does this plugin check external links? =

No. Old-site, staging, and development-site links are flagged but not fetched. A same-site redirect that points outside the site is reported without fetching the external URL.

== Screenshots ==

1. Scan setup with public content-type selection, collapsed advanced request settings, and saved-scan tools below the form.
2. Current scan progress and controls above compact saved-scan details.
3. Completed fix comparison with clear change categories and expandable technical details.

== Changelog ==

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
