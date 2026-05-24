# WPFixPath Redirect & Internal Link Auditor

Find broken, redirected, old-domain, and staging-domain links inside WordPress post, page, and product content.

The plugin runs in wp-admin and builds a cleanup table: source page, linked URL, HTTP status, redirect count, final URL, warning, anchor text, and result label.

Useful after migrations, redesigns, domain changes, or old cleanup work where internal links quietly drift.

## What it checks

- internal links found in post, page, and WooCommerce product content
- 404 and 410 targets
- 301 / 302 redirects
- redirect chains
- links pointing to old domains you enter
- common staging or development-domain links
- source page and anchor text
- CSV export for cleanup

## Data handling

Scans run on demand from your WordPress admin.

The plugin checks selected WordPress content and same-site link targets, then shows the results in the current admin screen. You can export the results as CSV.

It does not create an account, call an IndexLane/WPFixPath service, or add frontend tracking.

## Limits

This is a content-link checker, not a crawler.

Version 0.1 scans links found in WordPress post, page, and product content. It does not crawl menus, widgets, theme templates, page-builder metadata, shortcode output, or rendered frontend pages.

It is read-only. It does not replace links, bulk edit content, schedule scans, create database tables, or add frontend badges.

## CSV columns

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

## Result labels

- OK
- Warning
- Blocked
- Error
- Needs review

Labels are intentionally conservative. The plugin reports link evidence; it does not guess SEO impact.

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
