# CTI Adapter Setup

Pipelinq ships with a pluggable Computer Telephony Integration (CTI) adapter
that delivers inbound screen-pop, outbound click-to-dial, automatic
contactmoment creation and a disposition workflow against three platforms:

- **CallVoip** (Dutch SME market leader)
- **RingCentral** (international UCaaS)
- **Asterisk** (self-hosted PBX)

Custom platforms can be added by registering a class that implements
`OCA\Pipelinq\Service\Cti\CtiAdapterInterface` against the
`OCA\Pipelinq\Service\Cti\AdapterRegistry`.

## Spec

- OpenSpec change: `openspec/changes/cti-screenpop-adapter/`
- Issue: Codeberg issue #175 (pre-migration, not migrated to GitHub)

## High-level flow

```
Inbound call:
  Telephony platform ─POST webhook▶ /api/cti/webhook/{platform}
                                   └▶ adapter.verifyWebhookSignature(...)
                                   └▶ adapter.handleInboundWebhook(...) ─▶ CtiWebhookResult
                                   └▶ CtiService.handleWebhook(...) ─▶ event log + contactmoment

Outbound call:
  Vue UI ─POST /api/cti/click-to-dial─▶ CtiService.originateCall(...)
                                       └▶ adapter.originateCall(...) ─▶ platform REST API
                                       └▶ contactmoment (outbound, pending)
```

## Webhook URL

The platform should POST to:

```
POST https://<your-nextcloud>/index.php/apps/pipelinq/api/cti/webhook/<platform>
```

`<platform>` is one of `callvoip`, `ringcentral`, `asterisk`, or any custom
identifier registered in the AdapterRegistry.

## Configuration via admin UI

1. Navigate to **Pipelinq → Settings → CTI integration**.
2. Pick the platform, enter the API base URL, the OpenConnector credentials
   reference (the credentials themselves never live in Pipelinq), the screen-pop
   delay and the default outbound caller-ID.
3. Click **Test connection** to verify the adapter resolves and the
   configuration parses.

## Per-platform configuration keys

The following `IAppConfig` keys (under app id `pipelinq`) are read by the
adapters at runtime — set them via `occ config:app:set pipelinq <key> --value
'<value>'` (or via OpenConnector credential mapping):

| Adapter | Key | Notes |
|---|---|---|
| CallVoip | `cti_callvoip_api_base_url` | REST API base. |
| CallVoip | `cti_callvoip_api_key` | Bearer token. |
| CallVoip | `cti_callvoip_webhook_secret` | HMAC-SHA256 shared secret. |
| RingCentral | `cti_ringcentral_api_base_url` | e.g. `https://platform.ringcentral.com`. |
| RingCentral | `cti_ringcentral_access_token` | OAuth bearer. |
| RingCentral | `cti_ringcentral_webhook_token` | Validation-Token header value. |
| Asterisk | `cti_asterisk_api_base_url` | ARI base. |
| Asterisk | `cti_asterisk_ari_user` | ARI username. |
| Asterisk | `cti_asterisk_ari_pass` | ARI password. |
| Asterisk | `cti_asterisk_context` | Default `from-internal`. |
| Asterisk | `cti_asterisk_webhook_secret` | Shared secret query param. |
| All | `default_country_code` | ISO-3166 alpha-2; default `NL`. |
| All | `cti_escalation_queue` | Queue for `escalated` disposition tasks. |

## Webhook authentication per platform

- **CallVoip** — header `X-Pipelinq-Signature` = HMAC-SHA256(rawBody, webhook_secret).
- **RingCentral** — header `Validation-Token` = configured OAuth access token (constant-time compared).
- **Asterisk** — query parameter `signature=` = configured shared secret.

Adapter signature verification is fail-closed; an invalid signature is logged
to `ctiEventLog` and responds with `422 Unprocessable Entity` (the platform is
expected to retry on 5xx, not on 4xx, so signature failures do not snowball).

## Disposition outcomes

The Vue `CtiDispositionModal` collects subject + outcome + notes after every
call. Outcomes drive workflow:

| Outcome | Side-effect |
|---|---|
| `resolved` | Closes contactmoment. |
| `callback` | Creates a task of type `terugbelverzoek` (callback-management). |
| `escalated` | Creates a task of type `opvolgtaak` in `cti_escalation_queue`. |
| `wrong-number` / `no-answer` / `abandoned` | Closes contactmoment with notes. |

## Recording metadata

Audio files stay on the telephony platform. Pipelinq only stores the recording
URL and the platform-reported retention expiry on the contactmoment
(`recording_url`, `recording_retention_expires_at`).

## Event log

The CTI admin event log (`Pipelinq → Settings → CTI event log`) shows the last
30 days of received webhook events. Older events are purged by the
`OCA\Pipelinq\BackgroundJob\CtiEventLogCleanupJob` (runs daily).
