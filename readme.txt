=== IndexLane Redirect & Internal Link Auditor ===
Contributors: wpfixpath
Tags: redirects, broken links, internal links, migration, audit
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find broken links and leftover migration URLs, open their editing locations, and check whether your fixes worked.

== Description ==

Changed page URLs, moved a WordPress site, or taken over its maintenance? IndexLane finds broken links and leftover migration URLs in selected stored WordPress sources. It shows the page, menu, or template where each link is maintained, then checks whether your edits resolved the issue.

Run it on demand from WordPress admin. No account or external scanning service is required. The plugin never edits your content or creates redirects.

= Check links after a migration =

Supply your old domains to find links that still point there. Common staging and development domains are also flagged. These off-site URLs are listed for review without contacting those sites.

= Clean up links after changing URLs =

Find internal links that still take a redirect, follow a redirect chain, or end at a 404/410 response. Review the final URL and open the original source to update the link.

= Find links that return 200 but still need attention =

A successful HTTP response does not always mean a link reaches the intended page. IndexLane reports a different or off-site canonical, noindex directives, meta-refresh redirects, and missing linked fragments in **Page intent**. Problem URLs includes responding destinations that need review or have a warning.

PDFs, images, and internal nofollow links are informational. Existing redirect, blocked, and broken outcomes keep their transport classification, with intent evidence beside them.

= Find the place to edit =

A broken footer link may be maintained once in a menu or template part. IndexLane keeps that editing location with the result, alongside the link text and whether its source is individual content or a shared site-wide area.

Choose which stored sources to inspect: published posts, pages and public custom post types; classic menus; Navigation entities; synced patterns; block templates and template parts; and assigned block widgets.

= Save a scan and check your fixes =

1. Open **Tools -> Redirect & Internal Link Auditor**, choose the areas to check, and select **Start scan**.
2. Review **Problem URLs** and the sources under **Where it appears**. Use **Link details** for the full list, including old-domain and staging links.
3. Select **Save these results for comparison** before editing the links.
4. Fix the links in their WordPress editors, return to the auditor, and select **Check fixes against saved scan**.
5. Review new, changed, resolved, and still-present issues. Select **Download comparison** to keep the evidence or share it with a client.

Keep the scan page open while it runs. You can pause, return later, and continue. If the request limit is reached, increase the allowance to finish the selected scope.

See the [illustrated walkthrough](https://github.com/lame13/wpfixpath-redirect-internal-link-auditor/blob/0.7.0/docs/quick-start.md) for a demo site with a broken footer link and an outdated service URL.

= Preview the reports =

These sample CSVs contain fictional site data and show the current export columns:

* [Problem URLs](https://github.com/lame13/wpfixpath-redirect-internal-link-auditor/blob/0.7.0/docs/sample-destination-impact.csv): grouped broken, redirected, and intent-review destinations with their editing locations.
* [Link details](https://github.com/lame13/wpfixpath-redirect-internal-link-auditor/blob/0.7.0/docs/sample-report.csv): individual links, source locations, HTTP results, and page intent.
* [Content coverage](https://github.com/lame13/wpfixpath-redirect-internal-link-auditor/blob/0.7.0/docs/sample-target-coverage.csv): incoming links from content and shared areas, outgoing links, and link text.

Content coverage includes a filter for pages with zero or one detected linking source. “No incoming links detected in selected sources” describes only the stored sources selected for that scan; it does not prove that a page is an orphan.

All reports reuse the completed scan without making more HTTP requests. Saved scans can also be downloaded and uploaded as site-specific JSON for longer-term storage.

Learn more at [IndexLane](https://indexlane.dev/plugins/redirect-internal-link-auditor). Developers can register additional stored-source adapters through the documented `indexlane_rila_source_providers` filter in the [project README](https://github.com/lame13/wpfixpath-redirect-internal-link-auditor/blob/0.7.0/README.md).

== Data handling ==

Scans run on demand from WordPress admin through authenticated AJAX batches. Each batch processes at most five stored sources and makes at most five outbound HTTP requests. The active or completed session is stored in a per-user WordPress transient for up to 24 hours after its last activity. Abandoned sessions expire automatically, and completed-session downloads reuse the exact displayed results without scanning again.

An administrator may explicitly save one site-specific scan in their WordPress user options. It remains until it is replaced or deleted. Portable saved-scan JSON is limited to 20 MB and validated against the exact supported format and current site URL before upload.

The plugin does not create an account, call an IndexLane service, or add frontend tracking.

== Limits ==

This is a stored-source scanner, not a rendered-site crawler. It reads only the selected built-in adapters or adapters registered by other plugins. It does not execute shortcodes, scan arbitrary metadata, render templates, inspect unsupported page-builder storage, or crawl frontend pages.

A scan can snapshot at most 100,000 stored sources. Block widgets are included only when their stored block structure is available and the widget is assigned to a widget area.

Final same-site responses are inspected up to 256 KB. Bodies are never retained and intent checks add no requests. Partial responses and bounded fragment inventories cannot prove an unseen fragment is missing. Checks inspect stored HTML targets, not JavaScript-generated content; fragment-only links within their source page are outside the scan. No canonical target or meta-refresh target is fetched.

Each AJAX batch makes at most five outbound HTTP requests. A session begins with an explicit limit of 250 actual requests, including redirect hops. When that limit is reached, the administrator can increase it by 250 and continue without losing progress or recording incomplete results.

== Installation ==

1. Upload the `indexlane-redirect-internal-link-auditor` folder to `/wp-content/plugins/`.
2. Activate the plugin in WordPress admin.
3. Go to `Tools -> Redirect & Internal Link Auditor`.
4. Select the stored link sources, public content types, and content limit.
5. Start the scan, keep the page open while it runs, or pause and return later.
6. Review Problem URLs for broken, redirected, and responding destinations needing attention, and Link details for all findings, including old-domain and staging links.
7. Save the completed results for comparison, edit the links in WordPress, then check your fixes against the saved scan and download the comparison.

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

1. Find broken, redirected, and responding URLs with page-intent warnings, plus the exact places to edit them. Results come from a small demo site.
2. Check fixes against a saved scan: the footer link is resolved, while the redirect and missing section still need attention.
3. Choose the WordPress areas and content types to scan. The demo checks pages and classic menus.
4. Read the Page intent column beside HTTP results and original linked fragments in Link details.

== Changelog ==

= 0.7.0 =

* Added canonical, noindex/nofollow, meta-refresh, fragment-target, and content-type evidence for final same-site responses.
* Added responding URLs needing attention to Problem URLs and a Page intent column to Link details.
* Added intent evidence to CSVs and saved-scan comparisons, with strict imports of older saved scans.
* Kept bodies bounded at 256 KB without retaining them or adding requests for each occurrence; partial fragment checks remain informational.
* Corrected HTML parsing, exact fragment matching, Navigation-block nofollow, and saved-scan consistency checks.
* Updated listing copy, example reports, screenshots, and the release walkthrough.

= 0.6.1 =

* Reworked the listing around migration checks, URL changes, and finding the exact place to edit a link.
* Added a scan, save, fix, and recheck walkthrough with links to sample CSV reports.
* Replaced the public screenshots with a small demo showing a broken footer link, its editing location, and a real fix comparison.
* Corrected the README's development-build wording and aligned release metadata and verification assertions.

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
