# Publish 0.6.1

Run these commands from the prepared 0.6.1 working tree when you are ready to publish. GitHub hosts the source, walkthrough, and downloadable ZIP; WordPress.org receives the plugin package and directory screenshots through the existing SVN checkout.

Repository: `/Users/lame13/PhpstormProjects/wp-plugins/wpfixpath-redirect-internal-link-auditor`

SVN checkout: `/Users/lame13/PhpstormProjects/wp-plugins/wpfixpath-redirect-internal-link-auditor/dist/indexlane-wordpress-svn`

SVN URL: `https://plugins.svn.wordpress.org/indexlane-redirect-internal-link-auditor`

## 1. Commit and push the release source

The staging list covers this release. Review the staged diff before committing. Run on the existing `master` branch, with the previous release at `0.6.0`.

```sh
cd /Users/lame13/PhpstormProjects/wp-plugins/wpfixpath-redirect-internal-link-auditor
git status --short --branch
git diff --check
git add -- \
  CHANGELOG.md README.md readme.txt \
  indexlane-redirect-internal-link-auditor.php \
  assets/screenshot-1.png assets/screenshot-2.png assets/screenshot-3.png \
  docs/quick-start.md docs/releases/0.6.1.md docs/publishing-0.6.1.md \
  tests/behavioral.php tests/wordpress-integration.php \
  tests/wordpress-ajax-e2e.sh tests/wordpress-screenshot-state.php \
  tests/wordpress-demo-setup.php tests/fixtures/demo-site.php
git diff --cached --stat
git diff --cached
git commit -m "Release 0.6.1 scan and fix walkthrough"
git tag -a 0.6.1 -m "Release 0.6.1"
git push --atomic origin master 0.6.1
```

Wait for the existing PHP compatibility and WordPress activation workflows to pass for this commit before publishing the downloads. List the runs, then use each run's ID with `gh run watch RUN_ID --exit-status`:

```sh
gh run list \
  --repo lame13/wpfixpath-redirect-internal-link-auditor \
  --commit "$(git rev-parse '0.6.1^{commit}')" \
  --limit 10
```

## 2. Publish the GitHub release

The build script creates the installable WordPress ZIP with its required top-level plugin directory. It includes runtime files and `readme.txt`.

```sh
cd /Users/lame13/PhpstormProjects/wp-plugins/wpfixpath-redirect-internal-link-auditor
./scripts/build-wordpress-org-zip.sh
unzip -t dist/indexlane-redirect-internal-link-auditor-0.6.1.zip
gh release create 0.6.1 \
  dist/indexlane-redirect-internal-link-auditor-0.6.1.zip \
  --repo lame13/wpfixpath-redirect-internal-link-auditor \
  --verify-tag \
  --title "0.6.1 — Scan, fix, and check again" \
  --notes-file docs/releases/0.6.1.md
```

The listing links to documentation at the Git tag `0.6.1`, so publish the Git source before the WordPress.org release.

## 3. Prepare the existing WordPress.org checkout

This block checks the checkout URL and requires a clean SVN working copy. It updates the checkout, copies the exact ZIP contents into `trunk`, copies the three directory screenshots into SVN `assets`, and creates the new tag with `svn copy`. Existing directory icons are already versioned in SVN.

Run the block as one command. It stops on errors and removes its exact temporary extraction directory when it exits.

```sh
(
  set -eu
  release_repo=/Users/lame13/PhpstormProjects/wp-plugins/wpfixpath-redirect-internal-link-auditor
  release_svn="$release_repo/dist/indexlane-wordpress-svn"
  release_zip="$release_repo/dist/indexlane-redirect-internal-link-auditor-0.6.1.zip"
  release_slug=indexlane-redirect-internal-link-auditor

  test "$(svn info --show-item url "$release_svn")" = \
    "https://plugins.svn.wordpress.org/$release_slug"
  test -z "$(svn status "$release_svn")"
  svn update "$release_svn"
  test -z "$(svn status "$release_svn")"
  test ! -e "$release_svn/tags/0.6.1"

  release_tmp="$(mktemp -d "${TMPDIR:-/tmp}/indexlane-publish-0.6.1.XXXXXX")"
  trap 'rm -rf -- "$release_tmp"' EXIT
  unzip -q "$release_zip" -d "$release_tmp"
  test -f "$release_tmp/$release_slug/$release_slug.php"
  rg -q '^Stable tag: 0\.6\.1$' "$release_tmp/$release_slug/readme.txt"
  rg -q '^ \* Version: 0\.6\.1$' "$release_tmp/$release_slug/$release_slug.php"

  rsync -av "$release_tmp/$release_slug/" "$release_svn/trunk/"
  diff -qr "$release_tmp/$release_slug" "$release_svn/trunk"
  cp "$release_repo/assets/screenshot-1.png" "$release_svn/assets/screenshot-1.png"
  cp "$release_repo/assets/screenshot-2.png" "$release_svn/assets/screenshot-2.png"
  cp "$release_repo/assets/screenshot-3.png" "$release_svn/assets/screenshot-3.png"
  svn add --force "$release_svn/trunk" \
    "$release_svn/assets/screenshot-1.png" \
    "$release_svn/assets/screenshot-2.png" \
    "$release_svn/assets/screenshot-3.png"
  svn copy "$release_svn/trunk" "$release_svn/tags/0.6.1"
  svn status "$release_svn"
  svn diff "$release_svn"
)
```

If the clean-checkout or new-tag check fails, inspect `svn status` and `svn info` before continuing. If the package comparison reports additional files in `trunk`, review them before deciding whether any need `svn delete`; the block does not silently remove them.

## 4. Publish WordPress.org

Review the SVN status and diff printed above. The commit below publishes the prepared trunk, `0.6.1` tag, and the three screenshots together. SVN will prompt for credentials if needed.

```sh
cd /Users/lame13/PhpstormProjects/wp-plugins/wpfixpath-redirect-internal-link-auditor/dist/indexlane-wordpress-svn
svn commit trunk tags/0.6.1 \
  assets/screenshot-1.png assets/screenshot-2.png assets/screenshot-3.png \
  --username wpfixpath \
  -m "Release 0.6.1 scan and fix walkthrough"
svn status
svn info tags/0.6.1
```

Allow WordPress.org to process the commit, then check the [plugin listing](https://wordpress.org/plugins/indexlane-redirect-internal-link-auditor/) for version 0.6.1 and the new screenshot order. The procedure follows WordPress.org's [SVN release guidance](https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/) and [directory asset layout](https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/).
