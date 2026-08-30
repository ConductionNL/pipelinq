---
kind: code
---

## Why

`BerichtenboxAdminController::stats()` (`lib/Controller/BerichtenboxAdminController.php:117-146`)
answers `GET /api/admin/berichtenbox/stats` — the admin-only delivery-counter dashboard for the
Berichtenbox bridge — with an **unbounded** `ObjectService::findAll()` call:

```php
$rows = $this->getObjectService()->findAll(
    config: [
        'filters' => [
            'register' => $register,
            'schema'   => $schema,
        ],
    ]
);
```

No `'limit'` key is passed. Tracing the call into OpenRegister confirms this is not a safe
default: `ObjectService::findAll()` (`openregister/lib/Service/ObjectService.php:787-825`) passes
`limit: $config['limit'] ?? null` straight through to the mapper — `null` means "no LIMIT clause",
i.e. a full-table scan of every `berichtenboxMessage` row ever created for the tenant, rendered in
full (`renderObjectsAsync`) and returned to PHP just to be walked by `tally()` and thrown away as
six integers. Every gemeente running the Berichtenbox bridge for any length of time accumulates
one row per outbound status push + inbound reply (REQ-OUTBOUND-001 / REQ-MAILBOX in the archived
`burgerportaal-mijnoverheid-bridge` spec), so this scan grows unbounded with tenant age and there
is no cache — the admin stats panel re-runs it on every page load/poll.

The same controller has **zero test coverage**: `find tests -iname "*BerichtenboxAdmin*"` returns
nothing, while the sibling `BerichtenboxWebhookController`, `BerichtenboxService`, and the
`BerichtenboxIntegrationTest` are all covered. `stats()` and `retry()` (which mutates a message's
`deliveryStatus` back to `queued`, admin-gated per the class docblock) currently ship with no
regression protection at all — a "phantom green" gap: CI reports green because nothing asserts
this controller's behavior, not because it is verified.

## What Changes

- **Aggregate server-side instead of loading every row.** Replace the unbounded `findAll()` +
  in-PHP `tally()` with either (a) a bounded, paginated `findAll()` loop accumulating counts in
  fixed-size batches (e.g. `limit: 500` with an `offset` cursor), or (b) an OpenRegister facet/
  count-by-field aggregation if the installed `ObjectService` version exposes one for the
  `deliveryStatus` field — whichever avoids materializing the full result set in memory for tally
  purposes. `unread` counting keeps the same semantics as today's `tally()` helper.
- **Add unit test coverage** for `BerichtenboxAdminController`: `stats()` returns the expected
  counter shape from a stubbed/faked `ObjectService`, `stats()` degrades gracefully when
  `register`/`schema` config is empty (existing early-return branch), `stats()` surfaces a 500 on
  a thrown `ObjectService` failure (existing catch branch), and `retry()` resets
  `retryCount`/`nextRetryAt`/`deliveryStatus` and 500s on a save failure (existing catch branch).
  All four branches exist today and are entirely unverified.
- No route, auth posture, or response shape change — `stats()` and `retry()` keep their existing
  JSON contracts; this is a data-access + test-coverage change, not a behavior change.

## Impact

- `lib/Controller/BerichtenboxAdminController.php` — bounded/aggregated `stats()` query.
- New `tests/Unit/Controller/BerichtenboxAdminControllerTest.php`.
- No spec-visible behavior change for admins (same counters, same shape); the delta below records
  the previously-unwritten requirement so the bounded-query invariant and the test obligation are
  traceable going forward.
