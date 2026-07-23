# Change: pos-stock-moved-event

## Why

Shillinq (bookkeeping/inventory) needs to decrement on-hand stock and post
COGS the moment a pipelinq POS sale settles (shillinq issue #504,
`inventory-pos-decrement`). Today pipelinq's POS surface
(`posTransaction` / `posTransactionLine` / `PosTenderService`) is entirely
stock-unaware — its only cross-app event, `TenderPostedEvent`
(`nl.pipelinq.pos.tender.posted`), carries payment/GL data only, never
sold-item quantities. Without a producer event, shillinq's consumer would be
an orphaned listener.

## What changes

- Add a typed cross-app event `PosStockMovedEvent`
  (`nl.pipelinq.pos.stock.moved`), following the `TenderPostedEvent`
  conventions exactly (typed `IEventDispatcher::dispatchTyped()` +
  `WebhookService` fallback, CloudEvents 1.0 envelope).
- Dispatch it from `PosTransactionService::settleTransaction()` — the SAME
  commit path that already emits `TenderPostedEvent` per tender via
  `PosTenderService::emitTendersPosted()` — so a POS sale posts payment AND
  stock atomically. Emission is fire-and-forget: a downstream failure never
  aborts the settle path.
- The event carries, per sold line, the product's `sku` (resolved from the
  line's `product` ref against pipelinq's own `product` schema — the same
  SKU field the product-vendor-master ingest already syncs from shillinq,
  see `IngestProductVendorMaster`), quantity, and unit; plus
  `administrationId` (resolved the same way `TimeBillingHandoffService`
  resolves it, via `OCA\Shillinq\Service\AdministrationContextService`),
  the transaction UUID (`posTxnId`, the shared idempotency key) and an ISO
  timestamp.
- `location` is carried per line as a reserved (currently empty) field —
  pipelinq POS has no store/location concept yet; multi-location semantics
  are out of scope here (see shillinq's `inventory-pos-decrement` proposal).

## Out of scope

- The shillinq consumer (`PosStockDecrementListener`) — separate repo,
  `shillinq#504` / `openspec/changes/inventory-pos-decrement`.
- Multi-location stock, negative-stock policy, POS returns re-increment
  (pipelinq already emits `shillinq.StockMovement` webhook events for
  refund restock via `PosRefundService::emitStockMovementEvents()` — a
  separate, pre-existing mechanism, untouched by this change).
