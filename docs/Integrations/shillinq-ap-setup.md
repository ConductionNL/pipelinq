<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->

# Shillinq AP Integration

Pipelinq dispatches approved expenses to [Shillinq](https://shillinq.nl) as
accounts-payable (AP) vouchers, one-way, through OpenRegister's
`WebhookService`. The integration is a fire-and-forget CloudEvents 1.0
publish: pipelinq is the source of truth for the expense, Shillinq is the
source of truth for the AP voucher and downstream ledger postings.

## Spec

- OpenSpec change: `openspec/changes/pipelinq-expense-to-shillinq-ap/`
- Requirements: `REQ-AP-001` through `REQ-AP-007`

## High-level flow

```
Pipelinq                                                Shillinq
────────                                                ────────

ExpenseDetail.vue
        │
        │ status = "approved"
        ▼
OpenRegister save ──▶ ObjectUpdatedEvent
                              │
                              ▼
                    ExpenseApprovalListener
                              │
                              ├── persist apSyncStatus = "pending"
                              ├── emit ExpenseApprovedEvent (in-process)
                              ▼
                    ShillinqApService.dispatchApEvent()
                              │
                              ▼
                    WebhookService ──HTTPS POST──▶  shillinq_ap_webhook_url
                              │                              │
                              │                              ▼
                              │                       Shillinq AP intake
                              │                       creates voucher
                              │
                              ▼
                    apSyncStatus = "synced" + apSyncedAt
                    (or "failed" + admin notification)
```

## Configuring the webhook URL

1. Open **Settings → Pipelinq → Integraties**
   (`/settings/admin/pipelinq`).
2. Set **Shillinq AP webhook URL** to the HTTPS endpoint provided by
   your Shillinq instance, e.g.
   `https://shillinq.example.com/api/ap/intake`.
3. Click **Save**. The value is persisted as the
   `pipelinq → shillinq_ap_webhook_url` app-config string.

Leaving the field empty disables the integration; approved expenses are
not dispatched and `apSyncStatus` stays `null` (REQ-AP-002 Scenario 6).

The field accepts only well-formed HTTPS URLs (`shouldDispatch()` returns
`false` for HTTP, malformed, or empty values).

## Webhook payload format

The webhook body is a [CloudEvents 1.0](https://cloudevents.io) envelope
with `application/json` content type. Example:

```json
{
  "specversion": "1.0",
  "type": "nl.conduction.pipelinq.expense.approved",
  "source": "/apps/pipelinq/expenses",
  "id": "<expense-uuid>",
  "time": "2026-05-15T14:30:00Z",
  "datacontenttype": "application/json",
  "data": {
    "expenseId": "<expense-uuid>",
    "amount": 185.50,
    "currency": "EUR",
    "categoryId": "accommodation",
    "clientId": "<client-uuid>",
    "projectId": "<project-uuid-or-null>",
    "billable": true,
    "approvedBy": "<approver-user-id>",
    "approvedAt": "2026-05-15T14:30:00Z"
  }
}
```

Notes for Shillinq consumers:

- `id` and `data.expenseId` are the same value: the pipelinq expense UUID.
  Use it as the idempotency key on the Shillinq side so a duplicate POST
  (caused by, for example, an OpenRegister webhook retry) does not create
  a second voucher.
- `projectId` is `null` when the expense is not project-linked.
- `billable` is the pipelinq billability flag; route the AP voucher to a
  billable cost-pass-through ledger when it is `true`.
- The webhook is **fire-and-forget**: respond with `HTTP 2xx` to mark the
  dispatch successful. Any non-2xx, network error or timeout causes
  pipelinq to mark the expense `apSyncStatus = "failed"` and notify
  administrators.

## Sync status on the expense

Two properties on the `expense` schema track the integration outcome
(REQ-AP-001):

| Property        | Value      | Meaning                                                                            |
|-----------------|------------|------------------------------------------------------------------------------------|
| `apSyncStatus`  | `pending`  | Listener has captured the approval; AP dispatch in flight.                         |
|                 | `synced`   | Webhook returned 2xx; `apSyncedAt` stamped with the UTC ISO 8601 dispatch time.    |
|                 | `failed`   | Webhook returned non-2xx or threw; administrators have been notified.              |
|                 | `null`     | Expense has not yet been approved, or the integration is unconfigured.             |
| `apSyncedAt`    | ISO 8601   | UTC timestamp of the last successful dispatch.                                     |

The expense list shows the status as a color-coded badge; the expense
detail view shows the same status plus a **Opnieuw versturen** button
when the status is `failed`.

## Retrying a failed sync manually

When `apSyncStatus = "failed"`:

1. Open the expense detail page.
2. Click **Opnieuw versturen** in the Shillinq AP card.
3. The frontend calls
   `POST /apps/pipelinq/api/v1/expenses/{id}/shillinq-ap/retry`. The
   endpoint is gated with `#[AuthorizedAdminSetting]`: only Nextcloud
   administrators can retry.
4. The card updates with the new status (synced or failed).

You can also call the endpoint directly:

```bash
curl -u admin:<password> \
  -X POST \
  -H 'OCS-APIRequest: true' \
  'https://nextcloud.example.com/index.php/apps/pipelinq/api/v1/expenses/<uuid>/shillinq-ap/retry'
```

The endpoint refuses to retry an expense whose `apSyncStatus` is not
`failed` (400) so an admin cannot accidentally double-post a `synced`
voucher.

## Troubleshooting

| Symptom                                                          | Likely cause                                                                                            |
|------------------------------------------------------------------|---------------------------------------------------------------------------------------------------------|
| Approved expense stays at `apSyncStatus = null`                  | `shillinq_ap_webhook_url` is empty or not HTTPS — set a valid URL in admin settings.                    |
| Expense stuck at `pending`                                       | Pipelinq has dispatched but Shillinq has not responded yet. Wait, then retry from the detail view.      |
| Expense shows `failed` immediately after approval                | The webhook URL is unreachable, returns non-2xx or times out. Check Shillinq logs and retry once fixed. |
| `Only failed AP syncs can be retried` (400)                      | The expense is already `synced`. Retrying would create a duplicate voucher; intentional.                |
| `Shillinq AP integration is not configured.` (400) on retry      | The webhook URL was cleared after the expense failed. Re-configure it and retry.                        |

## Related docs

- Developer architecture: `docs/developer/shillinq-ap-architecture.md`
- Backend listener: `lib/Listener/ExpenseApprovalListener.php`
- Backend service: `lib/Service/ShillinqApService.php`
- Retry endpoint: `lib/Controller/ShillinqApController.php`
