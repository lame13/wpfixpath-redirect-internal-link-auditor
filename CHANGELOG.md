# Changelog

## 0.7.0 - 2026-09-15

- Added destination-intent auditing for internal URLs that respond successfully but declare a different or off-site canonical, noindex, a meta refresh, or a missing linked fragment.
- Kept transport outcomes dominant and reported file responses, inconclusive fragments, and page/internal nofollow as informational evidence.
- Reused bounded final-response evidence across link occurrences without extra HTTP requests or retaining response bodies.
- Added page intent to Problem URLs, Link details, saved-scan comparisons, and CSV exports; retained strict import compatibility with 0.5 and 0.6 saved scans.
- Fixed literal HTML being mistaken for metadata, exact and numeric fragment matching, partial-response handling, relative canonical bases, Navigation-block rel evidence, and UTF-8 byte limits.
- Updated the WordPress.org listing, example CSVs, screenshots, walkthrough, release notes, and publishing commands.

## 0.6.1 - 2026-09-07

- Reworked the WordPress.org listing and README around migration checks, URL changes, and finding the exact editable source of a link.
- Added an illustrated scan, save, fix, and recheck walkthrough and direct links to the existing sample CSV reports.
- Replaced the public screenshots with a small demo showing a broken footer link, its editing location, and a genuine saved-scan comparison after editing the link.
- Corrected development-build wording and aligned plugin metadata, saved-scan version assertions, and release packaging for 0.6.1.

## 0.6.0 - 2026-09-02

- Added independently selectable link-source adapters for normal content, classic menus, Navigation entities, synced patterns, block templates, template parts, and assigned block widgets.
- Retained each occurrence's stable source identity, source surface, edit and source URLs, link text, and individual-content or site-wide scope alongside the existing HTTP and redirect evidence.
- Expanded content coverage, problem-URL reports, details, comparisons, and CSV exports with contextual and site-wide counts plus exact editable locations.
- Added the public `indexlane_rila_source_providers` filter for bounded third-party stored-source adapters without executing shortcodes, scanning arbitrary metadata, or crawling rendered pages.
- Upgraded resumable session and portable saved-scan schemas with strict legacy migration, provider availability checks, five-source batches, and a 100,000-source session limit.
- Reworked the scan form and result wording for non-technical administrators, regenerated the WordPress.org screenshots, and expanded behavioral, integration, translation, and authenticated AJAX coverage.

## 0.5.1 - 2026-08-31

- Rewrote the administrator workflow around saved scans, fix checks, results, URLs, link details, and downloads without changing the plugin name or read-only behavior.
- Put the first scan form before the empty saved-scan panel and current scan progress before saved-scan details.
- Moved request controls into collapsed advanced settings and moved request accounting, exact settings, schema data, and raw JSON downloads into technical details.
- Reduced comparison, content-coverage, and problem-URL tables to their primary decisions while retaining HTTP, redirect, final-URL, and before/after data in expandable details.
- Simplified completed-scan summaries, added correct singular/plural labels, grouped downloads by purpose, and updated CSV headings.
- Regenerated all WordPress screenshots and expanded behavioral, integration, translation, and authenticated AJAX coverage for the revised interface.

## 0.5.0 - 2026-08-31

- Added one opt-in, site-specific baseline per administrator with strict JSON export, import, replacement, and deletion controls.
- Added exact-scope verification scans bound to the saved baseline revision so fixes can be compared without silently changing scope.
- Added new, changed, resolved, and still-present issue classifications with explicit before-and-after evidence and a comparison CSV export.
- Split the plugin implementation into focused admin, scan, report, and baseline modules while preserving the existing runtime class and public behavior.
- Updated packaging, documentation, screenshots, translation auditing, behavioral tests, WordPress integration tests, and authenticated AJAX coverage for the complete baseline lifecycle.

## 0.4.0 - 2026-08-29

- Added destination-oriented content link coverage for every published content item in a completed scan.
- Added incoming and outgoing link counts, distinct linking sources and destinations, anchor-text variants, self-link counts, and direct-versus-redirected evidence.
- Added a target detail view and a filter for content with zero or one detected linking source.
- Resolved redirected links to their final published WordPress content item while retaining the original redirect evidence and making no additional HTTP requests.
- Added an exact-session target-coverage CSV export, updated screenshots and documentation, and expanded behavioral, integration, translation, and authenticated AJAX coverage.

## 0.3.1 - 2026-08-28

- Removed the authenticated AJAX end-to-end harness's undeclared ripgrep dependency so release validation runs on standard GitHub-hosted runners.

## 0.3.0 - 2026-08-28

- Replaced the bounded synchronous form request with authenticated, browser-driven WordPress AJAX batches.
- Added resumable per-administrator scan sessions for all published content or a numeric newest-content limit.
- Added selection for every registered public post type.
- Added live progress for content processed, links extracted, unique destinations checked, HTTP requests, and actionable issues.
- Added pause, continue, cancel, reload recovery, and explicit 250-request allowance extensions.
- Limited each AJAX batch to five content items and five actual outbound HTTP requests while allowing redirect chains to continue safely across batches.
- Deduplicated destination requests across the complete session without collapsing occurrence-level evidence.
- Limited CSV export to exact completed-session evidence and extended temporary per-user session retention to 24 hours of inactivity.
- Added automatic abandoned-session expiry without cron jobs, custom tables, telemetry, or content changes.
- Made all plugin-owned administrator, JavaScript, result, warning, and CSV strings translation-ready with literal gettext calls, translator notes, and the WordPress.org slug text domain.
- Added repeatable source auditing and local POT extraction verification without bundling translations.
- Added WordPress-loaded integration tests and authenticated AJAX end-to-end coverage.

## 0.2.2 - 2026-08-26

- Moved the admin page CSS into a stylesheet enqueued only on the plugin's Tools screen.

## 0.2.1 - 2026-08-25

- Renamed the pre-approval plugin to IndexLane Redirect & Internal Link Auditor and aligned its slug, text domain, runtime identifiers, tests, and package layout.
- Prepared plugin metadata and packaging for the WordPress.org Plugin Directory.
- Removed the third-party `Update URI` so WordPress.org can deliver plugin updates.
- Declared compatibility testing through WordPress 7.1.
- Corrected the WordPress.org contributor metadata.
- Resolved Plugin Check findings for translation loading, nonce verification, translatable labels, and CSV output.
- Moved the project release history to this root changelog instead of duplicating it in the README.

## 0.2.0 - 2026-08-24

- Added a destination-centric impact view for broken/error and redirected targets.
- Grouped normalized destinations by total link occurrences and distinct affected content items.
- Preserved HTTP status, redirect-count, effective-final-URL, warning, and result evidence.
- Added deterministic severity, affected-source, occurrence, and URL ordering.
- Added a separate destination-impact CSV while preserving the detailed occurrence export.
- Derived both the admin summary and its export from the exact saved scan without additional HTTP requests.
- Strengthened CSV formula-injection protection for values with leading whitespace or line breaks.

## 0.1.3 - 2026-07-21

- Kept trailing-slash URL variants distinct in request caching and redirect-loop detection.
- Made the 250-request limit count every actual outbound HTTP request, including redirect hops.
- Replaced HEAD-derived status claims with bounded GET verification through the safe WordPress HTTP API.
- Exported the exact completed scan from short-lived per-user storage instead of rescanning content and URLs.

## 0.1.2 - 2026-05-24

- Rewrote README, readme, and plugin metadata copy in a less defensive voice.

## 0.1.1 - 2026-05-24

- Updated public screenshots from the current WordPress admin UI.

## 0.1.0 - 2026-05-24

- Added admin-only Tools screen.
- Added scan support for published posts, pages, and products when available.
- Added internal link extraction from content.
- Added same-site HTTP status checks.
- Added 404/410, redirect, redirect-chain, old-domain, and staging/dev-domain flags.
- Added results table and CSV export.
- Kept version 0.1 read-only with no telemetry, no scheduled scans, and no database storage.
