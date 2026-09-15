# Publish 0.7.0

Run these commands from the prepared 0.7.0 working tree when you are ready to publish. GitHub hosts the source, walkthrough, and downloadable ZIP; WordPress.org receives the plugin package and directory screenshots through the existing SVN checkout.

Repository: `/Users/lame13/PhpstormProjects/wp-plugins/wpfixpath-redirect-internal-link-auditor`

SVN checkout: `/Users/lame13/PhpstormProjects/wp-plugins/wpfixpath-redirect-internal-link-auditor/dist/indexlane-wordpress-svn`

SVN URL: `https://plugins.svn.wordpress.org/indexlane-redirect-internal-link-auditor`

## 1. Push the prepared release

The local release commit and annotated `0.7.0` tag are already created. From `master`:

```sh
cd /Users/lame13/PhpstormProjects/wp-plugins/wpfixpath-redirect-internal-link-auditor
git push --atomic origin master 0.7.0
```

Wait for the existing PHP compatibility and WordPress activation workflows to pass for this commit before publishing the downloads. List the runs, then use each run's ID with `gh run watch RUN_ID --exit-status`:

```sh
gh run list \
  --repo lame13/wpfixpath-redirect-internal-link-auditor \
  --commit "$(git rev-parse '0.7.0^{commit}')" \
  --limit 10
```

## 2. Publish the GitHub release

The build script creates the installable WordPress ZIP with its required top-level plugin directory. It includes runtime files and `readme.txt`.

```sh
cd /Users/lame13/PhpstormProjects/wp-plugins/wpfixpath-redirect-internal-link-auditor
./scripts/build-wordpress-org-zip.sh
unzip -t dist/indexlane-redirect-internal-link-auditor-0.7.0.zip
gh release create 0.7.0 \
  dist/indexlane-redirect-internal-link-auditor-0.7.0.zip \
  --repo lame13/wpfixpath-redirect-internal-link-auditor \
  --verify-tag \
  --title "0.7.0 — Destination-intent auditing" \
  --notes-file docs/releases/0.7.0.md
```

The listing links to documentation at the Git tag `0.7.0`, so publish the Git source before the WordPress.org release.

## 3. Prepare the existing WordPress.org checkout (only if not already staged)

The release preparation in this workspace already stages `trunk`, `tags/0.7.0`, and all four screenshots. For that prepared checkout, skip to section 4. The block below is for rebuilding from a clean checkout.

This block checks the checkout URL and requires a clean SVN working copy. It updates the checkout, copies the exact ZIP contents into `trunk`, copies the four directory screenshots into SVN `assets`, and creates the new tag with `svn copy`. Existing directory icons are already versioned in SVN.

Run the block as one command. It stops on errors and removes its exact temporary extraction directory when it exits.

```sh
(
  set -eu
  release_repo=/Users/lame13/PhpstormProjects/wp-plugins/wpfixpath-redirect-internal-link-auditor
  release_svn="$release_repo/dist/indexlane-wordpress-svn"
  release_zip="$release_repo/dist/indexlane-redirect-internal-link-auditor-0.7.0.zip"
  release_slug=indexlane-redirect-internal-link-auditor

  test "$(svn info --show-item url "$release_svn")" = \
    "https://plugins.svn.wordpress.org/$release_slug"
  test -z "$(svn status "$release_svn")"
  svn update "$release_svn"
  test -z "$(svn status "$release_svn")"
  test ! -e "$release_svn/tags/0.7.0"

  release_tmp="$(mktemp -d "${TMPDIR:-/tmp}/indexlane-publish-0.7.0.XXXXXX")"
  trap 'rm -rf -- "$release_tmp"' EXIT
  unzip -q "$release_zip" -d "$release_tmp"
  test -f "$release_tmp/$release_slug/$release_slug.php"
  rg -q '^Stable tag: 0\.7\.0$' "$release_tmp/$release_slug/readme.txt"
  rg -q '^ \* Version: 0\.7\.0$' "$release_tmp/$release_slug/$release_slug.php"

  rsync -av "$release_tmp/$release_slug/" "$release_svn/trunk/"
  diff -qr "$release_tmp/$release_slug" "$release_svn/trunk"
  cp "$release_repo/assets/screenshot-1.png" "$release_svn/assets/screenshot-1.png"
  cp "$release_repo/assets/screenshot-2.png" "$release_svn/assets/screenshot-2.png"
  cp "$release_repo/assets/screenshot-3.png" "$release_svn/assets/screenshot-3.png"
  cp "$release_repo/assets/screenshot-4.png" "$release_svn/assets/screenshot-4.png"
  svn add --force "$release_svn/trunk" \
    "$release_svn/assets/screenshot-1.png" \
    "$release_svn/assets/screenshot-2.png" \
    "$release_svn/assets/screenshot-3.png" \
    "$release_svn/assets/screenshot-4.png"
  svn copy "$release_svn/trunk" "$release_svn/tags/0.7.0"
  svn status "$release_svn"
  svn diff "$release_svn"
)
```

If the clean-checkout or new-tag check fails, inspect `svn status` and `svn info` before continuing. If the package comparison reports additional files in `trunk`, review them before deciding whether any need `svn delete`; the block does not silently remove them.

## 4. Publish WordPress.org

Review the SVN status and diff printed above. The commit below publishes the prepared trunk, `0.7.0` tag, and the four screenshots together. SVN will prompt for credentials if needed.

```sh
cd /Users/lame13/PhpstormProjects/wp-plugins/wpfixpath-redirect-internal-link-auditor/dist/indexlane-wordpress-svn
svn commit trunk tags/0.7.0 \
  assets/screenshot-1.png assets/screenshot-2.png assets/screenshot-3.png assets/screenshot-4.png \
  --username wpfixpath \
  -m "Release 0.7.0 destination-intent auditing"
svn status
svn info tags/0.7.0
```

Allow WordPress.org to process the commit, then check the [plugin listing](https://wordpress.org/plugins/indexlane-redirect-internal-link-auditor/) for version 0.7.0 and the page-intent screenshots. The procedure follows WordPress.org's [SVN release guidance](https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/) and [directory asset layout](https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/).
