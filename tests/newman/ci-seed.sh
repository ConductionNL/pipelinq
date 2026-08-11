#!/usr/bin/env bash
#
# SPDX-FileCopyrightText: 2026 Conduction B.V.
# SPDX-License-Identifier: EUPL-1.2
#
# Provision Pipelinq on a freshly installed Nextcloud for the shared
# `Integration Tests (Newman)` CI job.
#
# Wired up as the workflow's `newman-seed-command`. That step runs AFTER
# `php -S` is up and with cwd set to the Nextcloud server root, so this is
# invoked as:
#
#     newman-seed-command: 'bash apps/pipelinq/tests/newman/ci-seed.sh'
#
# WHY THIS EXISTS AT ALL, AND WHY IT IS NOT tests/e2e/ci-seed.sh VERBATIM
# ----------------------------------------------------------------------
# `enable-newman` sat at `false` with a recorded reason: "the CRM endpoints need
# the OpenRegister registers/schemas seeded (the disable/enable repair cycle
# alone isn't enough)". Half of that has expired. The shared workflow grew a
# `newman-seed-command` input, and this repo grew `tests/e2e/ci-seed.sh`, which
# forces the register import, completes first-time setup, seeds the demo dataset
# and then VERIFIES each of those. That covers the recorded blocker exactly, so
# step 1 below simply runs it rather than reimplementing it.
#
# It does NOT cover three further things, all of which were measured against a
# real freshly-installed NC32 + OpenRegister@development instance, not assumed:
#
#   A. THE COLLECTIONS TAKE OBJECT IDs AS INPUT.
#      `outbound-messaging` needs `{{contactId}}`; `semantic-handoff` needs
#      `{{requestId}}` (a ticket with ticketType=request AND status=in_progress)
#      and `{{contractId}}` (status=active). Their committed defaults are the
#      empty string, which does not 404 — the path collapses to
#      `/api/messaging/preflight/` and pipelinq's SPA catch-all
#      (`dashboard#page /{path}`) answers **HTTP 200 with text/html**. Every
#      test script then died on `JSONError: Unexpected token '<'`. The ids are
#      discovered here from the demo dataset the e2e seed already installs, so
#      nothing deployment-specific is ever hardcoded into a collection (which is
#      exactly what makes `tests/integration/pipelinq.postman_collection.json`
#      unrunnable on CI — see the note in code-quality.yml).
#
#   B. THE 403 TEST NEEDS A SECOND, GENUINELY NON-ADMIN IDENTITY.
#      `PUT /api/rapportage/sla` is `#[AuthorizedAdminSetting]`, and the
#      collection asserted 403 while referring to `{{nonAdminUsername}}` /
#      `{{nonAdminPassword}}` — variables NOTHING defined. Postman then sends an
#      Authorization header that authenticates nobody, Nextcloud falls back to
#      the session cookie Newman's jar picked up from the preceding admin
#      requests, and the call runs **as admin**. Measured: HTTP 200, i.e. the
#      "non-admin is refused" test actually performed the admin write it exists
#      to prove is refused. This script creates that user; the collection now
#      also asserts WHO the caller is via `/ocs/v2.php/cloud/user` before
#      trusting the status code.
#
#   C. THE NEWMAN JOB HAS NO FRONTEND BUILD.
#      `tests/e2e/ci-seed.sh` ends on a hard CI gate that the served
#      `pipelinq-main` bundle is really JavaScript. The shared workflow's
#      `newman` job never builds one (its steps are checkout → setup-php →
#      install NC → composer install → app:enable → `php -S` → seed → newman),
#      so that gate would fail the seed, and with it the job, for a reason no
#      API assertion depends on. `PIPELINQ_SEED_BUNDLE_GATE=off` below is the
#      narrow, explicit opt-out; the Playwright path keeps the gate armed.
#
# Everything this script discovers is VERIFIED against the endpoint that will
# consume it before the environment file is written. A seed that cannot find a
# usable id fails here, loudly, instead of handing Newman an empty string and
# letting 12 assertions fail with a misleading JSON parse error.
#
# It is idempotent: the underlying e2e seed is, the user creation tolerates an
# existing user, and the id discovery is a read.

set -euo pipefail

# ── Target resolution ────────────────────────────────────────────────────────
# Identical contract to tests/e2e/ci-seed.sh, and for the identical reason: the
# shared workflow's "Seed test data" step exports BASE_URL / ADMIN_USER /
# ADMIN_PASSWORD, and on a developer box `localhost:8080` is the SHARED dev
# container, which this script performs ADMIN WRITES against. Off CI, an unset
# target is a hard error rather than a default.
BASE="${PLAYWRIGHT_BASE_URL:-${BASE_URL:-${NEXTCLOUD_URL:-${NC_BASE_URL:-}}}}"
if [ -z "$BASE" ]; then
	if [ "${GITHUB_ACTIONS:-}" = "true" ] || [ "${CI:-}" = "true" ]; then
		BASE="http://localhost:8080"
	else
		echo "ERROR: no base URL set. Export BASE_URL (or NEXTCLOUD_URL)." >&2
		echo "       Refusing to default to http://localhost:8080 outside CI —" >&2
		echo "       that is the SHARED dev container and this script writes to it." >&2
		exit 1
	fi
fi
BASE="${BASE%/}"

ADMIN_NAME="${ADMIN_USER:-${NC_ADMIN_USER:-admin}}"
ADMIN_PASS_VALUE="${ADMIN_PASSWORD:-${NC_ADMIN_PASS:-admin}}"
APP_BASE="${BASE}/index.php/apps/pipelinq"
OR_BASE="${BASE}/index.php/apps/openregister"

# The second identity the authorization assertions are made as. Nextcloud
# silently refuses passwords under 10 characters, so this is deliberately long;
# it is a throwaway CI account on a throwaway instance.
NONADMIN_USER="${PIPELINQ_NEWMAN_NONADMIN_USER:-pipelinq-newman-user}"
NONADMIN_PASS="${PIPELINQ_NEWMAN_NONADMIN_PASS:-NewmanContractRing-2026}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="${SCRIPT_DIR}/ci.postman_environment.json"

echo "[newman-seed] target: ${BASE}"

# ── 1. Shared provisioning — register import, setup, demo data ───────────────
# ONE home for the provisioning both suites need. See the header for why the
# bundle gate is the single thing turned off.
echo "[newman-seed] === running tests/e2e/ci-seed.sh for the shared provisioning ==="
PIPELINQ_SEED_BUNDLE_GATE=off \
BASE_URL="$BASE" \
ADMIN_USER="$ADMIN_NAME" \
ADMIN_PASSWORD="$ADMIN_PASS_VALUE" \
	bash "${SCRIPT_DIR}/../e2e/ci-seed.sh"
echo "[newman-seed] === shared provisioning done ==="

# Small helper: request as the admin, capture status + body.
# Basic auth without a session cookie skips Nextcloud's CSRF check, which is why
# these admin-only endpoints are reachable from curl at all.
api() {
	local method="$1" url="$2" data="${3:-}"
	API_BODY="$(mktemp)"
	if [ -n "$data" ]; then
		API_CODE="$(curl -sS -o "$API_BODY" -w '%{http_code}' -u "${ADMIN_NAME}:${ADMIN_PASS_VALUE}" \
			-X "$method" -H 'Content-Type: application/json' -H 'OCS-APIRequest: true' \
			-H 'Accept: application/json' --data "$data" "$url" || echo 000)"
	else
		API_CODE="$(curl -sS -o "$API_BODY" -w '%{http_code}' -u "${ADMIN_NAME}:${ADMIN_PASS_VALUE}" \
			-X "$method" -H 'OCS-APIRequest: true' -H 'Accept: application/json' "$url" || echo 000)"
	fi
}

# ── 2. The non-admin identity the authorization assertions run as ────────────
# `|| true` on the create: an existing user is reported as OCS statuscode 102
# with an HTTP 200, and re-running this script must not fail on it. The password
# is then set unconditionally, so a user left over from an earlier run with a
# different password still ends up usable.
echo "[newman-seed] provisioning non-admin user '${NONADMIN_USER}'"
CREATE_CODE="$(curl -sS -o /dev/null -w '%{http_code}' -u "${ADMIN_NAME}:${ADMIN_PASS_VALUE}" \
	-H 'OCS-APIRequest: true' -X POST \
	--data-urlencode "userid=${NONADMIN_USER}" \
	--data-urlencode "password=${NONADMIN_PASS}" \
	"${BASE}/ocs/v2.php/cloud/users?format=json" || echo 000)"
echo "[newman-seed] POST /ocs/v2.php/cloud/users -> HTTP ${CREATE_CODE}"

curl -sS -o /dev/null -u "${ADMIN_NAME}:${ADMIN_PASS_VALUE}" -H 'OCS-APIRequest: true' \
	-X PUT --data-urlencode "key=password" --data-urlencode "value=${NONADMIN_PASS}" \
	"${BASE}/ocs/v2.php/cloud/users/${NONADMIN_USER}?format=json" || true

# THE CHECK THAT MATTERS. Creating a user is not evidence that the credentials
# authenticate, and the whole point of this account is that a request made with
# it is made BY it. Ask the server who it thinks is calling.
WHOAMI_BODY="$(mktemp)"
WHOAMI_CODE="$(curl -sS -o "$WHOAMI_BODY" -w '%{http_code}' -u "${NONADMIN_USER}:${NONADMIN_PASS}" \
	-H 'OCS-APIRequest: true' "${BASE}/ocs/v2.php/cloud/user?format=json" || echo 000)"
echo "[newman-seed] GET /ocs/v2.php/cloud/user as ${NONADMIN_USER} -> HTTP ${WHOAMI_CODE}"
python3 - "$WHOAMI_BODY" "$NONADMIN_USER" <<'PY'
import json, sys
path, expected = sys.argv[1], sys.argv[2]
with open(path) as fh:
    raw = fh.read()
try:
    uid = json.loads(raw)['ocs']['data']['id']
except Exception:
    print('::error::/ocs/v2.php/cloud/user did not return an OCS user document for the')
    print('::error::non-admin account. Its credentials do not authenticate, so the 403')
    print('::error::assertion would have been made by whoever the cookie jar last')
    print('::error::authenticated — which is the admin. First 500 bytes:')
    print(raw[:500])
    sys.exit(1)
if uid != expected:
    print(f"::error::The non-admin credentials authenticate as '{uid}', not '{expected}'.")
    sys.exit(1)
print(f"[newman-seed] non-admin identity confirmed: {uid}")
PY

# The account must NOT be an admin — a 403 assertion made by an admin that
# happens to be refused for some other reason would be a green test proving
# nothing. Read the admin group membership back.
GROUPS_BODY="$(mktemp)"
curl -sS -o "$GROUPS_BODY" -u "${ADMIN_NAME}:${ADMIN_PASS_VALUE}" -H 'OCS-APIRequest: true' \
	"${BASE}/ocs/v2.php/cloud/users/${NONADMIN_USER}/groups?format=json" || true
python3 - "$GROUPS_BODY" <<'PY'
import json, sys
with open(sys.argv[1]) as fh:
    raw = fh.read()
try:
    groups = json.loads(raw)['ocs']['data']['groups']
except Exception:
    print('::error::Could not read the group membership of the non-admin account.')
    print(raw[:500])
    sys.exit(1)
if 'admin' in groups:
    print(f'::error::The "non-admin" account is in the admin group ({groups}).')
    print('::error::It would be ALLOWED through PUT /api/rapportage/sla, and the 403')
    print('::error::assertion would fail — or worse, pass for the wrong reason.')
    sys.exit(1)
print(f'[newman-seed] non-admin groups: {groups or "[]"} (no admin) OK')
PY

# ── 3. Resolve the register + schema ids this deployment actually uses ───────
# Read them from the app rather than hardcoding: the numeric ids differ per
# instance, and a collection that hardcodes them only ever runs on the box they
# were copied from.
api GET "${APP_BASE}/api/settings"
if [ "$API_CODE" != "200" ]; then
	echo "::error::GET /api/settings returned HTTP ${API_CODE} — cannot resolve the register/schema ids."
	head -c 500 "$API_BODY"; echo
	exit 1
fi
CONFIG_BODY="$API_BODY"

# Assigned to a variable FIRST, then split. A `$(...)` nested inside a heredoc
# is not a command whose status `set -e` inspects, so a failing resolver there
# would have been swallowed and the ids would have come out empty — the exact
# shape of failure this script exists to make loud.
CONFIG_IDS="$(python3 - "$CONFIG_BODY" <<'PY'
import json, sys
with open(sys.argv[1]) as fh:
    cfg = (json.load(fh) or {}).get('config') or {}
keys = ['register', 'contact_schema', 'ticket_schema', 'contract_schema']
values = [str(cfg.get(k) or '') for k in keys]
missing = [k for k, v in zip(keys, values) if v == '']
if missing:
    print('::error::pipelinq settings do not carry: ' + ', '.join(missing), file=sys.stderr)
    print('::error::The forced re-import did not apply, so the CRM endpoints have no data layer.', file=sys.stderr)
    sys.exit(1)
print(' '.join(values))
PY
)"
read -r REGISTER_ID CONTACT_SCHEMA TICKET_SCHEMA CONTRACT_SCHEMA <<< "$CONFIG_IDS"
echo "[newman-seed] register=${REGISTER_ID} contact=${CONTACT_SCHEMA} ticket=${TICKET_SCHEMA} contract=${CONTRACT_SCHEMA}"

# ── 4. Discover the object ids the collections take as input ─────────────────
# Read from the demo dataset step 1 installed. `_limit` is generous because the
# ticket schema is a supertype: requests, complaints and contactmomenten share
# it, and only a `request` in `in_progress` satisfies the convert gate the
# semantic-handoff collection asserts on.
fetch_objects() {
	local schema="$1" out
	out="$(mktemp)"
	local code
	code="$(curl -sS -o "$out" -w '%{http_code}' -u "${ADMIN_NAME}:${ADMIN_PASS_VALUE}" \
		-H 'OCS-APIRequest: true' -H 'Accept: application/json' \
		"${OR_BASE}/api/objects/${REGISTER_ID}/${schema}?_limit=200" || echo 000)"
	if [ "$code" != "200" ]; then
		echo "::error::OpenRegister objects for schema ${schema} returned HTTP ${code}." >&2
		head -c 500 "$out" >&2; echo >&2
		exit 1
	fi
	echo "$out"
}

CONTACTS_JSON="$(fetch_objects "$CONTACT_SCHEMA")"
TICKETS_JSON="$(fetch_objects "$TICKET_SCHEMA")"
CONTRACTS_JSON="$(fetch_objects "$CONTRACT_SCHEMA")"

pick() {
	# pick <file> <label> [<field>=<value> ...]
	python3 - "$@" <<'PY'
import json, sys
path, label = sys.argv[1], sys.argv[2]
criteria = [c.split('=', 1) for c in sys.argv[3:]]
with open(path) as fh:
    body = json.load(fh)
items = body if isinstance(body, list) else (body.get('results') or [])


def matches(obj):
    return all(str(obj.get(k, '')) == v for k, v in criteria)


hits = [o for o in items if isinstance(o, dict) and matches(o)]
if not hits:
    crit = ', '.join(f'{k}={v}' for k, v in criteria) or '(any)'
    print(f'::error::No {label} object found matching {crit} among {len(items)} objects.', file=sys.stderr)
    print('::error::The demo dataset did not install what the collection asserts against.', file=sys.stderr)
    print('::error::Newman would be handed an empty id, the SPA catch-all would answer 200', file=sys.stderr)
    print("::error::text/html, and every assertion would fail as 'Unexpected token <'.", file=sys.stderr)
    sys.exit(1)
# Sort for determinism — the OR list order is not guaranteed stable across runs.
print(sorted(str(o.get('id') or '') for o in hits if o.get('id'))[0])
PY
}

CONTACT_ID="$(pick "$CONTACTS_JSON" contact)"
REQUEST_ID="$(pick "$TICKETS_JSON" 'request ticket' ticketType=request status=in_progress)"
CONTRACT_ID="$(pick "$CONTRACTS_JSON" contract status=active)"
echo "[newman-seed] contactId=${CONTACT_ID}"
echo "[newman-seed] requestId=${REQUEST_ID}"
echo "[newman-seed] contractId=${CONTRACT_ID}"

# ── 5. VERIFY each id against the endpoint that will consume it ──────────────
# An id that exists in OpenRegister is not the same as an id the controller can
# load: `SemanticHandoffController::loadRequestTicket()` additionally requires
# ticketType=request, and `MessagingService::loadContact()` has its own lookup.
# Both answer 404 with `{"status":"not-found"}` when they disagree with us — and
# `not-found` is not in either collection's list of acceptable outcomes, so this
# would surface as a confusing assertion failure minutes later instead of here.
verify_endpoint() {
	local label="$1" url="$2"
	local out code
	out="$(mktemp)"
	code="$(curl -sS -o "$out" -w '%{http_code}' -u "${ADMIN_NAME}:${ADMIN_PASS_VALUE}" \
		-H 'OCS-APIRequest: true' -H 'Accept: application/json' "$url" || echo 000)"
	echo "[newman-seed] verify ${label} -> HTTP ${code} $(head -c 200 "$out")"
	if [ "$code" != "200" ]; then
		echo "::error::${label} did not resolve (HTTP ${code}). The seeded id is not usable by the controller."
		exit 1
	fi
}

verify_endpoint "messaging preflight (contactId)" "${APP_BASE}/api/messaging/preflight/${CONTACT_ID}"
verify_endpoint "handoff request availability (requestId)" "${APP_BASE}/api/handoff/request/${REQUEST_ID}/availability"
verify_endpoint "handoff contract availability (contractId)" "${APP_BASE}/api/handoff/contract/${CONTRACT_ID}/availability"

# ── 6. Write the Postman environment the run step consumes ──────────────────
# The shared workflow reads `newman-environment-path` as
# `$GITHUB_WORKSPACE/server/apps/<app>/<path>` and passes it as `--environment`.
# Generated rather than committed on purpose: a committed file with placeholder
# ids is indistinguishable from a seeded one right up until the assertions fail.
python3 - "$ENV_FILE" "$BASE" "$ADMIN_NAME" "$ADMIN_PASS_VALUE" \
	"$NONADMIN_USER" "$NONADMIN_PASS" "$CONTACT_ID" "$REQUEST_ID" "$CONTRACT_ID" <<'PY'
import json, sys
(path, base, admin_user, admin_pass, nonadmin_user, nonadmin_pass,
 contact_id, request_id, contract_id) = sys.argv[1:10]

values = {
    # pipelinq-api.postman_collection.json
    'baseUrl': base,
    'username': admin_user,
    'password': admin_pass,
    'adminUsername': admin_user,
    'nonAdminUsername': nonadmin_user,
    'nonAdminPassword': nonadmin_pass,
    # outbound-messaging + semantic-handoff
    'ncUser': admin_user,
    'ncPass': admin_pass,
    'contactId': contact_id,
    'requestId': request_id,
    'contractId': contract_id,
    'unknownTemplateId': 'no-such-template',
}

with open(path, 'w') as fh:
    json.dump({
        'id': 'pipelinq-newman-ci',
        'name': 'Pipelinq Newman CI (generated by tests/newman/ci-seed.sh)',
        '_postman_variable_scope': 'environment',
        'values': [
            {'key': k, 'value': v, 'type': 'text', 'enabled': True}
            for k, v in values.items()
        ],
    }, fh, indent=2)
    fh.write('\n')
print(f'[newman-seed] wrote {path}')
PY

echo "[newman-seed] done."
