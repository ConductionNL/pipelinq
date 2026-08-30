<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->

# Shillinq AP integration — developer architecture

This page documents the event-driven flow that publishes approved pipelinq
expenses to Shillinq as AP vouchers, and the contracts a Shillinq consumer
(or any other downstream subscriber) MUST honour.

## Spec

- OpenSpec change: `openspec/changes/pipelinq-expense-to-shillinq-ap/`
- Requirements: `REQ-AP-001` through `REQ-AP-007`
- Admin guide: `docs/Integrations/shillinq-ap-setup.md`

## Components

| Component                                            | Role                                                                              |
|------------------------------------------------------|-----------------------------------------------------------------------------------|
| `lib/Listener/ExpenseApprovalListener.php`           | Listens to OR `ObjectCreatedEvent` / `ObjectUpdatedEvent`, detects expense        |
|                                                      | objects transitioning to `status="approved"`, persists `apSyncStatus="pending"`,  |
|                                                      | re-emits a domain `ExpenseApprovedEvent`, calls `ShillinqApService::dispatch...`. |
| `lib/Event/ExpenseApprovedEvent.php`                 | Domain event the listener fans out so downstream consumers can subscribe          |
|                                                      | without coupling to OpenRegister's internal events.                               |
| `lib/Service/ShillinqApService.php`                  | Builds the CloudEvents 1.0 envelope, delegates to OR `WebhookService`,            |
|                                                      | exposes `shouldDispatch()` / `dispatchApEvent()` / `now()`.                       |
| `lib/Service/ApSyncNotifier.php`                     | Wraps NC notification dispatch for the "AP sync failed" admin notification.       |
| `lib/Controller/ShillinqApController.php`            | `#[AuthorizedAdminSetting]` retry endpoint reachable from the expense detail UI.  |
| `lib/Settings/register.d/30-expense-shillinq-ap.json`| ADR-037 register fragment that extends `expense` with `apSyncStatus` /            |
|                                                      | `apSyncedAt` and seeds the 5 AP-status demo objects.                              |
| `src/views/expenses/ExpenseList.vue`                 | Renders the `apSyncStatus` column with color-coded badges (REQ-AP-005).           |
| `src/components/ExpenseShillinqApCard.vue`           | Expense-detail card with status + manual-retry button (REQ-AP-006).               |

## Event flow

```text
OpenRegister save(expense)
        │
        ▼
ObjectCreatedEvent / ObjectUpdatedEvent
        │
        ▼   (registered in lib/AppInfo/Application.php)
ExpenseApprovalListener::handle()
        │
        │ guards:
        │   - is expense schema?
        │   - status === "approved"?
        │   - apSyncStatus !== "synced" (idempotent — REQ-AP-002 Scenario 5)
        │   - apService->shouldDispatch() (REQ-AP-002 Scenario 6)
        │
        ▼
IEventDispatcher::dispatchTyped(ExpenseApprovedEvent)
        │
        ▼
ObjectService::saveObject({...expense, apSyncStatus: "pending"})
        │
        ▼
ShillinqApService::dispatchApEvent(expense, approvedBy, approvedAt)
        │
        │ - builds CloudEvents 1.0 envelope
        │ - calls OR WebhookService::dispatchEvent($e, EVENT_EXPENSE_APPROVED, payload)
        │
        ├── on success ─▶ ObjectService::saveObject({apSyncStatus: "synced", apSyncedAt: now()})
        │
        └── on failure ─▶ ObjectService::saveObject({apSyncStatus: "failed"})
                        ApSyncNotifier::notifyFailure(title, uuid)
```

The listener catches every `Throwable` and only logs — the originating
expense write MUST never fail because the AP integration is unhealthy
(REQ-AP-002, "MUST NOT block other handlers").

## CloudEvents 1.0 payload contract

`ShillinqApService::EVENT_EXPENSE_APPROVED` is
`nl.conduction.pipelinq.expense.approved`. Source is
`/apps/pipelinq/expenses`. The envelope shape is asserted by
`ShillinqApServiceTest` and `ExpenseApSyncTest`.

```jsonc
{
  "specversion": "1.0",
  "type": "nl.conduction.pipelinq.expense.approved",
  "source": "/apps/pipelinq/expenses",
  "id": "<expense-uuid>",
  "time": "<ISO 8601 UTC>",
  "datacontenttype": "application/json",
  "data": {
    "expenseId": "<expense-uuid>",
    "amount":    185.50,                  // number, in `data.currency`
    "currency":  "EUR",                   // ISO 4217
    "categoryId":"accommodation",         // free-form category slug
    "clientId":  "<client-uuid>",
    "projectId": "<project-uuid|null>",
    "billable":  true,                    // boolean
    "approvedBy":"<approver-user-id>",
    "approvedAt":"<ISO 8601 UTC>"
  }
}
```

`id` and `data.expenseId` are intentionally the same pipelinq expense UUID
so Shillinq can use the CloudEvents `id` as the natural idempotency key.

## Subscribing in-process to `ExpenseApprovedEvent`

Other pipelinq subsystems (reporting, audit, automation) MAY subscribe to
the domain event directly without going through the AP webhook:

```php
use OCA\Pipelinq\Event\ExpenseApprovedEvent;

$container->get(\OCP\EventDispatcher\IEventDispatcher::class)
    ->addListener(
        ExpenseApprovedEvent::class,
        function (ExpenseApprovedEvent $event): void {
            $uuid       = $event->getExpenseUuid();
            $expense    = $event->getExpense();
            $approvedBy = $event->getApprovedBy();
            $approvedAt = $event->getApprovedAt();
            // ...do work...
        },
    );
```

The event carries the full expense payload so subscribers don't need to
re-fetch it from OpenRegister.

## Manual retry endpoint

`POST /apps/pipelinq/api/v1/expenses/{id}/shillinq-ap/retry` is gated with
`#[AuthorizedAdminSetting(Application::APP_ID)]` and MUST only be called
for expenses whose `apSyncStatus = "failed"` (REQ-AP-003 Scenario 11).
The controller refuses retries for any other state to prevent duplicate
vouchers.

Response shapes:

- `200 { "apSyncStatus": "synced",  "apSyncedAt": "<iso>" }` on success.
- `502 { "apSyncStatus": "failed",  "error": "AP dispatch failed." }` when
  the webhook rejects the retry.
- `400 { "error": "Only failed AP syncs can be retried.", ... }` when the
  expense is not in `failed` state.
- `400 { "error": "Shillinq AP integration is not configured." }` when
  the webhook URL is empty.
- `404 { "error": "Expense not found." }` when the UUID does not resolve.

## Test layers

| Layer        | File                                                      | Coverage                                                      |
|--------------|-----------------------------------------------------------|---------------------------------------------------------------|
| Unit         | `tests/Unit/Listener/ExpenseApprovalListenerTest.php`     | Listener guards, idempotency, success/failure persistence.    |
| Unit         | `tests/Unit/Service/ShillinqApServiceTest.php`            | `shouldDispatch()`, CloudEvents payload shape, dispatch path. |
| Integration  | `tests/Integration/ExpenseApSyncTest.php`                 | Full approval-to-sync flow with the real AP service wired.    |
| E2E (Gate-19)| `tests/e2e/spec-coverage/expense-shillinq-ap.spec.ts`     | Admin settings field, expense list mount, detail-route mount. |

Backend dispatch is not exercised end-to-end through the browser: that
would require a live Shillinq consumer at the configured webhook URL.

## Related docs

- Admin guide: [`docs/Integrations/shillinq-ap-setup.md`](../Integrations/shillinq-ap-setup.md)
- Spec: [`openspec/changes/pipelinq-expense-to-shillinq-ap/specs.md`](../../openspec/changes/pipelinq-expense-to-shillinq-ap/specs.md)
- Sibling Shillinq integration: [`lib/Service/ShillinqLedgerService.php`](../../lib/Service/ShillinqLedgerService.php)
- Pattern reference: ADR-022 (event-driven cross-app integration)
