# Tasks: pos-stock-moved-event

## 1. Event + dispatch
- [x] Define `PosStockMovedEvent` (`nl.pipelinq.pos.stock.moved`), mirroring `TenderPostedEvent`.
- [x] Resolve each sold line's `product` ref to its `sku` + `unit` via the `product` schema.
- [x] Resolve `administrationId` via `OCA\Shillinq\Service\AdministrationContextService` (soft cross-app dep, fallback `'default'`).
- [x] Dispatch from `PosTransactionService::settleTransaction()`, same commit path as `emitTendersPosted()`; fire-and-forget (never aborts settle).

## 2. Tests
- [x] Unit test: event `toCloudEvent()` shape.
- [x] Unit test: settle emits one `PosStockMovedEvent` with all sold lines mapped to sku/qty/unit.
- [x] Unit test: settle still succeeds when the event dispatch throws (fire-and-forget).
- [x] Unit test: a line whose product has no resolvable sku still appears in the payload (empty productRef) — shillinq audits it, pipelinq does not drop it.

## 3. Verification
- [x] `composer.phar install` + `vendor/bin/phpunit -c phpunit-unit.xml --no-coverage` green.
- [ ] Live end-to-end with shillinq's `PosStockDecrementListener` (tracked on the shillinq side, `inventory-pos-decrement`).
