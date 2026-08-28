# IndexLane Redirect & Internal Link Auditor

Find broken, redirected, old-domain, and staging-domain links in the stored content of any public WordPress post type.

Version 0.3 runs complete, resumable scan sessions from wp-admin. It produces a destination-impact view and exact occurrence evidence without editing content, following external redirect targets, or calling an IndexLane service.

Project page: [indexlane.dev/plugins/redirect-internal-link-auditor](https://indexlane.dev/plugins/redirect-internal-link-auditor)

## Complete scan sessions

- Choose all published content or a numeric limit of the newest content.
- Select any registered public post type.
- Process work through authenticated WordPress AJAX in small browser-driven batches.
- See content processed, links extracted, unique destinations checked, actual HTTP requests, and actionable issue occurrences.
- Pause, continue, cancel, or reload and resume one per-administrator session.
- Deduplicate requests for the same destination across the entire session while retaining every source occurrence.
- Stop at an explicit 250-request session allowance and continue with another 250 requests when needed.
- Export destination-impact and detailed-row CSVs only from exact completed-session evidence.

Each AJAX batch processes at most five content items and makes at most five actual outbound HTTP requests. Redirect chains persist between batches, so an allowance boundary never turns partial redirect evidence into a result row.

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

## Destination impact

One row is derived for each broken/error or redirected destination. The view includes occurrences, distinct affected content items, result severity, HTTP status evidence, maximum redirect count, observed final URLs, and warning evidence. It never makes additional requests.

Destination grouping normalizes scheme and host case, fragments, and default ports. Paths, query strings, schemes, non-default ports, and trailing slashes remain distinct because they can return different evidence.

## Data handling

One active or completed scan session per administrator is stored in a WordPress transient. Its sliding expiry is 24 hours, so abandoned sessions are cleaned up automatically by WordPress and completed evidence remains available briefly for export.

The plugin creates no custom table, cron job, account, telemetry, frontend tracking, or content mutation.

All plugin-owned administrator, status, warning, result, JavaScript, and CSV-header strings use the `indexlane-redirect-internal-link-auditor` text domain. WordPress.org language packs can translate the plugin without bundled `.po` or `.mo` files.

## Limits

This is a stored-content link checker, not a rendered-site crawler. It does not inspect menus, widgets, theme templates, page-builder metadata, shortcode output, or rendered frontend pages.

HTTP checks use bounded GET response bodies, administrator-selected timeouts and redirect limits, WordPress unsafe-URL rejection, and manual same-site redirect handling.

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

## Development

Run the fast syntax and behavioral checks:

```bash
php -l indexlane-redirect-internal-link-auditor.php
php tests/behavioral.php
WP_CLI_BIN=/path/to/wp ./scripts/check-i18n.sh /tmp/indexlane-redirect-internal-link-auditor.pot
```

The translation check audits literal gettext calls and translator comments, then generates and validates a local POT without bundling translations. The CI workflow also installs WordPress, activates the plugin, runs the WordPress-loaded integration suite, and exercises the authenticated AJAX lifecycle and both CSV downloads over HTTP.

Build the production ZIP for WordPress.org submission:

```bash
./scripts/build-wordpress-org-zip.sh
```

Release history is maintained in [CHANGELOG.md](CHANGELOG.md).
