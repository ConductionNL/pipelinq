## 1. The server prices each line

- [x] 1.1 `PosCartObjects`, `PosLinePricer` and `PosLinePricingListener`, registered for creating and updating objects

## 2. The server prices the open sale

- [x] 2.1 `PosCartAccess`, `PosCartRepricer`, `PosCartController::recalculate` and the route

## 3. The till shows the server's figures

- [x] 3.1 `src/services/posCart.js`; PosTransactionForm and PosLineItemRow store line changes and show the server's figures; the browser tax helpers leave `posTotals.js`

## 4. Tests

- [x] 4.1 PHPUnit `PosSaleGoldenTest` (figures recorded from development before the change), `PosCartAccessTest`, `PosLinePricingListenerTest`, `PosCartControllerTest`
- [x] 4.2 vitest `posCart.spec.js`; `posSaleBoardLook.spec.js` reads the server's totals
