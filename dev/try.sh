#!/usr/bin/env bash
# Fresh stack, full end-to-end suite, then the instance in Chrome to click
# around by hand.
#
#   ./dev/try.sh             fresh stack + tests + browser
#   ./dev/try.sh --no-tests  fresh stack + browser
#
# The suite changes ticket statuses, so the browser opens on the state the
# tests left behind. For untouched seed data use --no-tests.
# The stack keeps running afterwards; stop it with ./dev/down.sh.
set -uo pipefail

DEV="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(dirname "$DEV")"
CRED="$DEV/local/credentials.env"
RUN_TESTS=1
[ "${1:-}" = "--no-tests" ] && RUN_TESTS=0

"$DEV/down.sh" >/dev/null 2>&1 || true
"$DEV/up.sh" || { echo "Stack did not come up." >&2; exit 1; }
set -a; . "$CRED"; set +a

RESULT="not run"
if [ "$RUN_TESTS" = 1 ]; then
  ( cd "$ROOT/tests/e2e" && { [ -d node_modules ] || npm ci; } && npx playwright test --reporter=line )
  [ $? -eq 0 ] && RESULT="passed" || RESULT="FAILED (report: dev/local/e2e-report/index.html)"
fi

BASE="http://127.0.0.1:${SPB_HTTP_PORT}"
URLS=("$BASE/login.php" "$BASE/scp/login.php" "http://127.0.0.1:${SPB_MAIL_PORT}/")

open_url() {
  case "$(uname -s)" in
    MINGW*|MSYS*|CYGWIN*)
      # "start chrome" resolves Chrome via App Paths; falls back to the
      # default browser if Chrome isn't installed.
      cmd.exe //c start "" chrome "$1" 2>/dev/null || cmd.exe //c start "" "$1" ;;
    Darwin)
      open -a "Google Chrome" "$1" 2>/dev/null || open "$1" ;;
    *)
      if command -v google-chrome >/dev/null; then google-chrome "$1" >/dev/null 2>&1 &
      else xdg-open "$1" >/dev/null 2>&1 & fi ;;
  esac
}
for u in "${URLS[@]}"; do open_url "$u"; done

cat <<EOF

End-to-end suite: $RESULT

Opened in the browser:
  Portal login   $BASE/login.php
  Agent login    $BASE/scp/login.php     Board: Applications, then Board
  Mailpit        http://127.0.0.1:${SPB_MAIL_PORT}/

Logins (passwords in dev/local/credentials.env):
  Agent, all departments     ${SPB_ADMIN_USER}       SPB_ADMIN_PASS
  Agent, Sales, view only    ${SPB_AGENT_USER}       SPB_AGENT_PASS
  Customer Acme (org shares) alice@acme.example     SPB_CLIENT_PASS
  Customer Acme              bob@acme.example       SPB_CLIENT_PASS
  Customer Globex            carol@globex.example   SPB_CLIENT_PASS

Stop the stack: ./dev/down.sh
EOF
