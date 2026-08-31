=== IndexLane Redirect & Internal Link Auditor ===
Contributors: wpfixpath
Tags: redirects, broken links, internal links, migration, audit
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Audit redirects, broken links, and content link coverage inside WordPress.

== Description ==

IndexLane Redirect & Internal Link Auditor finds internal content links that return 404/410, redirect through 301/302, or still point to old, staging, or development domains. It also shows how scanned content links to each published item and can verify fixes against an explicitly saved evidence baseline.

It runs from inside WordPress admin, stays read-only, and produces evidence a site owner or developer can review or export. It does not auto-fix content or mutate the database.

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
* Group broken/error and redirected destinations by occurrence count and distinct affected content count.
* Show status, redirect, effective-final-URL, warning, and result evidence for each destination.
* Report one content-link coverage row per scanned published item.
* Count incoming occurrences, distinct linking items, outgoing occurrences, distinct destinations, anchor variants, self-links, and direct versus redirected incoming links.
* Resolve redirects to their final published WordPress content item while retaining redirect evidence.
* Filter content with zero or one detected linking source and open every target's source-and-anchor details.
* Export separate target-coverage, destination-impact, and detailed-row CSV reports.
* Save one explicitly selected completed scan as a per-administrator baseline.
* Export and import strict, versioned, site-specific baseline JSON.
* Rerun the baseline's exact scope as a verification scan.
* Classify issue destinations as new, changed, resolved, or still present.
* Compare baseline and verification status chains, redirects, final URLs, severity, occurrences, and affected content counts.
* Export the exact completed verification comparison as CSV.
* Delete the saved baseline explicitly without changing temporary scan evidence.
* Show live content, link, unique-destination, HTTP-request, and actionable-issue progress.
* Pause, continue, cancel, and resume a saved scan after reloading the page.
* Provide translation-ready administrator, progress, evidence, JavaScript, and CSV strings through the WordPress.org text domain.

The destination-impact view is derived from the completed occurrence rows. It does not make more HTTP requests. Two links to one destination in the same page count as two occurrences and one affected content item.

The content-link coverage view is also derived from the exact completed scan without additional HTTP requests. “No incoming links detected in scanned content” means only that no links were found in the selected items' stored `post_content`; menus, templates, widgets, shortcode output, and rendered page-builder content are outside this evidence.

Destination grouping normalizes scheme and host case, fragments, and default ports. Paths, query strings, schemes, non-default ports, and trailing slashes remain distinct because they can return different evidence.

HTTP requests are made only to the current site. Links to old, staging, or development domains are still reported for review.

== Data handling ==

Scans run on demand from WordPress admin through authenticated AJAX batches. The active or completed session is stored in a per-user WordPress transient for up to 24 hours after its last activity. Abandoned sessions expire automatically, and completed-session exports reuse the exact displayed evidence without scanning again.

An administrator may explicitly save one site-specific baseline in their WordPress user options. It remains until it is replaced or deleted. Portable baseline JSON is limited to 20 MiB and validated against the exact supported schema and current site URL before import.

The plugin does not create an account, call an IndexLane service, or add frontend tracking.

== Limits ==

Version 0.5 scans links found in the `post_content` field of selected public post types. It does not crawl menus, widgets, theme templates, page-builder metadata, shortcode output, or rendered frontend pages.

Each AJAX batch makes at most five outbound HTTP requests. A session begins with an explicit allowance of 250 actual requests, including redirect hops. When that allowance is reached, the administrator can grant another 250 requests and continue without losing progress or recording incomplete evidence.

== Installation ==

1. Upload the `indexlane-redirect-internal-link-auditor` folder to `/wp-content/plugins/`.
2. Activate the plugin in WordPress admin.
3. Go to `Tools -> Redirect & Internal Link Auditor`.
4. Select the public content types and choose all published content or a numeric limit.
5. Start the scan, keep the page open while it runs, or pause and return later.
6. Review content-link coverage and export target-coverage, destination-impact, or detailed-row CSV reports after the session completes.
7. Optionally save the completed scan as a baseline, then run its exact scope again to verify fixes and export the comparison.

== Frequently Asked Questions ==

= Does this plugin change links or content? =

No. Version 0.5 is read-only and diagnostic only.

= Does this plugin store scan results? =

One active or completed session per administrator is stored temporarily in a WordPress transient for up to 24 hours after its last activity. One opt-in baseline per administrator is stored in WordPress user options until explicitly replaced or deleted. The plugin does not create custom database tables or retain scan history.

= Does it use an external API? =

No. It uses WordPress HTTP requests and local WordPress content only.

= Does this plugin check external links? =

No. Old, staging, and development-domain links are flagged but not fetched. A same-site redirect that points outside the site is reported without fetching the external destination.

== Screenshots ==

1. Baseline-aware scan setup with public post-type selection and portable JSON import access.
2. Saved baseline metadata with JSON export and a paused exact-scope verification scan.
3. Completed verification comparison with remediation categories and baseline-versus-current evidence.

== Changelog ==

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
