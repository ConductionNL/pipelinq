# Spec: pos-stock-moved-event (delta)

## ADDED Requirements

### Requirement: A settled POS sale SHALL emit a typed stock-moved CloudEvent

When a `posTransaction` settles, pipelinq MUST emit a `PosStockMovedEvent`
(CloudEvents type `nl.pipelinq.pos.stock.moved`) carrying, per sold line,
the product's SKU (`productRef`), quantity sold, and unit, plus the
transaction id (`posTxnId`), `administrationId` and an emission timestamp.
The event MUST be dispatched from the same commit path as
`TenderPostedEvent` (inside `settleTransaction()`), and a dispatch failure
MUST NOT abort or roll back the settle transition.

#### Scenario: Settling a transaction emits one stock-moved event with all sold lines

- **GIVEN** a confirmed `posTransaction` with two sold lines referencing products with SKUs `SKU-1001` (qty 3) and `SKU-2002` (qty 1)
- **WHEN** the transaction is settled
- **THEN** pipelinq dispatches one `PosStockMovedEvent` whose CloudEvent payload's `lines` array contains both `{productRef: "SKU-1001", qty: 3}` and `{productRef: "SKU-2002", qty: 1}`, plus the transaction's `posTxnId` and `administrationId`

#### Scenario: A dispatch failure never blocks settlement

- **GIVEN** the event dispatcher throws on `PosStockMovedEvent` emission (e.g. no listener registered)
- **WHEN** the transaction is settled
- **THEN** `settleTransaction()` still returns the settled transaction, and the failure is logged, not propagated

#### Scenario: A line whose product has no resolvable SKU is still carried, not dropped

- **GIVEN** a sold line whose `product` ref does not resolve to a product with a non-empty `sku`
- **WHEN** the transaction is settled
- **THEN** the line still appears in the `PosStockMovedEvent` payload with an empty `productRef` — pipelinq never silently drops a line, leaving product-matching (and the audit surface) to the shillinq consumer
