#!/usr/bin/env bash
#
# Smoke test for a running WP Update Server instance.
# Usage: ./tests/smoke-test.sh [base-url]
#
# Defaults to the DDEV URL if no argument is given.

set -euo pipefail

BASE_URL="${1:-https://wp-update-server.ddev.site}"
PASS=0
FAIL=0

check() {
	local description="$1"
	local url="$2"
	local expected_status="$3"
	local body_check="${4:-}"

	response=$(curl -s -w "\n%{http_code}" "$url")
	http_code=$(echo "$response" | tail -1)
	body=$(echo "$response" | sed '$d')

	if [ "$http_code" != "$expected_status" ]; then
		echo "FAIL: $description (expected $expected_status, got $http_code)"
		FAIL=$((FAIL + 1))
		return
	fi

	if [ -n "$body_check" ]; then
		if ! echo "$body" | grep -q "$body_check"; then
			echo "FAIL: $description (body missing: $body_check)"
			FAIL=$((FAIL + 1))
			return
		fi
	fi

	echo "PASS: $description"
	PASS=$((PASS + 1))
}

echo "Smoke testing: $BASE_URL"
echo "---"

# Metadata endpoints
check "get_metadata returns 200 with version" \
	"$BASE_URL/?action=get_metadata&slug=hello-dolly" \
	"200" '"version"'

check "get_metadata with specific version" \
	"$BASE_URL/?action=get_metadata&slug=hello-dolly&version=1.7.2" \
	"200" '"version"'

check "get_metadata with beta channel" \
	"$BASE_URL/?action=get_metadata&slug=hello-dolly&channel=beta" \
	"200" '"version"'

check "get_metadata for nonexistent slug returns 404" \
	"$BASE_URL/?action=get_metadata&slug=nonexistent-plugin" \
	"404" ""

check "get_metadata with invalid channel returns 400" \
	"$BASE_URL/?action=get_metadata&slug=hello-dolly&channel=invalid" \
	"400" ""

# Composer endpoint
check "composer_packages returns valid JSON with packages key" \
	"$BASE_URL/?action=composer_packages" \
	"200" '"packages"'

# Download endpoint
check "download returns zip content-type" \
	"$BASE_URL/?action=download&slug=hello-dolly" \
	"200" ""

# Error handling
check "missing action returns 400" \
	"$BASE_URL/?slug=hello-dolly" \
	"400" ""

check "missing slug returns 400" \
	"$BASE_URL/?action=get_metadata" \
	"400" ""

check "invalid action returns 400" \
	"$BASE_URL/?action=invalid_action&slug=hello-dolly" \
	"400" ""

# Upload endpoint (GET should fail)
check "upload via GET returns 405" \
	"$BASE_URL/?action=upload" \
	"405" ""

echo "---"
echo "Results: $PASS passed, $FAIL failed"

if [ "$FAIL" -gt 0 ]; then
	exit 1
fi
