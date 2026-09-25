# Publish 1.0.0

The local release commit and annotated `1.0.0` tag are prepared. The SVN checkout has the release staged in `trunk` and `tags/1.0.0`. These commands publish them; preparation does not push or publish anything.

## Push Git

```sh
cd /Users/lame13/PhpstormProjects/wp-plugins/wpfixpath-redirect-internal-link-auditor
git show --stat 1.0.0
git push --atomic origin master 1.0.0
```

Check the PHP compatibility and WordPress activation workflows before publishing WordPress.org:

```sh
gh run list --repo lame13/wpfixpath-redirect-internal-link-auditor \
  --commit "$(git rev-parse '1.0.0^{commit}')" --limit 10
# For each relevant run from that list:
gh run watch RUN_ID --exit-status --repo lame13/wpfixpath-redirect-internal-link-auditor
```

## Publish WordPress.org from the prepared checkout

```sh
cd /Users/lame13/PhpstormProjects/wp-plugins/wpfixpath-redirect-internal-link-auditor/dist/indexlane-wordpress-svn
svn status
svn diff trunk
svn commit trunk tags/1.0.0 --username wpfixpath \
  -m "Release 1.0.0 link repairs and scheduled checks"
svn status
svn info tags/1.0.0
```

The commit publishes the runtime files and `readme.txt` together. Existing directory screenshots and icons are unchanged. Push Git first so the listing's links to the `1.0.0` documentation resolve.

After WordPress.org processes the commit, check the [plugin listing](https://wordpress.org/plugins/indexlane-redirect-internal-link-auditor/) for version 1.0.0 and the revised description. This follows the [WordPress.org SVN release instructions](https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/).

## Optional GitHub release with the ZIP

The prepared archive is `dist/indexlane-redirect-internal-link-auditor-1.0.0.zip`.

```sh
cd /Users/lame13/PhpstormProjects/wp-plugins/wpfixpath-redirect-internal-link-auditor
unzip -t dist/indexlane-redirect-internal-link-auditor-1.0.0.zip
gh release create 1.0.0 \
  dist/indexlane-redirect-internal-link-auditor-1.0.0.zip \
  --repo lame13/wpfixpath-redirect-internal-link-auditor \
  --verify-tag \
  --title "1.0.0 — Link repairs and scheduled checks" \
  --notes-file docs/releases/1.0.0.md
```

## Rebuild an unstaged SVN checkout

Skip this section for the checkout already prepared in this workspace. Use it only when recreating the release from a clean checkout. It extracts the tagged source, builds the package, compares all package files, and creates the SVN tag locally. It stops if the checkout is dirty or the tag already exists.

```sh
(
  set -eu
  release_repo=/Users/lame13/PhpstormProjects/wp-plugins/wpfixpath-redirect-internal-link-auditor
  release_svn="$release_repo/dist/indexlane-wordpress-svn"
  release_slug=indexlane-redirect-internal-link-auditor
  test "$(svn info --show-item url "$release_svn")" = \
    "https://plugins.svn.wordpress.org/$release_slug"
  test -z "$(svn status "$release_svn")"
  svn update "$release_svn"
  test -z "$(svn status "$release_svn")"
  test ! -e "$release_svn/tags/1.0.0"

  release_tmp="$(mktemp -d "${TMPDIR:-/tmp}/indexlane-publish-1.0.0.XXXXXX")"
  trap 'rm -rf -- "$release_tmp"' EXIT
  git -C "$release_repo" archive 1.0.0 | tar -x -C "$release_tmp"
  "$release_tmp/scripts/build-wordpress-org-zip.sh"
  unzip -q "$release_tmp/dist/$release_slug-1.0.0.zip" -d "$release_tmp/package"
  rg -q '^Stable tag: 1\.0\.0$' "$release_tmp/package/$release_slug/readme.txt"
  rg -q '^ \* Version: 1\.0\.0$' "$release_tmp/package/$release_slug/$release_slug.php"
  rsync -av "$release_tmp/package/$release_slug/" "$release_svn/trunk/"
  diff -qr "$release_tmp/package/$release_slug" "$release_svn/trunk"
  svn add --force "$release_svn/trunk"
  svn copy "$release_svn/trunk" "$release_svn/tags/1.0.0"
  svn status "$release_svn"
  svn diff "$release_svn/trunk"
)
```

If the comparison finds extra files in `trunk`, inspect them before deciding whether to remove them with `svn delete`. The block does not delete existing files automatically.
