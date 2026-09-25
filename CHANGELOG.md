# Changelog

## 1.0.0 - 2026-09-26

You can now fix links from the results page and schedule checks to catch new problems. Scanning still leaves your content unchanged; repairs need your confirmation.

- **Fix a URL where it is stored.** Preview the before-and-after values, then apply the replacement to supported content, menus, Navigation blocks, patterns, templates, and block widgets. Redirect destinations can provide a starting suggestion.
- **Undo recent repairs.** The history keeps up to 25 repairs within an 8 MB budget. Undo skips sources edited since the repair, and a partially undone repair can be retried.
- **Set aside known issues.** Acknowledge a URL and its current outcome to remove it from the attention count and change notifications. Its evidence stays in the reports.
- **Check on a schedule.** Choose hourly, twice-daily, daily, or weekly checks. The first complete check sends a summary; later emails report newly detected or resolved problems. See the latest status on the dashboard and in Site Health.
- **Use WP-CLI.** Scan, preview or apply a repair, list recent repairs, and undo from the command line. JSON and CSV scan output can be consumed by scripts.
- Fixed repairs that could change URL-shaped text in scripts, comments, or unrelated attributes. Repairs now preserve WordPress escaping, count each stored change once, and refuse unsupported source storage.
- Bound confirmation to the previewed values, saved undo evidence before content writes, and protected the journal from overlapping repairs. Changed content requires a new preview.
- Fixed partial scans being reported as complete. Incomplete monitoring results no longer mark unseen problems as resolved or replace the last complete comparison. CLI repairs require a complete scan.
- Renamed the listing to **IndexLane Broken Link & Redirect Auditor**. The plugin slug, text domain, and existing saved-scan format are unchanged.
- Rewrote the WordPress description and release notes around the scan, repair, and recheck workflow, with clearer limits.

## 0.7.0 - 2026-09-15

- Added checks for links that load successfully but still need attention: different canonical URLs, noindex directives, meta refreshes, and missing linked sections.
- Added **Page intent** to Link details, Problem URLs, CSVs, and saved-scan comparisons.
- Kept broken, blocked, and redirected outcomes visible alongside the new evidence. File responses and inconclusive fragment checks remain informational.
- Reused each destination response for its link occurrences, with no extra requests or saved response bodies.
- Fixed HTML parsing, exact fragment matching, partial responses, relative canonical URLs, Navigation-block nofollow, and saved-scan validation.
- Updated the listing, example reports, screenshots, walkthrough, and publishing instructions.

## 0.6.1 - 2026-09-07

- Reworked the listing around migration checks, changed URLs, and finding the right place to edit a link.
- Added an illustrated scan, save, fix, and recheck walkthrough with sample CSV reports.
- Replaced the screenshots with a small demo showing a broken footer link and a real comparison after fixing it.
- Aligned the development-build wording, release metadata, packaging, and test expectations.

## 0.6.0 - 2026-09-02

- Expanded scans beyond posts and pages to classic menus, Navigation blocks, synced patterns, templates, template parts, and assigned block widgets. Each source type can be selected separately.
- Kept the exact editing location and link text with each result, including whether a link lives in individual content or a shared site area.
- Added these sources to coverage reports, Problem URLs, comparisons, and CSVs.
- Added a filter for developers to register other stored-source adapters.
- Updated resumable scans and saved-scan imports for the wider source selection, with bounded batches and a 100,000-source limit.
- Simplified the source controls and refreshed the screenshots and tests.

## 0.5.1 - 2026-08-31

- Replaced technical labels with plainer wording such as saved scans, fix checks, link details, and downloads.
- Put scan setup and progress before saved-scan controls, and moved advanced request settings into expandable details.
- Made comparison and coverage tables easier to scan while keeping the underlying evidence available.
- Grouped downloads, clarified CSV headings, fixed plural wording, and refreshed the screenshots and tests.

## 0.5.0 - 2026-08-31

- Added one optional saved scan per administrator, with JSON download, upload, replacement, and deletion.
- Added fix checks that repeat the saved scan's settings and compare against that specific saved result.
- Showed which issues are new, changed, resolved, or still present, with a comparison CSV.
- Split the plugin into smaller modules and updated packaging, documentation, translation checks, and tests.

## 0.4.0 - 2026-08-29

- Added content coverage for every published item included in a completed scan.
- Showed incoming and outgoing links, distinct linking sources, anchor-text variants, self-links, and direct versus redirected links.
- Added details for each target and a filter for pages with zero or one detected linking source.
- Added a coverage CSV that uses the completed results without making another scan.
- Updated screenshots, documentation, and tests.

## 0.3.1 - 2026-08-28

- Removed an undeclared ripgrep dependency from the authenticated AJAX tests so release checks run on standard GitHub-hosted runners.

## 0.3.0 - 2026-08-28

- Added resumable scans with live progress, pause, continue, cancel, and recovery after a page reload.
- Added a choice of all published content or a numeric limit, plus selection of public post types.
- Moved scans into small authenticated AJAX batches, processing at most five sources and five outbound requests per batch.
- Counted redirect hops toward the request allowance and let administrators increase the limit without losing progress.
- Reused destination checks across a scan while retaining every link occurrence, and limited downloads to completed results.
- Added per-administrator sessions that expire after 24 hours of inactivity.
- Made plugin-owned messages translation-ready and added WordPress integration and authenticated AJAX tests.

## 0.2.2 - 2026-08-26

- Moved the admin CSS into a stylesheet loaded only on the plugin's Tools page.

## 0.2.1 - 2026-08-25

- Renamed the pre-approval plugin to IndexLane Redirect & Internal Link Auditor and aligned its slug and text domain.
- Prepared metadata and packaging for WordPress.org, removed the third-party update URI, and corrected contributor details.
- Declared compatibility testing through WordPress 7.1 and resolved Plugin Check findings around translations, nonces, labels, and CSV output.
- Moved the release history into this changelog.

## 0.2.0 - 2026-08-24

- Added a destination view that groups broken and redirected URLs by how many links and content items they affect.
- Kept HTTP status, redirect count, final URL, warnings, and outcome with each group, ordered by severity and impact.
- Added a destination-impact CSV alongside the existing link-detail export.
- Strengthened CSV formula-injection protection for values starting with whitespace or line breaks.

## 0.1.3 - 2026-07-21

- Kept URLs with and without a trailing slash separate in the request cache and loop detection.
- Made the 250-request allowance count every outbound request, including redirect hops.
- Switched status checks from HEAD to bounded GET requests through WordPress's safe HTTP API.
- Exported completed results from temporary per-user storage instead of scanning again during download.

## 0.1.2 - 2026-05-24

- Rewrote the README, WordPress description, and plugin metadata in plainer language.

## 0.1.1 - 2026-05-24

- Updated the public screenshots from the WordPress admin interface.

## 0.1.0 - 2026-05-24

- Added an administrator-only Tools page for scanning links in published posts, pages, and products when available.
- Checked same-site HTTP responses and flagged 404/410 errors, redirects, redirect chains, old domains, and staging or development URLs.
- Added a results table and CSV export.
- Shipped as a manual, read-only diagnostic tool without telemetry, scheduled scans, or saved results.
