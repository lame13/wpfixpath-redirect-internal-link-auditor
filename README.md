# WPFixPath Redirect & Internal Link Auditor

A small free WordPress plugin for finding broken, redirected, or suspicious internal links inside post, page, and product content.

## What it does

Find internal content links that return 404/410, redirect through 301/302, or still point to old/staging domains.

The plugin adds an admin-only screen at:

```text
Tools -> Redirect & Internal Link Auditor
```

From there, a site administrator can:

- scan published posts, pages, and WooCommerce products when available
- extract internal links from post content
- check same-site HTTP status codes
- flag 404/410 responses
- flag 301/302 redirects and redirect chains
- flag links to old domains supplied by the administrator
- flag common staging and development-domain links
- view source post/page evidence
- export a CSV report

## Data handling

This tool scans selected WordPress content and checks same-site link targets on demand. Results are generated for the current run and can be exported as CSV.

No telemetry. No premium tier. No scan data is sent to WPFixPath, IndexLane, or any third party.

## Limits

This is a quick diagnostic helper, not a replacement for Google Search Console, Screaming Frog, Sitebulb, server logs, or a full technical SEO audit.

Version 0.1 is read-only. It does not auto-fix links, bulk edit content, schedule scans, create database tables, add frontend badges, or display upgrade prompts.

Version 0.1 scans links found in WordPress post, page, and product content. It does not crawl menus, widgets, theme templates, page-builder metadata, shortcode output, or rendered frontend pages.

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

Labels are conservative. The plugin reports evidence; it does not claim ranking impact.

## Repository contents

```text
wpfixpath-redirect-internal-link-auditor.php
readme.txt
README.md
assets/
  screenshot-1.png
  screenshot-2.png
  demo.gif
docs/
  sample-report.csv
  changelog.md
```

## Development

Run a syntax check before packaging:

```bash
php -l wpfixpath-redirect-internal-link-auditor.php
```

For a manual WordPress check, copy or symlink this folder into `wp-content/plugins/`, activate the plugin, then open `Tools -> Redirect & Internal Link Auditor`.
