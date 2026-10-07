# Publish 1.1.1

The local release commit, annotated `1.1.1` Git tag, and production ZIP are prepared. The original `1.1.0` Git commit, tag, package, and release notes are preserved. Nothing has been pushed or published.

The existing SVN checkout is already prepared: `trunk` and `tags/1.1.1` contain the fixed release, and `tags/1.1.0` preserves the original package. Both tags are scheduled for addition in the same SVN commit, with `1.1.1` as the stable version. Do not copy trunk over `tags/1.1.0`.

## Push Git

```sh
cd /Users/lame13/PhpstormProjects/wp-plugins/wpfixpath-redirect-internal-link-auditor
git show --stat 1.1.1
git push --atomic origin master 1.1.0 1.1.1
```

Check the PHP compatibility and WordPress activation workflows before publishing WordPress.org:

```sh
gh run list --repo lame13/wpfixpath-redirect-internal-link-auditor \
  --commit "$(git rev-parse '1.1.1^{commit}')" --limit 10
# For each relevant run from that list:
gh run watch RUN_ID --exit-status --repo lame13/wpfixpath-redirect-internal-link-auditor
```

## Publish the prepared WordPress.org checkout

```sh
cd /Users/lame13/PhpstormProjects/wp-plugins/wpfixpath-redirect-internal-link-auditor/dist/indexlane-wordpress-svn
svn status
svn diff trunk
svn commit trunk tags/1.1.0 tags/1.1.1 --username wpfixpath \
  -m "Release 1.1.1 batch repair fixes; preserve 1.1.0"
svn status
svn info tags/1.1.1
```

This publishes the runtime files and readme together using the [WordPress.org SVN release workflow](https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/). Push Git first so release documentation is available. After WordPress.org processes the commit, check the [plugin listing](https://wordpress.org/plugins/indexlane-redirect-internal-link-auditor/) for version 1.1.1.

## Optional GitHub release

```sh
cd /Users/lame13/PhpstormProjects/wp-plugins/wpfixpath-redirect-internal-link-auditor
unzip -t dist/indexlane-redirect-internal-link-auditor-1.1.1.zip
gh release create 1.1.1 \
  dist/indexlane-redirect-internal-link-auditor-1.1.1.zip \
  --repo lame13/wpfixpath-redirect-internal-link-auditor \
  --verify-tag \
  --title "1.1.1 — Batch repair fixes" \
  --notes-file docs/releases/1.1.1.md
```
