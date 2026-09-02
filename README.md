# IndexLane Redirect & Internal Link Auditor

Audit redirects, broken links, and source-aware content link coverage across stored WordPress link surfaces.

The current 0.6 development build can inspect ordinary post content, classic menus, Navigation entities, synced patterns, block templates, template parts, and assigned block widgets. It remains read-only, never follows external redirect targets, and does not call an IndexLane service.

Project page: [indexlane.dev/plugins/redirect-internal-link-auditor](https://indexlane.dev/plugins/redirect-internal-link-auditor)

## WordPress-native link sources

Every source adapter can be selected independently:

- stored content from any selected public post type;
- every classic menu and its stored menu items;
- Navigation block entities;
- synced patterns and reusable blocks;
- effective block templates and template parts available to the Site Editor;
- block widgets currently assigned to widget areas.

Each occurrence retains the stable source identity, source surface, contextual or shared/global scope, edit URL, source URL where one exists, link text, and the full HTTP and redirect result. Shared structures are checked once where they are maintained, so a result can say that a broken URL exists once in a Footer template part instead of implying that hundreds of rendered pages each need editing.

The built-in adapters inspect stored structures only. They do not render blocks, execute shortcodes, search arbitrary metadata, or crawl frontend output.

## Complete scan sessions

- Choose all published content or a numeric limit of the newest content.
- Select any registered public post type when Content is enabled.
- Process work through authenticated WordPress AJAX in small browser-driven batches.
- See stored sources checked, links found, unique URLs checked, and links needing attention.
- Pause, continue, cancel, or reload and resume one per-administrator session.
- Deduplicate requests for the same URL across every selected source while retaining each occurrence.
- Stop at an explicit 250-request limit and increase that limit by 250 when needed.
- Download content-coverage, problem-URL, and link-detail CSVs only from exact completed scan results.

Each AJAX batch processes at most five stored sources and makes at most five actual outbound HTTP requests. Redirect chains persist between batches, so a request-limit boundary never creates a partial result row.

## Saved scans and fix checks

- Save one explicitly selected completed scan for comparison for the current administrator.
- Download or upload portable, versioned JSON scan data with strict format, file-size, completeness, counter, and site-URL validation.
- Import legacy schema-1 content-only saved scans without widening their scope.
- Rerun the saved source providers, post types, content scope, old domains, timeout, and redirect limit exactly.
- Bind each fix check to the saved scan revision used when it started.
- Classify URL issues as new, changed, resolved, or still present.
- Show saved-scan and latest-scan status chains, redirect counts, final URLs, outcomes, times linked, and editable sources affected.
- Download the completed comparison as CSV without making additional HTTP requests.
- Delete the saved scan explicitly without deleting the current temporary scan session.

The comparison is derived entirely from retained results. It never rescans during page rendering or download, and it refuses to compare when the saved scan or a required source provider changed after the fix check started.

## What it checks

- same-site links found in the selected stored sources;
- 404 and 410 targets;
- 301, 302, 303, 307, and 308 redirects;
- redirect chains, loops, and configured redirect limits;
- same-site redirects that leave the site, without fetching the external target;
- links pointing to old domains entered by the administrator;
- common staging or development-domain links;
- exact source, source surface, scope, edit URL, link text, status chain, redirect count, final URL, warning, and outcome.

Unrelated external links are skipped. Old, staging, and development-domain links are reported but never fetched.

## Content link coverage

The completed coverage report contains one row per published content item included through the Content source. Each row includes:

- content title and URL;
- contextual incoming links;
- navigation/shared incoming links;
- total distinct editable sources linking to the target;
- outgoing internal links and distinct linked URLs from that content;
- link-text variants pointing to the content;
- self-link count;
- direct and redirected incoming-link counts;
- a conservative zero-, one-, or multiple-source status.

Open a content item's link details to review every exact source, source surface, contextual/shared scope, edit link, anchor, linked URL, status chain, and final URL. Filter the report to content with zero or one editable source, or download the complete content-coverage CSV.

When a selected source links through a redirect to a published WordPress URL, coverage counts the occurrence toward that final content item and retains the redirect results. The coverage report makes no additional HTTP requests.

“No incoming links detected in selected sources” is deliberately limited to the stored adapters chosen for that scan. It is not a claim about shortcode output, arbitrary metadata, page-builder storage, or rendered frontend pages.

## Problem URLs

One row is derived for each broken/error or redirected URL. The primary view shows the URL, outcome, times linked, and exact editable sources affected. A single-source result links directly to that source and identifies its surface and scope; multi-source results expose the full list. HTTP status, redirect count, final URLs, and warnings remain available under technical details. The report never makes additional requests.

URL grouping normalizes scheme and host case, fragments, and default ports. Paths, query strings, schemes, non-default ports, and trailing slashes remain distinct because they can return different results.

## Source-provider API

Plugins can register reliable stored-source adapters through `indexlane_rila_source_providers`:

```php
add_filter(
	'indexlane_rila_source_providers',
	static function ( array $providers ): array {
		$providers['my_source'] = array(
			'label'             => __( 'My stored sources', 'my-plugin' ),
			'description'       => __( 'Links stored by My Plugin.', 'my-plugin' ),
			'context'           => 'shared',
			'default'           => false,
			'snapshot_callback' => 'my_plugin_snapshot_sources',
			'next_callback'     => 'my_plugin_next_source',
		);

		return $providers;
	}
);
```

The provider ID must be a stable lowercase identifier containing letters, numbers, underscores, or hyphens. The snapshot callback receives sanitized scan settings and returns an integer `total_items` plus a serializable array `cursor`. The next callback receives that cursor and the settings, then returns:

```php
array(
	'source' => array(
		'key'      => 'my_source:stable-id',
		'id'       => 123,
		'title'    => 'Footer links',
		'type'     => 'My shared footer',
		'context'  => 'shared',
		'url'      => '',
		'edit_url' => admin_url( 'admin.php?page=my-plugin' ),
		'base_url' => home_url( '/' ),
		'content'  => '<a href="/contact/">Contact</a>',
	),
	'cursor' => array( 'offset' => 1 ),
	'done'   => true,
);
```

`source` may be `null` when a snapshotted record disappeared. A source must have a globally stable `key` and either stored `content` or an exact `links` list containing `href` and `anchor` values. `context` is `contextual` or `shared`. A contextual provider may also supply `content_item` (`id`, `title`, `type`, `url`, and `edit_url`) when its records should become coverage targets. Callbacks should advance deterministically, keep cursors serializable, and inspect stored data without rendering or executing user content.

If a provider used by a paused scan disappears or returns an invalid contract, the scan stops with a recoverable message instead of silently producing incomplete evidence. A saved fix check likewise refuses to run if its provider is no longer registered.

## Data handling

One active or completed scan session per administrator is stored in a WordPress transient. Its sliding expiry is 24 hours, so abandoned sessions are cleaned up automatically by WordPress and completed results remain available briefly for download.

One opt-in, site-specific saved scan per administrator is stored in WordPress user options until explicitly replaced or deleted. Portable JSON supports longer-term storage outside WordPress without creating an in-plugin scan-history system.

The plugin creates no custom table, cron job, account, telemetry, frontend tracking, or content mutation.

All plugin-owned administrator, status, warning, result, JavaScript, and CSV-header strings use the `indexlane-redirect-internal-link-auditor` text domain. WordPress.org language packs can translate the plugin without bundled `.po` or `.mo` files.

## Limits

This is a stored-source link checker, not a rendered-site crawler. It does not execute shortcodes, inspect arbitrary metadata or proprietary page-builder storage, render templates, or crawl frontend pages. Third-party storage requires a provider registered by the plugin that owns and understands it.

HTTP checks use bounded GET response bodies, administrator-selected timeouts and redirect limits, WordPress unsafe-URL rejection, and manual same-site redirect handling.

One scan can snapshot at most 100,000 stored sources. Saved-scan uploads are limited to 20 MB, 100,000 content items, and 100,000 link-result rows. Uploaded data must use an exact supported format and belong to the current normalized site URL.

## CSV downloads

Content-coverage columns:

- Content
- Content URL
- Times Linked
- Contextual Links Here
- Navigation/Shared Links Here
- Editable Sources Linking Here
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
- Editable Sources Affected
- Affected Source Details
- Source Edit URLs
- Outcome
- HTTP Status
- Maximum Redirects
- Final URLs
- Warnings

Link-detail columns:

- Source
- Source Surface
- Source Scope
- Source URL
- Edit URL
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
php -l includes/trait-source-providers.php
php -l includes/trait-scan.php
php -l includes/trait-reports.php
php -l includes/trait-baselines.php
php tests/behavioral.php
WP_CLI_BIN=/path/to/wp ./scripts/check-i18n.sh /tmp/indexlane-redirect-internal-link-auditor.pot
```

The translation check audits literal gettext calls and translator comments, then generates and validates a local POT without bundling translations. The CI workflow also installs WordPress, activates the plugin, runs the WordPress-loaded adapter/integration suite, and exercises the authenticated AJAX lifecycle, saved-scan save/upload/download/delete flow, exact-scope fix checks, comparison downloads, coverage filters, and detail views over HTTP.

Build the production ZIP for WordPress.org submission:

```bash
./scripts/build-wordpress-org-zip.sh
```

Release history is maintained in [CHANGELOG.md](CHANGELOG.md).
