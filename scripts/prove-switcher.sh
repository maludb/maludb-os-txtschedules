#!/usr/bin/env bash
# Proves the application switcher (K31; app/switcher.php, shared/app-switcher.php, the header): the code, the layout, and in
# process against the REAL kernel on this host with this checkout's OS_APPLICATION_TOKEN (a read) — see scripts/prove-switcher.php.
# Run from a checkout whose config/.env names a scratch database (never the live one).
set -u
cd "$(dirname "$0")/.."
DB=$(grep -E '^DB_NAME=' config/.env | cut -d= -f2)
case "$DB" in *_dev*|*_prove*|*_picker|*_w[0-9]*|sp_dev*|ct_dev*) ;; *) echo "refusing $DB: only a scratch database" >&2; exit 2;; esac
pass=0; fail=0; ok() { pass=$((pass+1)); echo "ok   $1"; }; bad() { fail=$((fail+1)); echo "FAIL $1"; }; check(){ if eval "$2"; then ok "$1"; else bad "$1"; fi; }
php -l app/switcher.php >/dev/null && php -l app/views/shared/app-switcher.php >/dev/null && php -l app/views/layout.php >/dev/null && ok "lint" || bad "lint"
check "the bootstrap requires app/switcher.php" "grep -q \"require_once __DIR__ . '/switcher.php';\" app/bootstrap.php"
check "the layout renders the switcher and the old launcher icon is gone" "grep -q \"view('shared/app-switcher.php')\" app/views/layout.php && ! grep -q 'header-launcher-link' app/views/layout.php"
check "app-overrides.css has the APPLICATION SWITCHER section" "grep -q 'APPLICATION SWITCHER' html/assets/css/app-overrides.css"
out=$(php scripts/prove-switcher.php 2>&1); rc=$?
echo "$out" | grep -E '^(ok|FAIL)'
p2=$(echo "$out" | grep -c '^ok'); f2=$(echo "$out" | grep -c '^FAIL'); pass=$((pass+p2)); fail=$((fail+f2))
if [ $rc -ne 0 ] || [ $p2 -lt 10 ] || echo "$out" | grep -q 'Exception\|Fatal'; then bad "the in-process half stopped early (exit $rc, $p2 ok lines): $(echo "$out" | grep -m1 'Exception\|Fatal\|FAIL' | cut -c1-160)"; fi
echo "== $pass passed, $fail failed"; [ $fail -eq 0 ]
