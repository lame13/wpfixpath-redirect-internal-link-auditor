#!/usr/bin/env bash

set -euo pipefail

base_url="${1:-http://127.0.0.1:8080}"
admin_user="${2:-admin}"
admin_password="${3:-password}"
temporary_root="$(mktemp -d "${TMPDIR:-/tmp}/indexlane-rila-ajax.XXXXXX")"
cookie_jar="${temporary_root}/cookies.txt"
page_html="${temporary_root}/auditor.html"

cleanup() {
	rm -rf "${temporary_root}"
}
trap cleanup EXIT

json_value() {
	php -r '
		$data = json_decode(file_get_contents($argv[1]), true);
		$path = explode(".", $argv[2]);
		foreach ($path as $part) {
			if (!is_array($data) || !array_key_exists($part, $data)) {
				fwrite(STDERR, "Missing JSON path: " . $argv[2] . "\n");
				exit(1);
			}
			$data = $data[$part];
		}
		if (is_bool($data)) {
			echo $data ? "true" : "false";
		} elseif ($data !== null) {
			echo $data;
		}
	' "$1" "$2"
}

assert_success() {
	if [[ "$(json_value "$1" success)" != "true" ]]; then
		php -r '$data=json_decode(file_get_contents($argv[1]), true); fwrite(STDERR, "AJAX failure: " . json_encode($data) . "\n");' "$1"
		exit 1
	fi
}

curl -fsS -c "${cookie_jar}" "${base_url}/wp-login.php" -o "${temporary_root}/login.html"
curl -fsS -L -b "${cookie_jar}" -c "${cookie_jar}" \
	--data-urlencode "log=${admin_user}" \
	--data-urlencode "pwd=${admin_password}" \
	--data-urlencode "redirect_to=${base_url}/wp-admin/" \
	--data "wp-submit=Log In" \
	--data "testcookie=1" \
	"${base_url}/wp-login.php" -o "${temporary_root}/dashboard.html"

curl -fsS -b "${cookie_jar}" "${base_url}/wp-admin/tools.php?page=indexlane-redirect-internal-link-auditor" -o "${page_html}"
if ! grep -Fq -- 'Redirect &amp; Internal Link Auditor' "${page_html}"; then
	printf 'The authenticated Tools page did not render.\n' >&2
	exit 1
fi

nonce="$(php -r '
	$html = file_get_contents($argv[1]);
	if (!preg_match("/var IndexLaneRila = (\\{.*?\\});/s", $html, $matches)) {
		fwrite(STDERR, "Could not find localized scan configuration.\n");
		exit(1);
	}
	$config = json_decode($matches[1], true);
	if (!is_array($config) || empty($config["nonce"])) {
		fwrite(STDERR, "Could not read the scan nonce.\n");
		exit(1);
	}
	echo $config["nonce"];
' "${page_html}")"

invalid_nonce_response="${temporary_root}/invalid-nonce.json"
curl -sS -b "${cookie_jar}" \
	--data "action=indexlane_rila_start_scan" \
	--data "nonce=invalid" \
	"${base_url}/wp-admin/admin-ajax.php" -o "${invalid_nonce_response}"
if [[ "$(json_value "${invalid_nonce_response}" success)" != "false" ]]; then
	printf 'An invalid AJAX nonce was accepted.\n' >&2
	exit 1
fi

empty_sources_response="${temporary_root}/empty-sources.json"
curl -sS -b "${cookie_jar}" \
	--data "action=indexlane_rila_start_scan" \
	--data-urlencode "nonce=${nonce}" \
	--data "source_types_present=1" \
	"${base_url}/wp-admin/admin-ajax.php" -o "${empty_sources_response}"
if [[ "$(json_value "${empty_sources_response}" success)" != "false" ]]; then
	printf 'An empty stored-source selection was accepted.\n' >&2
	exit 1
fi

response="${temporary_root}/start.json"
curl -fsS -b "${cookie_jar}" \
	--data "action=indexlane_rila_start_scan" \
	--data-urlencode "nonce=${nonce}" \
	--data "source_types_present=1" \
	--data "source_types[]=content" \
	--data "post_types[]=indexlane_e2e" \
	--data "content_scope=all" \
	--data "max_posts=1" \
	--data-urlencode "old_domains=legacy.example" \
	--data "timeout=2" \
	--data "max_redirects=5" \
	"${base_url}/wp-admin/admin-ajax.php" -o "${response}"
assert_success "${response}"
session_id="$(json_value "${response}" data.session.id)"

paused_response="${temporary_root}/paused.json"
curl -fsS -b "${cookie_jar}" \
	--data "action=indexlane_rila_control_scan" \
	--data-urlencode "nonce=${nonce}" \
	--data-urlencode "session_id=${session_id}" \
	--data "command=pause" \
	"${base_url}/wp-admin/admin-ajax.php" -o "${paused_response}"
assert_success "${paused_response}"
[[ "$(json_value "${paused_response}" data.session.status)" == "paused" ]]

curl -fsS -b "${cookie_jar}" "${base_url}/wp-admin/tools.php?page=indexlane-redirect-internal-link-auditor" -o "${page_html}"
if ! grep -Fq -- '"status":"paused"' "${page_html}"; then
	printf 'The paused session was not restored after page reload.\n' >&2
	exit 1
fi

resume_response="${temporary_root}/resume.json"
curl -fsS -b "${cookie_jar}" \
	--data "action=indexlane_rila_control_scan" \
	--data-urlencode "nonce=${nonce}" \
	--data-urlencode "session_id=${session_id}" \
	--data "command=resume" \
	"${base_url}/wp-admin/admin-ajax.php" -o "${resume_response}"
assert_success "${resume_response}"

status="running"
previous_requests=0
saw_limit=false
for batch_number in $(seq 1 100); do
	batch_response="${temporary_root}/batch-${batch_number}.json"
	curl -fsS -b "${cookie_jar}" \
		--data "action=indexlane_rila_run_batch" \
		--data-urlencode "nonce=${nonce}" \
		--data-urlencode "session_id=${session_id}" \
		"${base_url}/wp-admin/admin-ajax.php" -o "${batch_response}"
	assert_success "${batch_response}"
	status="$(json_value "${batch_response}" data.session.status)"
	requests="$(json_value "${batch_response}" data.session.stats.http_requests)"
	if (( requests - previous_requests > 5 )); then
		printf 'AJAX batch %d exceeded the five-request hard limit.\n' "${batch_number}" >&2
		exit 1
	fi
	previous_requests="${requests}"

	if [[ "${status}" == "limit_reached" ]]; then
		saw_limit=true
		extend_response="${temporary_root}/extend.json"
		curl -fsS -b "${cookie_jar}" \
			--data "action=indexlane_rila_control_scan" \
			--data-urlencode "nonce=${nonce}" \
			--data-urlencode "session_id=${session_id}" \
			--data "command=extend" \
			"${base_url}/wp-admin/admin-ajax.php" -o "${extend_response}"
		assert_success "${extend_response}"
		[[ "$(json_value "${extend_response}" data.session.request_limit)" == "500" ]]
		status="running"
	fi

	if [[ "${status}" == "complete" ]]; then
		final_response="${batch_response}"
		break
	fi
done

if [[ "${status}" != "complete" || "${saw_limit}" != "true" ]]; then
	printf 'The AJAX scan did not complete through its allowance continuation.\n' >&2
	exit 1
fi

[[ "$(json_value "${final_response}" data.session.stats.content_items_processed)" == "40" ]]
[[ "$(json_value "${final_response}" data.session.stats.sources_processed)" == "40" ]]
[[ "$(json_value "${final_response}" data.session.stats.links_extracted)" == "283" ]]
[[ "$(json_value "${final_response}" data.session.stats.links_audited)" == "282" ]]
[[ "$(json_value "${final_response}" data.session.stats.skipped_external)" == "1" ]]
[[ "$(json_value "${final_response}" data.session.stats.unique_destinations_checked)" == "222" ]]
[[ "$(json_value "${final_response}" data.session.stats.http_requests)" == "302" ]]
[[ "$(json_value "${final_response}" data.session.stats.actionable_issues)" == "122" ]]

curl -fsS -b "${cookie_jar}" "${base_url}/wp-admin/tools.php?page=indexlane-redirect-internal-link-auditor" -o "${page_html}"
grep -Fq -- 'Content link coverage' "${page_html}"
grep -Fq -- 'No incoming links detected in selected sources.' "${page_html}"
grep -Fq -- 'Showing 40 of 40 content items.' "${page_html}"

filtered_page="${temporary_root}/coverage-filtered.html"
curl -fsS -b "${cookie_jar}" "${base_url}/wp-admin/tools.php?page=indexlane-redirect-internal-link-auditor&coverage_filter=attention" -o "${filtered_page}"
grep -Fq -- 'No links or links from one place' "${filtered_page}"
grep -Fq -- 'Showing 30 of 40 content items.' "${filtered_page}"

target_detail_url="$(php -r '
	$html = file_get_contents($argv[1]);
	$dom = new DOMDocument();
	libxml_use_internal_errors(true);
	$dom->loadHTML($html);
	libxml_clear_errors();
	$xpath = new DOMXPath($dom);
	foreach ($xpath->query("//tr") as $row) {
		if (strpos($row->textContent, "Help center article 1") === false) {
			continue;
		}
		foreach ($xpath->query(".//a[contains(@href, \"coverage_target=\")]", $row) as $link) {
			echo html_entity_decode($link->getAttribute("href"), ENT_QUOTES | ENT_HTML5);
			exit;
		}
	}
	fwrite(STDERR, "Could not find the target-detail link.\n");
	exit(1);
' "${page_html}")"
target_detail_page="${temporary_root}/coverage-target.html"
curl -fsS -b "${cookie_jar}" "${target_detail_url}" -o "${target_detail_page}"
grep -Fq -- 'Link details for: Help center article 1' "${target_detail_page}"
grep -Fq -- 'Redirected' "${target_detail_page}"
grep -Fq -- 'Earlier article 1' "${target_detail_page}"

details_csv="${temporary_root}/details.csv"
details_headers="${temporary_root}/details.headers"
curl -fsS -b "${cookie_jar}" -D "${details_headers}" \
	--data-urlencode "indexlane_rila_nonce=${nonce}" \
	--data-urlencode "session_id=${session_id}" \
	--data "indexlane_rila_action=export_details" \
	"${base_url}/wp-admin/tools.php?page=indexlane-redirect-internal-link-auditor" -o "${details_csv}"
grep -Fqi -- 'content-disposition: attachment; filename=indexlane-redirect-internal-link-auditor-details-' "${details_headers}"
[[ "$(wc -l < "${details_csv}" | tr -d ' ')" == "283" ]]
grep -Fq -- 'Source,"Source Surface","Source Scope","Source URL","Edit URL"' "${details_csv}"

impact_csv="${temporary_root}/impact.csv"
curl -fsS -b "${cookie_jar}" \
	--data-urlencode "indexlane_rila_nonce=${nonce}" \
	--data-urlencode "session_id=${session_id}" \
	--data "indexlane_rila_action=export_impact" \
	"${base_url}/wp-admin/tools.php?page=indexlane-redirect-internal-link-auditor" -o "${impact_csv}"
[[ "$(wc -l < "${impact_csv}" | tr -d ' ')" == "122" ]]
grep -Fq -- 'URL,Problem,"Times Linked","Editable Sources Affected","Affected Source Details","Source Edit URLs"' "${impact_csv}"

coverage_csv="${temporary_root}/coverage.csv"
coverage_headers="${temporary_root}/coverage.headers"
curl -fsS -b "${cookie_jar}" -D "${coverage_headers}" \
	--data-urlencode "indexlane_rila_nonce=${nonce}" \
	--data-urlencode "session_id=${session_id}" \
	--data "indexlane_rila_action=export_coverage" \
	"${base_url}/wp-admin/tools.php?page=indexlane-redirect-internal-link-auditor" -o "${coverage_csv}"
grep -Fqi -- 'content-disposition: attachment; filename=indexlane-redirect-internal-link-auditor-target-coverage-' "${coverage_headers}"
[[ "$(wc -l < "${coverage_csv}" | tr -d ' ')" == "41" ]]
grep -Fq -- 'Content,"Content URL","Times Linked"' "${coverage_csv}"

saved_baseline_page="${temporary_root}/baseline-saved.html"
curl -fsS -L -b "${cookie_jar}" -c "${cookie_jar}" \
	--data-urlencode "indexlane_rila_nonce=${nonce}" \
	--data-urlencode "session_id=${session_id}" \
	--data "indexlane_rila_action=save_baseline" \
	"${base_url}/wp-admin/tools.php?page=indexlane-redirect-internal-link-auditor" -o "${saved_baseline_page}"
grep -Fq -- 'Saved scan ready' "${saved_baseline_page}"
grep -Fq -- 'These results are saved for comparison.' "${saved_baseline_page}"

baseline_json="${temporary_root}/baseline.json"
baseline_headers="${temporary_root}/baseline.headers"
curl -fsS -b "${cookie_jar}" -D "${baseline_headers}" \
	--data-urlencode "indexlane_rila_nonce=${nonce}" \
	--data "indexlane_rila_action=export_baseline_json" \
	"${base_url}/wp-admin/tools.php?page=indexlane-redirect-internal-link-auditor" -o "${baseline_json}"
grep -Fqi -- 'content-type: application/json' "${baseline_headers}"
grep -Fqi -- 'content-disposition: attachment; filename=indexlane-redirect-internal-link-auditor-saved-scan-' "${baseline_headers}"
php -r '
	$data = json_decode(file_get_contents($argv[1]), true);
	if (!is_array($data) || $data["format"] !== "indexlane-rila-baseline" || $data["schema_version"] !== 2 || $data["plugin_version"] !== "0.6.1") {
		fwrite(STDERR, "Exported baseline metadata is invalid.\n");
		exit(1);
	}
	if ($data["site_url"] !== $argv[2] || $data["settings"]["source_types"] !== array("content") || $data["scope"]["content_scope"] !== "all" || $data["scope"]["total_sources"] !== 40 || $data["scope"]["content_items"] !== 40) {
		fwrite(STDERR, "Exported baseline scope or site ownership is invalid.\n");
		exit(1);
	}
	if ($data["completion"]["complete"] !== true || $data["completion"]["request_limit"] !== 500 || $data["completion"]["request_allowance_extensions"] !== 1) {
		fwrite(STDERR, "Exported baseline completion evidence is invalid.\n");
		exit(1);
	}
' "${baseline_json}" "${base_url}"

verification_start="${temporary_root}/verification-start.json"
curl -fsS -b "${cookie_jar}" \
	--data "action=indexlane_rila_start_scan" \
	--data-urlencode "nonce=${nonce}" \
	--data "verification=1" \
	"${base_url}/wp-admin/admin-ajax.php" -o "${verification_start}"
assert_success "${verification_start}"
[[ "$(json_value "${verification_start}" data.session.scan_mode)" == "verification" ]]
verification_session_id="$(json_value "${verification_start}" data.session.id)"

verification_status="running"
verification_previous_requests=0
verification_saw_limit=false
for batch_number in $(seq 1 100); do
	verification_batch_response="${temporary_root}/verification-batch-${batch_number}.json"
	curl -fsS -b "${cookie_jar}" \
		--data "action=indexlane_rila_run_batch" \
		--data-urlencode "nonce=${nonce}" \
		--data-urlencode "session_id=${verification_session_id}" \
		"${base_url}/wp-admin/admin-ajax.php" -o "${verification_batch_response}"
	assert_success "${verification_batch_response}"
	verification_status="$(json_value "${verification_batch_response}" data.session.status)"
	verification_requests="$(json_value "${verification_batch_response}" data.session.stats.http_requests)"
	if (( verification_requests - verification_previous_requests > 5 )); then
		printf 'Verification AJAX batch %d exceeded the five-request hard limit.\n' "${batch_number}" >&2
		exit 1
	fi
	verification_previous_requests="${verification_requests}"

	if [[ "${verification_status}" == "limit_reached" ]]; then
		verification_saw_limit=true
		verification_extend_response="${temporary_root}/verification-extend.json"
		curl -fsS -b "${cookie_jar}" \
			--data "action=indexlane_rila_control_scan" \
			--data-urlencode "nonce=${nonce}" \
			--data-urlencode "session_id=${verification_session_id}" \
			--data "command=extend" \
			"${base_url}/wp-admin/admin-ajax.php" -o "${verification_extend_response}"
		assert_success "${verification_extend_response}"
		verification_status="running"
	fi

	if [[ "${verification_status}" == "complete" ]]; then
		break
	fi
done

if [[ "${verification_status}" != "complete" || "${verification_saw_limit}" != "true" ]]; then
	printf 'The exact-scope verification scan did not complete through its allowance continuation.\n' >&2
	exit 1
fi

verification_page="${temporary_root}/verification.html"
curl -fsS -b "${cookie_jar}" "${base_url}/wp-admin/tools.php?page=indexlane-redirect-internal-link-auditor" -o "${verification_page}"
grep -Fq -- 'What changed since the saved scan' "${verification_page}"
grep -Fq -- 'New issues' "${verification_page}"
grep -Fq -- 'Changed issues' "${verification_page}"
grep -Fq -- 'Resolved' "${verification_page}"
grep -Fq -- 'Still present' "${verification_page}"
grep -Fq -- '>122</strong><span>Still present<' "${verification_page}"

comparison_csv="${temporary_root}/comparison.csv"
comparison_headers="${temporary_root}/comparison.headers"
curl -fsS -b "${cookie_jar}" -D "${comparison_headers}" \
	--data-urlencode "indexlane_rila_nonce=${nonce}" \
	--data-urlencode "session_id=${verification_session_id}" \
	--data "indexlane_rila_action=export_comparison" \
	"${base_url}/wp-admin/tools.php?page=indexlane-redirect-internal-link-auditor" -o "${comparison_csv}"
grep -Fqi -- 'content-disposition: attachment; filename=indexlane-redirect-internal-link-auditor-comparison-' "${comparison_headers}"
[[ "$(wc -l < "${comparison_csv}" | tr -d ' ')" == "123" ]]
php -r '
	$handle = fopen($argv[1], "r");
	$header = false === $handle ? false : fgetcsv($handle, 0, ",", "\"", "");
	$expected = array("Outcome", "Change", "URL", "Changed Fields", "Saved Scan HTTP Status Chain", "Latest Scan HTTP Status Chain");
	if (!is_array($header) || array_slice($header, 0, count($expected)) !== $expected) {
		fwrite(STDERR, "Comparison CSV headers are invalid.\n");
		exit(1);
	}
' "${comparison_csv}"

deleted_baseline_page="${temporary_root}/baseline-deleted.html"
curl -fsS -L -b "${cookie_jar}" -c "${cookie_jar}" \
	--data-urlencode "indexlane_rila_nonce=${nonce}" \
	--data "confirm_delete=1" \
	--data "indexlane_rila_action=delete_baseline" \
	"${base_url}/wp-admin/tools.php?page=indexlane-redirect-internal-link-auditor" -o "${deleted_baseline_page}"
grep -Fq -- 'No saved scan' "${deleted_baseline_page}"

imported_baseline_page="${temporary_root}/baseline-imported.html"
curl -fsS -L -b "${cookie_jar}" -c "${cookie_jar}" \
	--form-string "indexlane_rila_nonce=${nonce}" \
	--form "baseline_file=@${baseline_json};type=application/json" \
	--form-string "indexlane_rila_action=import_baseline" \
	"${base_url}/wp-admin/tools.php?page=indexlane-redirect-internal-link-auditor" -o "${imported_baseline_page}"
grep -Fq -- 'Saved scan ready' "${imported_baseline_page}"
grep -Fq -- 'saved-scan file was uploaded and is ready to use' "${imported_baseline_page}"

curl -fsS -L -b "${cookie_jar}" -c "${cookie_jar}" \
	--data-urlencode "indexlane_rila_nonce=${nonce}" \
	--data "confirm_delete=1" \
	--data "indexlane_rila_action=delete_baseline" \
	"${base_url}/wp-admin/tools.php?page=indexlane-redirect-internal-link-auditor" -o /dev/null

printf 'Authenticated WordPress AJAX end-to-end tests passed.\n'
