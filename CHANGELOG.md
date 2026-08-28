# Changelog

## 0.3.1 - 2026-08-28

- Removed the authenticated AJAX end-to-end harness's undeclared ripgrep dependency so release validation runs on standard GitHub-hosted runners.

## 0.3.0

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

## 0.2.2

- Moved the admin page CSS into a stylesheet enqueued only on the plugin's Tools screen.

## 0.2.1

- Renamed the pre-approval plugin to IndexLane Redirect & Internal Link Auditor and aligned its slug, text domain, runtime identifiers, tests, and package layout.
- Prepared plugin metadata and packaging for the WordPress.org Plugin Directory.
- Removed the third-party `Update URI` so WordPress.org can deliver plugin updates.
- Declared compatibility testing through WordPress 7.1.
- Corrected the WordPress.org contributor metadata.
- Resolved Plugin Check findings for translation loading, nonce verification, translatable labels, and CSV output.
- Moved the project release history to this root changelog instead of duplicating it in the README.

## 0.2.0

- Added a destination-centric impact view for broken/error and redirected targets.
- Grouped normalized destinations by total link occurrences and distinct affected content items.
- Preserved HTTP status, redirect-count, effective-final-URL, warning, and result evidence.
- Added deterministic severity, affected-source, occurrence, and URL ordering.
- Added a separate destination-impact CSV while preserving the detailed occurrence export.
- Derived both the admin summary and its export from the exact saved scan without additional HTTP requests.
- Strengthened CSV formula-injection protection for values with leading whitespace or line breaks.

## 0.1.3

- Kept trailing-slash URL variants distinct in request caching and redirect-loop detection.
- Made the 250-request limit count every actual outbound HTTP request, including redirect hops.
- Replaced HEAD-derived status claims with bounded GET verification through the safe WordPress HTTP API.
- Exported the exact completed scan from short-lived per-user storage instead of rescanning content and URLs.

## 0.1.2

- Rewrote README, readme, and plugin metadata copy in a less defensive voice.

## 0.1.1

- Updated public screenshots from the current WordPress admin UI.

## 0.1.0

- Added admin-only Tools screen.
- Added scan support for published posts, pages, and products when available.
- Added internal link extraction from content.
- Added same-site HTTP status checks.
- Added 404/410, redirect, redirect-chain, old-domain, and staging/dev-domain flags.
- Added results table and CSV export.
- Kept version 0.1 read-only with no telemetry, no scheduled scans, and no database storage.
