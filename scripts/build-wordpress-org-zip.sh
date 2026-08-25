#!/usr/bin/env bash

set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repository_root="$(cd "${script_dir}/.." && pwd)"
plugin_slug="wpfixpath-redirect-internal-link-auditor"
plugin_file="${repository_root}/${plugin_slug}.php"
output_dir="${repository_root}/dist"
version="$(sed -nE 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([^[:space:]]+).*$/\1/p' "${plugin_file}")"

if [[ ! "${version}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	printf 'Could not read a semantic version from %s.\n' "${plugin_file}" >&2
	exit 1
fi

temporary_root="$(mktemp -d "${TMPDIR:-/tmp}/wpfixpath-wordpress-org.XXXXXX")"
package_root="${temporary_root}/${plugin_slug}"
archive_path="${output_dir}/${plugin_slug}-${version}.zip"

cleanup() {
	rm -rf "${temporary_root}"
}
trap cleanup EXIT

mkdir -p "${package_root}" "${output_dir}"
cp "${plugin_file}" "${repository_root}/readme.txt" "${package_root}/"

(
	cd "${temporary_root}"
	zip -q -r "${archive_path}" "${plugin_slug}"
)

printf '%s\n' "${archive_path}"
