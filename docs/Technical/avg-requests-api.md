<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->

# AVG-verzoeken API (GDPR data-subject requests)

REST endpoints for the AVG (Article 15/16/17/18/20) data-subject request
workflow. All paths are relative to `/index.php/apps/pipelinq/api`. Object CRUD
for the six AVG schemas (`avgVerzoek`, `termijnEvent`, `bewijsItem`,
`exportBundle`, `weigering`, `redactieActie`) is handled by OpenRegister's
generic object API; the endpoints below are the server-authoritative lifecycle
actions.

## Authorization

Every endpoint requires an authenticated user (`#[NoAdminRequired]`) except the
secure bundle download, which is a `#[PublicPage]` authenticated by a one-time
token. Access is scoped server-side (ADR-005):

- a **handler** sees and edits only the requests they are the `behandelaar` of;
- a **team lead** (`avg_teamlead_group`) sees and edits all requests;
- the **FG / DPO** (`avg_dpo_group`) reads all requests, exports AP dossiers and
  may override the retention guard;
- a **Nextcloud admin** has all of the above.

A request that belongs to another handler resolves to `403` (IDOR-safe).

## Requests

| Method | Path | Description |
|--------|------|-------------|
| `GET`    | `/avg-verzoeken` | List visible requests. Filters: `status`, `artikel`, `behandelaar`, `dpiaFlag`. |
| `POST`   | `/avg-verzoeken` | Register a request (intake). Body: `artikel`, `specifiekeVraag`, `scope[]`, `verzoekerNaam`, `verzoekerBsn`, `verzoekerBsnGeverifieerd`, `ingediendVia`. Computes the reference + 30-day legal deadline and records a receipt event. Ambiguous free-text intent → `422`. |
| `GET`    | `/avg-verzoeken/{id}` | Fetch one request. |
| `PATCH`  | `/avg-verzoeken/{id}` | Update handler fields (`behandelaar`, `status`, `scope`, `specifiekeVraag`, `verzoekerNaam`, `verzoekerContact`). Resolved/archived requests are read-only. |
| `DELETE` | `/avg-verzoeken/{id}` | Delete. Refused (`403`) while the 5-year retention window is active unless the caller is an FG/DPO. |
| `POST`   | `/avg-verzoeken/{id}/dpia-flag` | Manually flag for DPIA review. |
| `POST`   | `/avg-verzoeken/{id}/extend` | Grant a 60-day extension. Body: `verlengingsgrond` (≥30 chars). Only on/before day 30 and only once. Returns the request + a 4-eyes citizen email draft. |
| `POST`   | `/avg-verzoeken/{id}/archive` | Archive a resolved request and stamp `retentieTot` (resolution + 5 years). |

## Evidence

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/avg-verzoeken/{id}/collect-evidence` | Run a federated evidence-collection pass (OpenRegister + configured external sources). Unreachable sources yield a `bron-onbereikbaar` item, never an aborted run. Returns a per-source summary. |
| `GET`  | `/avg-verzoeken/{id}/evidence-status` | Collection status counts (`collected`, `failed`, `total`). |
| `GET`  | `/avg-verzoeken/{id}/bewijs-items` | List collected evidence with deduplication flagging. |

## Redaction

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/avg-verzoeken/{id}/redact` | Apply a field redaction. Body: `bewijsItemId`, `veldpad` (JSONPath), `grond`, `naWaarde`. Redacting the subject's own data without the `art-23-eigen-gegevens` ground → `422`. |
| `GET`  | `/avg-verzoeken/{id}/redaction-summary` | Before/after redaction list for 4-eyes review. |
| `POST` | `/avg-verzoeken/{id}/approve-redactions` | Approve the redaction set; advances the request to `bundle-genereren`. |

## Bundle & delivery

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/avg-verzoeken/{id}/generate-bundle` | Assemble + seal the export bundle (SHA-256 integrity hash; PAdES-LTV when a PKIoverheid cert + DocuDesk signer are wired, otherwise a sha256-manifest fallback). Returns the bundle metadata and the one-time download token (shown once). |
| `GET`  | `/export-bundles/{bundleId}` | Bundle metadata (token hash stripped). |
| `GET`  | `/export-bundles/{bundleId}/download?token={token}` | Public secure download. Validates the hashed token in constant time, enforces the link expiry, and records first-download receipt confirmation. Invalid/expired → `403`. |

## Denial (Weigering)

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/avg-verzoeken/{id}/deny` | Draft/update a denial. Body: `weigering` (`geheel`/`gedeeltelijk`), `grond` (art. 23), `toelichtingAvg23` (≥100 chars), `geweigerdeOnderdelen[]`, `verwijzingAp`. |
| `GET`  | `/avg-verzoeken/{id}/weigering` | Fetch the current denial. |
| `POST` | `/avg-verzoeken/{id}/finalize-denial` | Finalize + sign. Blocked (`422`) without a non-empty AP complaint reference URL. Returns the denial + a 4-eyes denial-letter draft. |

## AP escalation

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/avg-verzoeken/{id}/ap-escalate` | FG/DPO-only. Assemble the complete dossier (request + events + evidence + redactions + denial) for export to the AP. |

## Error codes

- `401` — not authenticated.
- `403` — access denied (foreign request, retention guard, DPO-only action, bad/expired download token).
- `404` — request/object not found in this app's schema.
- `422` — validation failure (ambiguous article, short motivation, missing AP reference, own-data redaction without grounds, extension not permitted).
- `500` — unexpected error (no stack trace is returned to the client).

## Configuration (admin)

Tunable via the admin-gated settings write path (`SettingsService::TUNABLE_DEFAULTS`):
`avg_dpia_threshold` (10), `avg_evidence_retention_days` (30),
`avg_download_validity_days` (30), `avg_pki_cert_path`, `avg_evidence_sources`
(comma-separated source ids), `avg_dpia_auto_procest` (`no`),
`avg_handler_group`, `avg_teamlead_group`, `avg_dpo_group`.
