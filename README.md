# WPFixPath Redirect & Internal Link Auditor

Find broken, redirected, old-domain, and staging-domain links inside WordPress post, page, and product content.

The plugin runs in wp-admin and builds two cleanup views:

- a destination-impact table that groups broken/error and redirected targets by affected content and occurrence count
- the complete occurrence table with source page, linked URL, HTTP status, redirect count, final URL, warning, anchor text, and result label

Useful after migrations, redesigns, domain changes, or old cleanup work where internal links quietly drift.

## What it checks

- internal links found in post, page, and WooCommerce product content
- 404 and 410 targets
- 301 / 302 redirects
- redirect chains
- links pointing to old domains you enter
- common staging or development-domain links
- source page and anchor text
- destination-level impact across repeated links
- separate destination-impact and detailed-row CSV exports

## Destination impact

Version 0.2 adds one row per actionable destination before the full occurrence table. It groups completed result rows only; it does not recrawl or make additional requests.

The view includes:

- normalized destination URL
- broken/error, redirect, or combined impact
- total link occurrences
- distinct affected content items
- most severe result label
- observed HTTP status evidence
- maximum redirect count
- effective/final URL evidence
- warning evidence

Two links to the same destination in one page count as two occurrences and one affected content item. The same destination linked from a second page counts as another occurrence and a second affected content item.

Grouping normalizes scheme and host case, removes fragments, and removes default ports. Scheme, path, query string, non-default port, and trailing slash remain distinct because they can produce different HTTP behavior.

Rows are ordered deterministically by result severity, affected content count, occurrence count, and destination URL.

## Data handling

Checks run on demand inside wp-admin. Results are shown for the current run and can be exported as CSV without repeating the scan.

Completed results are cached in a per-user WordPress transient for up to one hour so both exports contain the exact evidence shown on screen. Destination impact is derived from those saved rows. The plugin does not create custom database tables.

The plugin does not create an account, call an IndexLane/WPFixPath service, or add frontend tracking.

## Limits

This is a content-link checker, not a crawler.

Version 0.2 scans links found in WordPress post, page, and product content. It does not crawl menus, widgets, theme templates, page-builder metadata, shortcode output, or rendered frontend pages.

It is read-only. It does not replace links, bulk edit content, schedule scans, create database tables, or add frontend badges.

Each run makes at most 250 outbound HTTP requests. Redirect hops each consume one request, and links that cannot be completed inside that budget are explicitly marked for review.

## CSV exports

Destination impact columns:

- Destination
- Impact
- Occurrences
- Affected Content Items
- Result
- HTTP Status Evidence
- Maximum Observed Redirects
- Observed Final URLs
- Warning Evidence

Detailed-row columns:

- Source Post/Page
- Source Type
- Source URL
- Linked URL
- HTTP Status
- Redirect Count
- Final URL
- Warning
- Anchor Text
- Result

Both exports protect spreadsheet cells that could otherwise be interpreted as formulas.

## Result labels

- OK
- Warning
- Blocked
- Error
- Needs review

Labels are intentionally conservative. The plugin reports link evidence; it does not guess SEO impact.

## Changelog

### 0.2.0

- Added the destination-centric impact view for broken/error and redirected targets.
- Added occurrence and distinct affected-content counts.
- Added deterministic severity and impact ordering.
- Added a separate destination-impact CSV while preserving the detailed-row export.
- Kept aggregation read-only and derived from the exact saved scan.
- Strengthened CSV formula-injection protection for values with leading whitespace.

## Development

Run a syntax check before packaging:

```bash
php -l wpfixpath-redirect-internal-link-auditor.php
```

For a manual WordPress check, copy or symlink this folder into:

```text
wp-content/plugins/
```

Then activate the plugin and open:

```text
Tools -> Redirect & Internal Link Auditor
```
