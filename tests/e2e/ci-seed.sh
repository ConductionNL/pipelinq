#!/usr/bin/env bash
#
# SPDX-FileCopyrightText: 2026 Conduction B.V.
# SPDX-License-Identifier: EUPL-1.2
#
# Provision Pipelinq on a freshly installed Nextcloud for the shared
# `E2E Tests (Playwright)` CI job.
#
# Wired up as the workflow's `playwright-seed-command`. That step runs AFTER
# `php -S` is up and with cwd set to the Nextcloud server root, so this is
# invoked as:
#
#     playwright-seed-command: 'bash apps/pipelinq/tests/e2e/ci-seed.sh'
#
# WHAT THIS REPLACES, AND WHY
# ---------------------------
# The previous seed command was:
#
#     php occ app:disable pipelinq && php occ app:enable pipelinq
#
# It reported success on every run and provisioned nothing reliable. Three
# separate reasons, all silent:
#
#   1. `app:enable` runs the `InitializeSettings` post-migration repair step,
#      which has NO user session. OpenRegister's RBAC can refuse the writes,
#      and `run()` catches the exception and downgrades it to a warning marked
#      "Non-fatal". `occ app:enable` still exits 0.
#   2. That path calls `loadSettings(force: false)`. The non-forced branch is
#      version-guarded and can advance the recorded configuration version
#      WITHOUT applying the register, so the next run sees "already current"
#      and also does nothing. (Note that truncating the openregister tables is
#      NOT enough to reset this — the guard also reads the NC appconfig keys
#      `imported_config_<app>_version` / `_hash`.)
#   3. Even with the register present, the app's SHELL does not render. See
#      "The setup gate" below — this is what actually produced the wall of red.
#
# So this script drives the real admin HTTP API (a genuine session, which
# passes RBAC), forces the import, completes first-time setup, and then
# VERIFIES each of those things separately. A failed provision becomes one loud
# step failure here instead of ~111 misleading selector timeouts later.
#
# THE SETUP GATE — the thing that actually broke the suite
# --------------------------------------------------------
# `CnAppRoot` renders the app in phases. Two of them replace or cover the whole
# shell, and pipelinq hit BOTH on a fresh install:
#
#   * `phase === 'setup'` — a REQUIRED `manifest.setup` step is unmet. The
#     shell is REPLACED by `CnSetupWizard`: there is no `<main>`, no nav, no
#     router-view. Pipelinq's one required step is the reporting currency, and
#     a fresh install has no currency set. Every UI spec then fails on a
#     selector timeout whose message points at the selector, not at the cause.
#
#   * the non-gating optional wizard — every required step is met but some
#     OPTIONAL step is not. `CnAppRoot` then AUTO-OPENS `CnSetupWizard` as a
#     full `dialog__modal modal-mask` over the shell. Its dismissal is stored
#     in localStorage, and every Playwright test runs in a fresh context, so it
#     reopens in every single test. Verified in a real browser:
#     `document.elementsFromPoint(centre)` resolved inside
#     `.cn-wizard-dialog__step-body` while `main` existed underneath.
#
# Steps 2 and 3 below clear the first; step 3 also clears the second, because
# `seed-demo-data` records the demo-data decision. Step 5 then ASSERTS the
# result rather than assuming it.
#
# It is idempotent: the import and the demo seed are both idempotent
# server-side, and re-running only re-verifies.

set -euo pipefail

# ── Target resolution ────────────────────────────────────────────────────────
# The shared workflow's "Seed test data" step exports BASE_URL / ADMIN_USER /
# ADMIN_PASSWORD (ConductionNL/.github#124). Accept every name the fleet uses,
# and fall back to the CI runner's own `php -S 0.0.0.0:8080` only when we can
# prove we are on CI.
#
# On a developer box `localhost:8080` is the SHARED dev container, and this
# script performs ADMIN WRITES — it must never silently provision into someone
# else's environment. Off CI, an unset target is a hard error.
BASE="${PLAYWRIGHT_BASE_URL:-${BASE_URL:-${NEXTCLOUD_URL:-${NC_BASE_URL:-}}}}"
if [ -z "$BASE" ]; then
	if [ "${GITHUB_ACTIONS:-}" = "true" ] || [ "${CI:-}" = "true" ]; then
		BASE="http://localhost:8080"
	else
		echo "ERROR: no base URL set. Export PLAYWRIGHT_BASE_URL or BASE_URL." >&2
		echo "       Refusing to default to http://localhost:8080 outside CI —" >&2
		echo "       that is the SHARED dev container and this script writes to it." >&2
		exit 1
	fi
fi
BASE="${BASE%/}"

USER_NAME="${ADMIN_USER:-${NC_ADMIN_USER:-admin}}"
USER_PASS="${ADMIN_PASSWORD:-${NC_ADMIN_PASS:-admin}}"
APP_BASE="${BASE}/index.php/apps/pipelinq"

echo "[ci-seed] target: ${BASE}"

# ── 0. Pretty URLs, and the diagnostic that proves what the BROWSER sees ─────
#
# `generateUrl()` from @nextcloud/router is:
#
#     if (window.OC.config.modRewriteWorking === true) return webroot + path
#     return webroot + '/index.php' + path
#
# and Nextcloud sets `modRewriteWorking` from `htaccess.IgnoreFrontController`.
# A freshly `occ maintenance:install`ed instance behind `php -S` does NOT have
# it set, so every generated URL carries an `/index.php` prefix that a real
# Apache deployment does not.
#
# WHAT THAT COSTS ELSEWHERE, AND WHY IT IS NOT FATAL HERE. Apps whose router is
# `createWebHistory(generateUrl('/apps/<app>'))` get a router BASE of
# `/index.php/apps/<app>`, which is not a prefix of the `/apps/<app>/…` URL a
# spec opens — vue-router matches nothing and silently falls back to the default
# route. That single cause produced 36 failures in decidesk and 67 of 68 in
# openbuild. Pipelinq is structurally immune: `src/main.js` builds
# `createWebHashHistory(generateUrl('/apps/pipelinq'))`, and vue-router's hash
# history reads the route from `location.hash` (`base.slice(hashPos)` is just
# `'#'`), so the path prefix cannot shift which route matches.
#
# It is still set, because the CI box should describe a real deployment rather
# than a special case, and the shared workflow's `ci-router.php` already serves
# pretty URLs (it mirrors Nextcloud's .htaccess). And it is VERIFIED against the
# SERVED PAGE, not against config.php: `occ` writing a value is not evidence
# that the SPA sees it, and the whole point of the flag is what the browser
# reads out of `OC.config`.
if [ -f "./occ" ]; then
	if php occ config:system:set htaccess.IgnoreFrontController --value=true --type=boolean; then
		echo "[ci-seed] pretty URLs enabled (htaccess.IgnoreFrontController=true)."
	else
		echo "::warning::Could not set htaccess.IgnoreFrontController — generated URLs will keep the /index.php prefix."
	fi
	echo "[ci-seed] read-back: $(php occ config:system:get htaccess.IgnoreFrontController || echo '<unset>')"
else
	echo "::warning::No occ in $(pwd) — skipping the pretty-URL setting."
fi

# Small helper: POST JSON as the admin, echo the status, dump the body.
# Basic auth without a session cookie skips Nextcloud's CSRF check, which is
# why these admin-only endpoints are reachable from curl at all.
post_json() {
	local url="$1" data="${2:-}" body code
	body="$(mktemp)"
	if [ -n "$data" ]; then
		code="$(curl -sS -o "$body" -w '%{http_code}' -u "${USER_NAME}:${USER_PASS}" \
			-X POST -H 'Content-Type: application/json' -H 'OCS-APIRequest: true' \
			--data "$data" "$url" || echo 000)"
	else
		code="$(curl -sS -o "$body" -w '%{http_code}' -u "${USER_NAME}:${USER_PASS}" \
			-X POST -H 'Content-Type: application/json' -H 'OCS-APIRequest: true' \
			"$url" || echo 000)"
	fi
	echo "[ci-seed] POST ${url} -> HTTP ${code}"
	head -c 1200 "$body"; echo
	POST_CODE="$code"
	POST_BODY="$body"
}

# ── 1. Force-import the Pipelinq register + schemas ──────────────────────────
# `settings#reimport` calls `loadSettings(force: true)`, which is the whole
# point: it defeats the version guard that makes the repair-step path a no-op.
post_json "${APP_BASE}/api/settings/reimport"
if [ "$POST_CODE" != "200" ]; then
	echo "::error::Pipelinq configuration import failed (HTTP ${POST_CODE}). The e2e suite has no registers or schemas to read."
	exit 1
fi

# ── 2. Complete the REQUIRED setup step (reporting currency) ─────────────────
# Without this, CnAppRoot's phase is 'setup' and the ENTIRE shell is replaced
# by the wizard — no <main>, no nav — so every UI spec fails on a selector
# timeout for a reason that has nothing to do with the assertion.
post_json "${APP_BASE}/api/setup/config" '{"currency":"EUR"}'
if [ "$POST_CODE" != "200" ]; then
	echo "::error::Could not set the required reporting currency (HTTP ${POST_CODE}). CnAppRoot will gate the whole shell behind the setup wizard."
	exit 1
fi

# ── 3. Seed the demo dataset ─────────────────────────────────────────────────
# Two jobs at once. It gives the list/dashboard specs real objects to assert
# on, AND it records the demo-data decision, which is what stops the optional
# setup wizard auto-opening as a modal mask over every single test.
post_json "${APP_BASE}/api/setup/action/seed-demo-data"
if [ "$POST_CODE" != "200" ]; then
	echo "::error::Demo-data seeding failed (HTTP ${POST_CODE}). Lists and dashboards would be empty, and the optional setup wizard would cover the app in every test."
	exit 1
fi

# ── 4. Verify the register and schemas actually exist ────────────────────────
# The import reporting success is not the same as the register existing —
# verify against OpenRegister directly.
verify_registers() {
	python3 - "$1" <<'PY'
import json, sys
path = sys.argv[1]
with open(path) as fh:
    raw = fh.read()
try:
    body = json.loads(raw)
except json.JSONDecodeError:
    print('::error::OpenRegister registers endpoint did not return JSON. First 500 bytes:')
    print(raw[:500])
    sys.exit(1)
items = body if isinstance(body, list) else body.get('results', [])
slugs = {i.get('slug') for i in items if isinstance(i, dict)}
print(f'[ci-seed] registers present: {sorted(s for s in slugs if s)}')
if 'pipelinq' not in slugs:
    print("::error::The 'pipelinq' register is missing after a forced import.")
    sys.exit(1)
print('[ci-seed] registers OK')
PY
}

REG_BODY="$(mktemp)"
curl -sS -u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	"${BASE}/index.php/apps/openregister/api/registers?_limit=300" -o "$REG_BODY"
verify_registers "$REG_BODY"

# ── 5. Verify first-time setup is genuinely complete ─────────────────────────
# This is the check that would have caught the original failure immediately.
#
# `useSetupStatus` walks the MANIFEST's step list and looks each id up in this
# response. Any manifest step id the endpoint does not report resolves to
# `done: false` and counts as unmet FOREVER — and an unmet optional step makes
# CnAppRoot cover the shell with the wizard in every fresh browser context.
# So compare the two lists directly instead of trusting `completed`.
verify_setup() {
	python3 - "$1" "$2" <<'PY'
import json, sys
status_path, manifest_path = sys.argv[1], sys.argv[2]
with open(status_path) as fh:
    raw = fh.read()
try:
    status = json.loads(raw)
except json.JSONDecodeError:
    print('::error::setup/status did not return JSON. First 500 bytes:')
    print(raw[:500])
    sys.exit(1)

with open(manifest_path) as fh:
    manifest = json.load(fh)

steps = (manifest.get('setup') or {}).get('steps') or []
reported = status.get('steps') or {}


def done(step_id):
    return reported.get(step_id, {}).get('done') is True


# A step the server never MENTIONS can never be completed by anyone — that is
# the structural defect this check exists for. A step that is merely NOT DONE
# is a different thing entirely, and for an optional step it is the normal
# state of a fresh install.
missing = [s['id'] for s in steps if s['id'] not in reported]
required_unmet = [s['id'] for s in steps if s.get('required') is True and not done(s['id'])]
optional_unmet = [s['id'] for s in steps if s.get('required') is not True and not done(s['id'])]

print(f"[ci-seed] setup completed flag : {status.get('completed')}")
print(f"[ci-seed] manifest step ids    : {[s['id'] for s in steps]}")
print(f"[ci-seed] reported step ids    : {sorted(reported)}")
if optional_unmet:
    print(f"[ci-seed] optional not-yet-done : {optional_unmet}  (fine — see below)")

ok = True
if missing:
    print(f'::error::setup/status does not report these manifest steps at all: {missing}.')
    print('::error::useSetupStatus looks every MANIFEST step id up in this response, so a step')
    print('::error::the server never reports resolves to done:false and is unmet FOREVER —')
    print('::error::no operator action can clear it. CnAppRoot then auto-opens CnSetupWizard')
    print('::error::as a modal mask over the app, in every fresh browser context, in every test.')
    ok = False
if required_unmet:
    print(f'::error::REQUIRED setup steps are unmet: {required_unmet}.')
    print('::error::CnAppRoot REPLACES the whole shell with the wizard while that is true —')
    print('::error::no <main>, no nav — so every UI spec fails on a selector timeout.')
    ok = False
if status.get('completed') is not True:
    print('::error::setup/status reports completed != true.')
    print('::error::nextcloud-vue >= 2.1.0-vue3.17 uses that flag to suppress the optional')
    print('::error::setup wizard; without it the wizard auto-opens over the shell.')
    ok = False
if not ok:
    sys.exit(1)

# Deliberately NOT an error: an unmet OPTIONAL step is the normal state of a
# fresh install (nobody has typed an organisation name), and since
# nextcloud-vue 2.1.0-vue3.17 the server's `completed` flag suppresses the
# optional wizard regardless. Failing on it would have meant seeding cosmetic
# data purely to satisfy this gate, which tests nothing.
print('[ci-seed] first-time setup OK — the shell will render and no wizard will cover it')
PY
}

SETUP_BODY="$(mktemp)"
curl -sS -u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	"${APP_BASE}/api/setup/status" -o "$SETUP_BODY"
verify_setup "$SETUP_BODY" "$(dirname "$0")/../../src/manifest.json"

# ── 6. Warm the SPA, and GATE on the bundle actually being JavaScript ────────
# The shared workflow serves Nextcloud with `php -S`. Warm the routes the first
# spec will hit so it is not measuring server start-up. Failures here are
# ignored on purpose — this part is a warm-up, not a gate. The real checks are
# above and below.
for path in \
	"/index.php/apps/pipelinq/" \
	"/index.php/apps/pipelinq/api/setup/status" \
	"/index.php/apps/openregister/api/registers?_limit=1"
do
	code="$(curl -sS -o /dev/null -w '%{http_code}' -u "${USER_NAME}:${USER_PASS}" \
		-H 'OCS-APIRequest: true' "${BASE}${path}" || echo 000)"
	echo "[ci-seed] warm ${path} -> ${code}"
done

# Do NOT hardcode the bundle URL. Nextcloud serves an app's assets from
# whichever apps directory it was installed into — `/apps/<app>/js/…` on the CI
# runner, `/custom_apps/<app>/js/…` in the docker dev images — and asking for
# the wrong one does NOT 404. It returns **HTTP 200 with `text/html`**: the NC
# error page, served through index.php. A status-code check therefore reports
# success while fetching an HTML page instead of a multi-megabyte bundle.
#
# Read the real src out of the rendered app page instead, and verify the
# response is actually JavaScript.
APP_HTML="$(mktemp)"
curl -sS -u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	"${APP_BASE}/" -o "$APP_HTML" || true

# THE ONLY EVIDENCE THAT COUNTS for step 0: what the served page tells the SPA.
# `OC.config` is emitted into the rendered page, so this reads the exact value
# `generateUrl()` will branch on. Reported either way — a mismatch between the
# config read-back above and this line means occ wrote a value the web SAPI is
# not serving (stale opcache is the usual reason), which is worth seeing before
# 200 selector timeouts are blamed on the app.
if grep -q '"modRewriteWorking":true' "$APP_HTML"; then
	echo "[ci-seed] served page reports modRewriteWorking:true — generated URLs are prefix-free."
else
	echo "::warning::The served app page does NOT report \"modRewriteWorking\":true."
	echo "::warning::generateUrl() will prefix every URL with /index.php. Pipelinq's hash router"
	echo "::warning::tolerates that (see step 0), but it means the CI instance is NOT describing a"
	echo "::warning::real Apache deployment, and any future switch to createWebHistory would break."
	grep -o '"modRewriteWorking":[a-z]*' "$APP_HTML" | head -1 || echo "::warning::(the key is not present in the page at all)"
fi

# `|| true` is load-bearing: grep exits 1 when it matches nothing, and under
# `set -euo pipefail` that aborts the script right here — so the case the gate
# below exists to explain (no bundle) would die with a bare non-zero exit and
# none of the diagnosis. Let it fall through to the gate instead.
BUNDLE_SRC="$(grep -oE 'src="[^"]*pipelinq-main[^"]*"' "$APP_HTML" \
	| head -1 | sed 's/^src="//; s/"$//' || true)"

if [ -n "$BUNDLE_SRC" ]; then
	BUNDLE_INFO="$(curl -sS -o /dev/null \
		-w '%{http_code} %{content_type} %{size_download}' \
		-u "${USER_NAME}:${USER_PASS}" "${BASE}${BUNDLE_SRC}" || echo '000 - 0')"
	echo "[ci-seed] warm bundle ${BUNDLE_SRC} -> ${BUNDLE_INFO}"
else
	echo "[ci-seed] could not locate the bundle src in the rendered app page."
	BUNDLE_INFO=""
fi

# On CI this is a GATE, not a warm-up.
#
# The single most likely way this job "succeeds" dishonestly is by passing
# without ever loading the app, and the environment hides it well: when the
# bundle is absent Nextcloud does not 404, it serves its HTML error page with
# HTTP 200 and Content-Type text/html. So `npm run build` producing nothing
# looks, to every status-code check in the pipeline, exactly like success.
#
# ⚠️ Note for anyone testing this control: DELETING the bundle does not
# reproduce that state, because `tests/e2e/global-setup.ts`'s
# `ensureBundleBuilt()` does an `fs.existsSync()` check and silently rebuilds
# it. TRUNCATE the file instead.
#
# PIPELINQ_SEED_BUNDLE_GATE=off — the ONE legitimate opt-out, and it is narrow.
# This gate asks "can the SPA mount?", which is a question only a job that
# drives a browser has any stake in. The shared workflow's Newman job
# (`quality.yml`, job `newman`) has no "Build app frontend" step at all: its
# steps are checkout → setup-php → install NC → composer install → app:enable →
# `php -S` → seed → newman. There is therefore never a bundle to serve there,
# and leaving the gate armed would fail the seed — and so the whole job — for a
# reason that has nothing to do with any API assertion Newman makes.
#
# It is an explicit opt-out rather than a sniff for "is a browser involved"
# precisely so it cannot switch itself off: `tests/newman/ci-seed.sh` sets it,
# nothing else does, and the Playwright path is untouched. If you find yourself
# setting it for a job that DOES render the app, you are disarming a real gate.
if [ "${PIPELINQ_SEED_BUNDLE_GATE:-on}" != "on" ]; then
	echo "[ci-seed] bundle gate disabled by PIPELINQ_SEED_BUNDLE_GATE=${PIPELINQ_SEED_BUNDLE_GATE}."
	echo "[ci-seed] (API-only caller — no frontend build in this job, so there is no bundle to verify.)"
elif [ "${GITHUB_ACTIONS:-}" = "true" ] || [ "${CI:-}" = "true" ]; then
	case "$BUNDLE_INFO" in
		*javascript*)
			echo "[ci-seed] bundle verified as JavaScript."
			;;
		*)
			echo "::error::The Pipelinq frontend bundle did not serve as JavaScript (got: ${BUNDLE_INFO:-<not found>})."
			echo "::error::The SPA cannot mount, so every UI spec would fail on a selector timeout with a misleading cause."
			echo "::error::Check the 'Build app frontend' step — a missing bundle returns HTTP 200 text/html, not 404."
			exit 1
			;;
	esac
fi

echo "[ci-seed] done."
