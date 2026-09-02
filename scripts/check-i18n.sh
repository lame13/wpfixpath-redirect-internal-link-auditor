#!/usr/bin/env bash

set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repository_root="$(cd "${script_dir}/.." && pwd)"
plugin_slug="indexlane-redirect-internal-link-auditor"
wp_cli_bin="${WP_CLI_BIN:-wp}"
pot_path="${1:-}"
remove_pot=false

if [[ -z "${pot_path}" ]]; then
	pot_path="$(mktemp "${TMPDIR:-/tmp}/indexlane-rila-i18n.XXXXXX")"
	remove_pot=true
fi

cleanup() {
	if [[ "${remove_pot}" == true ]]; then
		rm -f "${pot_path}"
	fi
}
trap cleanup EXIT

php "${repository_root}/tests/i18n-audit.php"

"${wp_cli_bin}" i18n make-pot \
	"${repository_root}" \
	"${pot_path}" \
	--slug="${plugin_slug}" \
	--domain="${plugin_slug}" \
	--include="${plugin_slug}.php,includes/*.php,assets/admin.js" \
	--exclude="dist,tests,FUTURE-PLAN.md"

grep -Fq '"X-Domain: indexlane-redirect-internal-link-auditor\n"' "${pot_path}"
grep -Fq 'msgid "Increase request limit by %d"' "${pot_path}"
grep -Fq 'msgid "Where to check for links"' "${pot_path}"
grep -Fq 'msgid "Pausing after the current batch…"' "${pot_path}"
grep -Fq 'msgid "Content link coverage"' "${pot_path}"
grep -Fq 'msgid "No incoming links detected in selected sources."' "${pot_path}"
grep -Fq 'msgid "Times Linked"' "${pot_path}"
grep -Fq 'msgid "Affected Source Details"' "${pot_path}"
grep -Fq 'msgid "Save results and check fixes"' "${pot_path}"
grep -Fq 'msgid "Download comparison"' "${pot_path}"

if grep -Eq '^#: (dist|tests)/' "${pot_path}"; then
	printf 'POT extraction unexpectedly included non-release files.\n' >&2
	exit 1
fi

if command -v msgfmt >/dev/null 2>&1; then
	msgfmt --check-format -o /dev/null "${pot_path}"
fi

message_count="$(grep -c '^msgid ' "${pot_path}")"
printf 'Translation extraction passed (%d messages): %s\n' "$(( message_count - 1 ))" "${pot_path}"
