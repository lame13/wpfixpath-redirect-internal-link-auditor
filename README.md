# IndexLane Redirect & Internal Link Auditor

Audit redirects, broken links, and content link coverage in the stored content of any public WordPress post type.

Version 0.5 adds opt-in baselines and exact-scope fix verification to the complete, resumable scan and content-link coverage workflow. It remains read-only, never follows external redirect targets, and does not call an IndexLane service.

Project page: [indexlane.dev/plugins/redirect-internal-link-auditor](https://indexlane.dev/plugins/redirect-internal-link-auditor)

## Complete scan sessions

- Choose all published content or a numeric limit of the newest content.
- Select any registered public post type.
- Process work through authenticated WordPress AJAX in small browser-driven batches.
- See content processed, links extracted, unique destinations checked, actual HTTP requests, and actionable issue occurrences.
- Pause, continue, cancel, or reload and resume one per-administrator session.
- Deduplicate requests for the same destination across the entire session while retaining every source occurrence.
- Stop at an explicit 250-request session allowance and continue with another 250 requests when needed.
- Export target-coverage, destination-impact, and detailed-row CSVs only from exact completed-session evidence.

Each AJAX batch processes at most five content items and makes at most five actual outbound HTTP requests. Redirect chains persist between batches, so an allowance boundary never turns partial redirect evidence into a result row.

## Baselines and fix verification

- Save one explicitly selected completed scan as a site-specific baseline for the current administrator.
- Export or import portable, versioned JSON evidence with strict schema, file-size, completeness, counter, and site-URL validation.
- Rerun the saved post types, content scope, old domains, timeout, and redirect limit exactly.
- Bind each verification session to the baseline ID and fingerprint used when it started.
- Classify destination issues as new, worsened or changed, resolved, or still present.
- Show baseline and verification status chains, redirect counts, final URLs, result severity, occurrence counts, and affected-content counts.
- Export the completed comparison as CSV without making additional HTTP requests.
- Delete the saved baseline explicitly without deleting the current temporary scan session.

Baseline comparison is derived entirely from retained evidence. It never rescans during page rendering or export, and it refuses to compare when the saved baseline changed after verification started.

## What it checks

- same-site links found in selected posts' `post_content`
- 404 and 410 targets
- 301, 302, 303, 307, and 308 redirects
- redirect chains, loops, and configured redirect limits
- same-site redirects that leave the site, without fetching the external target
- links pointing to old domains entered by the administrator
- common staging or development-domain links
- occurrence source, edit URL, anchor text, status chain, redirect count, final URL, warning, and result

Unrelated external links are skipped. Old, staging, and development-domain links are reported but never fetched.

## Content link coverage

The completed report contains one row per scanned published content item. Each row includes:

- target title and URL
- incoming link occurrences and distinct linking content items
- outgoing internal-link occurrences and distinct internal destinations
- anchor-text variants pointing to the target
- self-link count
- direct and redirected incoming-link counts
- a conservative zero-, one-, or multiple-source status

Open a target's details to review every saved source, anchor, linked URL, status chain, and final URL. Filter the report to content with zero or one detected linking source, or export the complete target-coverage CSV.

When a scanned link redirects to a published WordPress URL, coverage counts the occurrence toward that final content item and retains the redirect evidence. The coverage projection makes no additional HTTP requests.

“No incoming links detected in scanned content” describes only the selected items' stored `post_content`. It does not inspect menus, templates, widgets, shortcode output, or rendered page-builder content.

## Destination impact

One row is derived for each broken/error or redirected destination. The view includes occurrences, distinct affected content items, result severity, HTTP status evidence, maximum redirect count, observed final URLs, and warning evidence. It never makes additional requests.

Destination grouping normalizes scheme and host case, fragments, and default ports. Paths, query strings, schemes, non-default ports, and trailing slashes remain distinct because they can return different evidence.

## Data handling

One active or completed scan session per administrator is stored in a WordPress transient. Its sliding expiry is 24 hours, so abandoned sessions are cleaned up automatically by WordPress and completed evidence remains available briefly for export.

One opt-in, site-specific baseline per administrator is stored in WordPress user options until explicitly replaced or deleted. Portable JSON supports longer-term evidence outside WordPress without creating an in-plugin scan-history system.

The plugin creates no custom table, cron job, account, telemetry, frontend tracking, or content mutation.

All plugin-owned administrator, status, warning, result, JavaScript, and CSV-header strings use the `indexlane-redirect-internal-link-auditor` text domain. WordPress.org language packs can translate the plugin without bundled `.po` or `.mo` files.

## Limits

This is a stored-content link checker, not a rendered-site crawler. It does not inspect menus, widgets, theme templates, page-builder metadata, shortcode output, or rendered frontend pages.

HTTP checks use bounded GET response bodies, administrator-selected timeouts and redirect limits, WordPress unsafe-URL rejection, and manual same-site redirect handling.

Baseline imports are limited to 20 MiB, 100,000 content items, and 100,000 occurrence rows. Imported data must use the exact supported schema and belong to the current normalized site URL.

## CSV exports

Target-coverage columns:

- Target Title
- Target URL
- Incoming Link Occurrences
- Distinct Linking Content Items
- Outgoing Internal-Link Occurrences
- Distinct Internal Destinations
- Anchor-Text Variants
- Self-Link Count
- Direct Incoming Links
- Redirected Incoming Links
- Status

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

Verification-comparison columns include the category, direction, destination, changed fields, and explicit baseline/verification values for every required evidence field.

All exports protect spreadsheet cells that could otherwise be interpreted as formulas.

## Result labels

- OK
- Warning
- Blocked
- Error
- Needs review

Labels are intentionally conservative. The plugin reports link evidence; it does not guess SEO impact.

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

The translation check audits literal gettext calls and translator comments, then generates and validates a local POT without bundling translations. The CI workflow also installs WordPress, activates the plugin, runs the WordPress-loaded integration suite, and exercises the authenticated AJAX lifecycle, baseline save/import/export/delete flow, exact-scope verification, comparison export, coverage filters, and detail views over HTTP.

Build the production ZIP for WordPress.org submission:

```bash
./scripts/build-wordpress-org-zip.sh
```

Release history is maintained in [CHANGELOG.md](CHANGELOG.md).
