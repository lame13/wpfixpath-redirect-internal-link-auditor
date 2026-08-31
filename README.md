# IndexLane Redirect & Internal Link Auditor

Audit redirects, broken links, and content link coverage in the stored content of any public WordPress post type.

Version 0.5 adds saved scans and exact-scope fix checks to the complete, resumable scan and content-link coverage workflow. It remains read-only, never follows external redirect targets, and does not call an IndexLane service.

Project page: [indexlane.dev/plugins/redirect-internal-link-auditor](https://indexlane.dev/plugins/redirect-internal-link-auditor)

## Complete scan sessions

- Choose all published content or a numeric limit of the newest content.
- Select any registered public post type.
- Process work through authenticated WordPress AJAX in small browser-driven batches.
- See content checked, links found, unique URLs checked, and links needing attention.
- Pause, continue, cancel, or reload and resume one per-administrator session.
- Deduplicate requests for the same URL across the entire session while retaining every link found.
- Stop at an explicit 250-request limit and increase that limit by 250 when needed.
- Download content-coverage, problem-URL, and link-detail CSVs only from exact completed scan results.

Each AJAX batch processes at most five content items and makes at most five actual outbound HTTP requests. Redirect chains persist between batches, so a request-limit boundary never creates a partial result row.

## Saved scans and fix checks

- Save one explicitly selected completed scan for comparison for the current administrator.
- Download or upload portable, versioned JSON scan data with strict format, file-size, completeness, counter, and site-URL validation.
- Rerun the saved post types, content scope, old domains, timeout, and redirect limit exactly.
- Bind each fix check to the saved scan revision used when it started.
- Classify URL issues as new, changed, resolved, or still present.
- Show saved-scan and latest-scan status chains, redirect counts, final URLs, outcomes, times linked, and content items affected.
- Download the completed comparison as CSV without making additional HTTP requests.
- Delete the saved scan explicitly without deleting the current temporary scan session.

The comparison is derived entirely from retained results. It never rescans during page rendering or download, and it refuses to compare when the saved scan changed after the fix check started.

## What it checks

- same-site links found in selected posts' `post_content`
- 404 and 410 targets
- 301, 302, 303, 307, and 308 redirects
- redirect chains, loops, and configured redirect limits
- same-site redirects that leave the site, without fetching the external target
- links pointing to old domains entered by the administrator
- common staging or development-domain links
- content item, edit URL, link text, status chain, redirect count, final URL, warning, and outcome

Unrelated external links are skipped. Old, staging, and development-domain links are reported but never fetched.

## Content link coverage

The completed report contains one row per scanned published content item. Each row includes:

- content title and URL
- times linked and distinct linking content items
- outgoing internal links and distinct linked URLs
- link-text variants pointing to the content
- self-link count
- direct and redirected incoming-link counts
- a conservative zero-, one-, or multiple-source status

Open a content item's link details to review every source, link text, linked URL, status chain, and final URL. Filter the report to content with zero or one detected linking content item, or download the complete content-coverage CSV.

When a scanned link redirects to a published WordPress URL, coverage counts the link toward that final content item and retains the redirect results. The coverage report makes no additional HTTP requests.

“No incoming links detected in scanned content” describes only the selected items' stored `post_content`. It does not inspect menus, templates, widgets, shortcode output, or rendered page-builder content.

## Problem URLs

One row is derived for each broken/error or redirected URL. The primary view shows the URL, outcome, times linked, and content items affected. HTTP status, redirect count, final URLs, and warnings remain available under technical details. The report never makes additional requests.

URL grouping normalizes scheme and host case, fragments, and default ports. Paths, query strings, schemes, non-default ports, and trailing slashes remain distinct because they can return different results.

## Data handling

One active or completed scan session per administrator is stored in a WordPress transient. Its sliding expiry is 24 hours, so abandoned sessions are cleaned up automatically by WordPress and completed results remain available briefly for download.

One opt-in, site-specific saved scan per administrator is stored in WordPress user options until explicitly replaced or deleted. Portable JSON supports longer-term storage outside WordPress without creating an in-plugin scan-history system.

The plugin creates no custom table, cron job, account, telemetry, frontend tracking, or content mutation.

All plugin-owned administrator, status, warning, result, JavaScript, and CSV-header strings use the `indexlane-redirect-internal-link-auditor` text domain. WordPress.org language packs can translate the plugin without bundled `.po` or `.mo` files.

## Limits

This is a stored-content link checker, not a rendered-site crawler. It does not inspect menus, widgets, theme templates, page-builder metadata, shortcode output, or rendered frontend pages.

HTTP checks use bounded GET response bodies, administrator-selected timeouts and redirect limits, WordPress unsafe-URL rejection, and manual same-site redirect handling.

Saved-scan uploads are limited to 20 MB, 100,000 content items, and 100,000 link-result rows. Uploaded data must use the exact supported format and belong to the current normalized site URL.

## CSV downloads

Content-coverage columns:

- Content
- Content URL
- Times Linked
- Content Items Linking Here
- Links From This Content
- Unique URLs Linked
- Link Text
- Self-Links
- Direct Links Here
- Redirected Links Here
- Outcome

Problem-URL columns:

- URL
- Problem
- Times Linked
- Content Items Affected
- Outcome
- HTTP Status
- Maximum Redirects
- Final URLs
- Warnings

Link-detail columns:

- Content Item
- Content Type
- Content URL
- Linked URL
- HTTP Status
- Redirects
- Final URL
- Warning
- Link Text
- Outcome

Comparison columns include the outcome, change, URL, changed fields, and explicit saved-scan/latest-scan values for each technical field.

All downloads protect spreadsheet cells that could otherwise be interpreted as formulas.

## Result labels

- OK
- Warning
- Blocked
- Error
- Needs review

Labels are intentionally conservative. The plugin reports link results; it does not guess SEO impact.

## Development

Run the fast syntax and behavioral checks:

```bash
php -l indexlane-redirect-internal-link-auditor.php
php -l includes/trait-admin.php
php -l includes/trait-scan.php
php -l includes/trait-reports.php
php -l includes/trait-baselines.php
php tests/behavioral.php
WP_CLI_BIN=/path/to/wp ./scripts/check-i18n.sh /tmp/indexlane-redirect-internal-link-auditor.pot
```

The translation check audits literal gettext calls and translator comments, then generates and validates a local POT without bundling translations. The CI workflow also installs WordPress, activates the plugin, runs the WordPress-loaded integration suite, and exercises the authenticated AJAX lifecycle, saved-scan save/upload/download/delete flow, exact-scope fix checks, comparison downloads, coverage filters, and detail views over HTTP.

Build the production ZIP for WordPress.org submission:

```bash
./scripts/build-wordpress-org-zip.sh
```

Release history is maintained in [CHANGELOG.md](CHANGELOG.md).
