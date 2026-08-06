#!/usr/bin/env bash
#
# SPDX-FileCopyrightText: 2026 Conduction B.V.
# SPDX-License-Identifier: EUPL-1.2
#
# THROWAWAY — DO NOT MERGE.
#
# Bundle-truncation control for the e2e suite. Answers exactly one question:
#
#     How many tests still PASS when the app's JavaScript is a zero-byte file?
#
# Every test that passes with a dead bundle is not evidence about the app. It is
# evidence about the assertion.
#
# WHY THIS IS A SCRIPT AND NOT AN INLINE `&&` CHAIN
# -------------------------------------------------
# The previous attempt at this control (PR #677, run 30800304506) was wired as
#
#   bash apps/pipelinq/tests/e2e/ci-seed.sh \
#     && echo "[control] bundle before: $(stat -c%s …)" \
#     && truncate -s 65 apps/pipelinq/js/pipelinq-main.js \
#     && echo "[control] bundle after:  $(stat -c%s …)"
#
# and it failed as a control in TWO independent ways at once:
#
#   1. `ci-seed.sh` exited non-zero that day ("These setup steps are still
#      unmet: ['organisation']"), so the `&&` chain short-circuited and
#      `truncate` NEVER RAN. The run's 107 passes were an ordinary run of an
#      un-truncated app after a failed seed.
#   2. Its own readback could not have detected that. `$(stat …)` inside a
#      double-quoted string is expanded by the PARENT shell before `eval` runs,
#      so "before" and "after" were computed at the same instant and printed the
#      same number (3299693) BY CONSTRUCTION. Even a successful truncation would
#      have reported "no change".
#
# So: a control that did not run, reported by an instrument that could not have
# noticed. Everything below is built so neither can recur.
#
#   * the truncation is sequenced with `;`, never `&&`, and the seed's exit code
#     is captured and reported instead of being allowed to gate it;
#   * every size is read by a command that genuinely runs AFTER the truncation,
#     in the same shell that performed it;
#   * the authoritative readback is a `curl` of the SERVED bundle URL — the only
#     bytes the browser ever sees — asserting `size_download == 0`;
#   * the script FAILS LOUDLY if the before/after sizes are equal, because a
#     control that cannot show a difference has proven nothing.

set -uo pipefail

SEED="$(dirname "$0")/ci-seed.sh"
JS_DIR="apps/pipelinq/js"
BASE="${PLAYWRIGHT_BASE_URL:-${BASE_URL:-http://localhost:8080}}"
BASE="${BASE%/}"
USER_NAME="${ADMIN_USER:-admin}"
USER_PASS="${ADMIN_PASSWORD:-admin}"

echo "[control] ================= BUNDLE TRUNCATION CONTROL ================="
echo "[control] cwd: $(pwd)"

# ── 1. Seed normally, and REPORT the outcome rather than gate on it ──────────
# The truncation must happen whether or not the seed succeeded, otherwise a bad
# seed silently converts this into an ordinary run — exactly the #677 failure.
bash "$SEED"
SEED_RC=$?
echo "[control] ci-seed.sh exited ${SEED_RC}"
if [ "$SEED_RC" -ne 0 ]; then
	echo "::warning::ci-seed.sh failed (${SEED_RC}). The truncation below still runs, but the tally will mix a dead bundle with a bad fixture."
fi

# ── 2. Measure BEFORE, in this shell, right now ──────────────────────────────
echo "[control] --- js/ before ---"
ls -l "$JS_DIR"
BEFORE=$(stat -c%s "$JS_DIR/pipelinq-main.js")
echo "[control] pipelinq-main.js before = ${BEFORE} bytes"

# ── 3. Truncate EVERY app bundle to zero ─────────────────────────────────────
# Not just `-main`: webpack also emits pipelinq-portal.js and the shared
# nc-vue / vendor chunks (webpack.config.js). A control that leaves any of them
# alive is answering a weaker question than the one asked.
for f in "$JS_DIR"/*.js; do
	truncate -s 0 "$f"
done

# ── 4. Measure AFTER, in the SAME shell, after the truncation ────────────────
echo "[control] --- js/ after ---"
ls -l "$JS_DIR"
AFTER=$(stat -c%s "$JS_DIR/pipelinq-main.js")
echo "[control] pipelinq-main.js after  = ${AFTER} bytes"

if [ "$BEFORE" = "$AFTER" ]; then
	echo "::error::The control did not change the bundle (before=${BEFORE} after=${AFTER})."
	echo "::error::Whatever this run reports is NOT a truncation result. Do not use it."
	exit 1
fi

# ── 5. The authoritative readback: what the SERVER hands the browser ─────────
SERVED="$(curl -sS -o /dev/null -w '%{http_code} %{content_type} %{size_download}' \
	-u "${USER_NAME}:${USER_PASS}" "${BASE}/apps/pipelinq/js/pipelinq-main.js" || echo '000 - -')"
echo "[control] served bundle: ${SERVED}"
case "$SERVED" in
	*" 0") echo "[control] served bundle is ZERO BYTES — the SPA cannot mount. Control is live." ;;
	*)     echo "::error::The served bundle is not zero bytes (${SERVED}). The browser is still getting code." ;
	       exit 1 ;;
esac

echo "[control] =============================================================="
echo "[control] Every test that PASSES from here on passed without any app JS."
