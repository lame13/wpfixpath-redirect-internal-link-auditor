# Changelog

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
