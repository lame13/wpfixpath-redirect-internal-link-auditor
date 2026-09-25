=== IndexLane Broken Link & Redirect Auditor ===
Contributors: wpfixpath
Tags: redirects, broken links, internal links, migration, audit
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find broken internal links, fix stored URLs with preview and undo, and schedule checks that email you when problems change.

== Description ==

A moved page. An old staging URL in the footer. A link that loads, but lands in the wrong place. Small problems like these are easy to leave behind when a site changes.

**IndexLane helps you find them, see where to edit them, and check the result.** Scan the WordPress content you choose, review the links that need attention, and replace a URL from the results page or open its WordPress editor. Turn on scheduled checks when you want help keeping up with new problems.

It's free, runs inside WordPress, and needs no account or external scanning service.

= Find more than a 404 =

Check internal links for broken pages, redirects, and redirect chains. Add your old domains to find migration leftovers; common staging and development domains are flagged too, without contacting those sites.

A page can return a successful response and still deserve a look. IndexLane also flags a different canonical URL, noindex directives, meta-refresh redirects, and missing linked sections such as `#pricing`. These appear beside the HTTP result under **Page intent**, so you can judge what needs changing.

= Go straight to the source =

A broken footer link might live in one menu, even if visitors see it on every page. IndexLane shows the source where the link is maintained, with its link text and an editing link.

Choose from published posts, pages and public custom post types; classic menus; Navigation blocks; synced patterns; block templates and template parts; and assigned block widgets. You decide which areas to check.

= Fix a URL without hunting through every page =

Under **Fix links**, enter a replacement URL and select **Preview changes**. Review the affected sources and expand the full before-and-after values, then apply the repair. A redirect's final URL may be offered as a suggestion; you choose whether to use it.

Repairs change matching stored link attributes and supported block link settings. Recent repairs keep an undo history. Undo skips sources edited since the repair, so it won't discard your later work.

Theme-file templates, menu items whose URLs follow a WordPress page or other object, and unsupported storage need their own editors. IndexLane does not create redirects.

= Check that your changes helped =

Save a completed scan before making changes, then select **Check fixes against saved scan**. The comparison shows new, changed, resolved, and still-present issues in the same selected scope. Download it as a CSV for your records or a client handover.

Content coverage also shows which scanned pages have few incoming links from the sources you selected. It's a useful starting point for review, not proof that a page has no links anywhere on the site.

= Keep an eye on new problems =

Choose an hourly, twice-daily, daily, or weekly check. After the first complete check sends a summary, later emails report newly detected or resolved problems. Acknowledge a known issue to remove it from the attention count and change notifications while keeping its evidence in reports.

Schedules use WP-Cron and depend on site visits or your server's cron setup. If a check reaches its request limit, the result is marked partial and is not used to claim that earlier problems are fixed. Status is available on the WordPress dashboard and in Site Health.

= Start with one scan =

1. Open **Tools -> Redirect & Internal Link Auditor** and choose the areas to check.
2. Select **Start scan** and keep the page open. You can pause and return later, or increase the request allowance if needed.
3. Review **Problem URLs** and **Link details**. Save the results for comparison before making changes.
4. Preview a replacement under **Fix links**, or use an editing link to make the change yourself.
5. Run **Check fixes against saved scan** and review the comparison.

The [illustrated walkthrough](https://github.com/lame13/wpfixpath-redirect-internal-link-auditor/blob/1.0.0/docs/quick-start.md) shows a broken footer link and an outdated service URL on a small demo site.

= See what the reports include =

These sample CSVs use fictional site data:

* [Problem URLs](https://github.com/lame13/wpfixpath-redirect-internal-link-auditor/blob/1.0.0/docs/sample-destination-impact.csv): destinations that need attention and the sources linking to them.
* [Link details](https://github.com/lame13/wpfixpath-redirect-internal-link-auditor/blob/1.0.0/docs/sample-report.csv): individual links, editing locations, HTTP results, and page intent.
* [Content coverage](https://github.com/lame13/wpfixpath-redirect-internal-link-auditor/blob/1.0.0/docs/sample-target-coverage.csv): incoming and outgoing links within the selected sources.

Downloads reuse completed scan results without making another scan. You can also download or upload a saved scan as site-specific JSON.

Learn more at [IndexLane](https://indexlane.dev/plugins/redirect-internal-link-auditor). Developers can add stored-source adapters through the filter documented in the [project README](https://github.com/lame13/wpfixpath-redirect-internal-link-auditor/blob/1.0.0/README.md).

== WP-CLI ==

Agencies and deployment scripts can run the same checks without a browser:

* `wp indexlane scan --content-scope=all --format=table` checks the site and lists the URLs that need attention.
* `wp indexlane fix --from=OLD --to=NEW --dry-run` previews an exact replacement; the same command without `--dry-run` applies it.
* `wp indexlane undo` restores the most recent repair, and `wp indexlane repairs` lists the recorded history.

== Data handling ==

Scans run on demand from WordPress admin through authenticated AJAX batches, or from the scheduled check and WP-CLI without a browser. Each batch processes at most five stored sources and makes at most five outbound HTTP requests. The active or completed browser scan is stored in a per-user WordPress transient for up to 24 hours after its last activity. Abandoned sessions expire automatically, and completed-session downloads reuse the exact displayed results without scanning again.

An administrator may explicitly save one site-specific scan in their WordPress user options. It remains until it is replaced or deleted. Portable saved-scan JSON is limited to 20 MB and validated against the exact supported format and current site URL before upload.

A repair writes only the stored link values you confirmed, through the normal WordPress post, menu-item, and block-widget APIs, and records the previous values in a bounded site option so the repair can be undone. The history keeps up to 25 repairs within an 8 MB total budget, including before-and-after values and their metadata. Older entries are removed as the history fills up. Acknowledged URLs are stored once per site, and the scheduled check stores its configuration, its comparison set, and the last result summary.

The plugin does not create an account, call an IndexLane service, or add frontend tracking.

== Limits ==

This is a stored-source scanner, not a rendered-site crawler. It reads only the selected built-in adapters or adapters registered by other plugins. It does not execute shortcodes, scan arbitrary metadata, render templates, inspect unsupported page-builder storage, or crawl frontend pages.

A scan can snapshot at most 100,000 stored sources. Block widgets are included only when their stored block structure is available and the widget is assigned to a widget area.

Final same-site responses are inspected up to 256 KB. Bodies are never retained and intent checks add no requests. Partial responses and bounded fragment inventories cannot prove an unseen fragment is missing. Checks inspect stored HTML targets, not JavaScript-generated content; fragment-only links within their source page are outside the scan. No canonical target or meta-refresh target is fetched.

Each AJAX batch makes at most five outbound HTTP requests. A session begins with an explicit limit of 250 actual requests, including redirect hops. When that limit is reached, the administrator can increase it by 250 and continue without losing progress or recording incomplete results.

A repair handles one linked URL at a time, including its fragment. It is limited to 500 stored sources and must fit within the 8 MB undo budget. Changes made after the preview require a fresh preview. Undo is available while the repair remains in history and the stored values still match. Keep your normal site backups.

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

Scanning and scheduled checks do not change your content. Applying a repair changes the matching stored links after you confirm the preview. Recent repairs can be undone while their history is retained, provided the source has not been edited again.

= Does this plugin store scan results? =

One active or completed session per administrator is stored temporarily in a WordPress transient for up to 24 hours after its last activity. One opt-in saved scan per administrator is stored in WordPress user options until explicitly replaced or deleted. Repairs and acknowledged issues are stored in bounded site options. Scheduled checks also store their settings, temporary progress, issue identities, and latest summary. The plugin does not create custom database tables or keep a history of every scan.

= Does this plugin create redirects? =

No. It reports links that take a redirect and can replace the stored link with its final URL, but it never creates or edits a redirect rule.

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

= 1.0.0 =

* Fix a stored URL from the results page, with a before-and-after preview and undo for recent repairs.
* Acknowledge known issues and schedule checks with an initial summary and emails when problems change.
* Check status from the dashboard or Site Health, or scan, repair, and undo through WP-CLI.
* Keep repairs confined to supported link storage, preserve escaped content, and require a new preview if the source changes.
* Correct replacement counts, undo handling, partial-scan reporting, and JSON/CSV command output.
* Updated the description and release notes to explain the workflow and its limits more clearly.

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

== Upgrade Notice ==

= 1.0.0 =

You can now repair stored links after previewing the changes, undo recent repairs, acknowledge known issues, and turn on scheduled checks. Scanning still leaves your content unchanged. Scheduled checks are off until you enable them.
