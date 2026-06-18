---
status: done
---

# POS Barcode Scan (HID + camera)

**Status**: active
**Capability**: pos-barcode-scan
**Schema.org mapping**: `schema:gtin` (barcode)

**OpenSpec changes**:
- `pos-barcode-scan` (archived 2026-05-31) — Initial: HID keyboard-wedge detection (rapid-burst + Enter, never hijacks normal typing) + camera `BarcodeDetector` scanning (feature-detected, graceful HID-only fallback) via the `useBarcodeScanner` composable; `useBarcodeProductLookup` resolving scanned barcodes through the IDOR-safe server endpoint; `ProductCatalogService::lookupByBarcode` extended to return a zero-based `variantIndex` and exclude inactive variants (top-level priority preserved); unified `BarcodeScanner.vue` wired into `ProductBarcodeSearch.vue`; nl+en i18n; 5 Dutch seed products with EAN-13 + variant barcodes.

---

**Feature tier:** P0-must
**Demand evidence:** 13/13 competitors
**Depends on:** pos-product-catalogue (`product.barcode`, `product.variants[].barcode`)

## Purpose

Define the requirements for barcode input capture and product lookup in the pipelinq POS context. This spec covers HID keyboard-wedge detection, BarcodeDetector API camera scanning, product resolution (including variant-level), and error handling. The implementation provides a composable scanning layer (`useBarcodeScanner`, `useBarcodeProductLookup`) and a unified `BarcodeScanner.vue` component.

@e2e exclude HID/camera scanning, composable scan logic, and server-authoritative barcode resolution have no deterministic Playwright surface (require physical scanner hardware, camera/BarcodeDetector device APIs, and a seeded product+variant fixture); covered by Vitest component tests and PHPUnit/Newman.

## Implementation notes (as built)

- The unified scanner is wired into **`src/views/products/ProductBarcodeSearch.vue`** — the design referenced `ProductList.vue`, which does not exist in this app. Scenarios written against `ProductList.vue` apply to `ProductBarcodeSearch.vue`.
- Barcode resolution is delegated to the merged, IDOR-safe server endpoint `POST /apps/pipelinq/api/products/barcode-lookup` (`ProductCatalogService`) rather than client-side `objectStore` enumeration. The extended service returns the matched product plus a zero-based `variantIndex` (null on a top-level match); the composable maps this to `{ product, variant, variantIndex, status }`.
- Scanned input is treated as untrusted: validated for length (≤ 64) and charset (`[A-Za-z0-9 .-]`) on both the client (`isValidBarcode`) and the server before any lookup.

---

## Requirements

### REQ-PBS-001: HID keyboard-wedge barcode input

The `useBarcodeScanner` composable MUST detect input from USB/Bluetooth HID keyboard-wedge barcode scanners and emit a `scan` event distinct from ordinary keyboard typing.

#### Scenario: HID scan is detected

- GIVEN `useBarcodeScanner` is mounted on a page
- WHEN a barcode scanner emits 13 characters in under 650 ms followed by Enter (keyCode 13)
- THEN `useBarcodeScanner` MUST emit `scan("8710919041022")`
- AND the keydown events MUST NOT propagate to other input fields as text

#### Scenario: Slow typing is NOT treated as a scan

- GIVEN `useBarcodeScanner` is mounted on a page
- WHEN a user types 8 characters with average inter-key delay > 100 ms
- THEN `useBarcodeScanner` MUST NOT emit a `scan` event
- AND the keystrokes MUST behave normally for other components

#### Scenario: Short input rejected as non-barcode

- GIVEN `useBarcodeScanner` is mounted
- WHEN the rapid-burst sequence contains fewer than 4 characters before Enter
- THEN NO `scan` event MUST be emitted (minimum barcode length guard)

#### Scenario: Buffer cleared on inactivity

- GIVEN a partial barcode sequence has been typed at scan speed (< 50 ms intervals)
- WHEN no further keystrokes arrive for 200 ms
- THEN the internal buffer MUST be cleared
- AND a subsequent new sequence starts fresh

#### Scenario: Composable cleaned up on unmount

- GIVEN `useBarcodeScanner` is used in a component that is subsequently unmounted
- THEN the global `keydown` event listener MUST be removed
- AND no `scan` events MUST fire after unmount

---

### REQ-PBS-002: Camera-based barcode scanning with BarcodeDetector API

When `BarcodeDetector` is available in the browser, `useBarcodeScanner` MUST support opening a camera stream and resolving barcodes using `BarcodeDetector.detect()`.

#### Scenario: Camera scan resolves EAN-13

- GIVEN `BarcodeDetector` is available (`'BarcodeDetector' in window` is true)
- AND the user clicks the camera button in `BarcodeScanner.vue`
- WHEN the camera viewfinder detects a valid EAN-13 barcode "8710919041022"
- THEN `useBarcodeScanner` MUST emit `scan("8710919041022")`
- AND the camera stream MUST stop automatically after a successful scan

#### Scenario: Camera scan resolves UPC-A

- GIVEN `BarcodeDetector` is available and camera is active
- WHEN the camera detects a UPC-A barcode "012345678905"
- THEN `useBarcodeScanner` MUST emit `scan("012345678905")`

#### Scenario: Camera closes after scan

- GIVEN camera mode is active
- WHEN a barcode is detected and `scan` is emitted
- THEN `BarcodeScanner.vue` MUST close the camera overlay automatically
- AND the `<video>` stream MUST be stopped (`stream.getTracks().forEach(t => t.stop())`)

#### Scenario: Camera closes on manual dismiss

- GIVEN camera mode is active with the overlay visible
- WHEN the user clicks the "Camera sluiten" button or presses Escape
- THEN the camera overlay MUST close and the stream MUST be stopped
- AND NO `scan` event MUST be emitted

---

### REQ-PBS-003: BarcodeDetector unavailability fallback

When `BarcodeDetector` is not supported by the browser, `BarcodeScanner.vue` MUST degrade gracefully to HID-only mode without errors.

#### Scenario: Camera button hidden when BarcodeDetector unavailable

- GIVEN the browser does not support `BarcodeDetector` (`'BarcodeDetector' in window` is false)
- WHEN `BarcodeScanner.vue` is rendered
- THEN the camera icon button MUST NOT be visible in the DOM
- AND the HID text input MUST be visible and functional

#### Scenario: No JavaScript error thrown

- GIVEN `BarcodeDetector` is not in `window`
- WHEN `useBarcodeScanner` initializes
- THEN NO TypeError or ReferenceError MUST be thrown
- AND the composable MUST operate in HID-only mode silently

#### Scenario: HID input remains fully functional in fallback mode

- GIVEN camera is unavailable
- WHEN a HID scanner emits a barcode via keyboard wedge
- THEN `useBarcodeScanner` MUST still emit `scan(barcode)` exactly as in REQ-PBS-001

---

### REQ-PBS-004: Product lookup by barcode

The `useBarcodeProductLookup` composable MUST resolve a scanned barcode string to a `product` object. Resolution is performed by the server-authoritative, IDOR-safe `POST /apps/pipelinq/api/products/barcode-lookup` endpoint (scoped to this app's register + product schema), and the (untrusted) scanned value MUST be validated for length + charset before any request is sent.

#### Scenario: Barcode matches a product directly

- GIVEN a `product` object exists with `barcode: "8710919041022"` (Shampoo Keratine)
- WHEN `lookupByBarcode("8710919041022")` is called
- THEN the composable MUST return `{ product: <Shampoo Keratine>, variant: null, variantIndex: null, status: 'found' }`

#### Scenario: Lookup is exact-match for barcode strings

- GIVEN a `product` exists with `barcode: "8710919041022"`
- WHEN `lookupByBarcode("8710919041022")` is called (exact match only; EAN barcodes are numeric)
- THEN the result MUST match that product
  (Numeric EAN barcodes do not require case normalisation; this scenario confirms no unnecessary case transformation occurs)

#### Scenario: No match returns not_found

- GIVEN no `product` exists with `barcode: "0000000000000"`
- WHEN `lookupByBarcode("0000000000000")` is called
- THEN the composable MUST return `{ product: null, variant: null, variantIndex: null, status: 'not_found' }`

#### Scenario: Malformed scan returns invalid

- GIVEN a scanned string fails the length/charset guard (control characters, > 64 chars, or empty)
- WHEN `lookupByBarcode(thatString)` is called
- THEN the composable MUST return `{ status: 'invalid' }` WITHOUT issuing a network request
- AND the caller MUST surface the "no product found" feedback

#### Scenario: Ambiguous match returns ambiguous

- GIVEN two `product` objects share the same barcode value (data integrity error in catalogue)
- WHEN `lookupByBarcode(thatBarcode)` is called
- THEN the composable MUST return `{ status: 'ambiguous' }`
- AND the caller MUST display the "Meerdere producten gevonden" error message

---

### REQ-PBS-005: Variant barcode resolution

When a direct product-level barcode match fails, the lookup MUST search `product.variants[].barcode` and return the parent product, the matched variant object, and its zero-based `variantIndex`. This resolution is performed server-side by `ProductCatalogService::matchProductByBarcode`.

#### Scenario: Variant barcode resolves to parent product and variant

- GIVEN a `product` "Haargel Flex Hold" with variant index 0 `{ sku: "HAR-GEL-002-75", barcode: "8714100247038", name: "75 ml" }`
- WHEN `lookupByBarcode("8714100247038")` is called
- THEN the lookup MUST return `{ product: <Haargel Flex Hold>, variant: { sku: "HAR-GEL-002-75", ... }, variantIndex: 0, status: 'found' }`

#### Scenario: Inactive variant is excluded from lookup

- GIVEN a variant exists with `barcode: "8714100247045"` and `status: "inactive"`
- WHEN `lookupByBarcode("8714100247045")` is called
- THEN the lookup MUST return `{ status: 'not_found' }`
  (Inactive variants MUST NOT appear in scan results to avoid selling discontinued items)

#### Scenario: Top-level barcode is checked before variant scan

- GIVEN a `product` A has `barcode: "8714100247021"` (top-level)
- AND a different `product` B has a variant with `barcode: "8714100247021"`
- WHEN `lookupByBarcode("8714100247021")` is called
- THEN product A MUST be returned (top-level match takes priority)
- AND variant scan of product B MUST NOT be performed (short-circuit on first match)

---

### REQ-PBS-006: User-facing scan feedback in the barcode search view

When `BarcodeScanner.vue` is integrated into `ProductBarcodeSearch.vue`, the view MUST provide clear Dutch-language feedback for all scan outcomes.

#### Scenario: Found product navigates to detail

- GIVEN `BarcodeScanner.vue` is active in `ProductBarcodeSearch.vue`
- WHEN `lookupByBarcode` returns `{ status: 'found', product: P }`
- THEN navigation to the product detail (`ProductDetail`, params `{ id: P.id }`) MUST occur
- AND when a variant matched, the `variant` query param MUST carry the matched variant SKU
- AND no error message MUST be displayed

#### Scenario: Not-found shows Dutch error

- GIVEN a scan returns `{ status: 'not_found' }` (or `{ status: 'invalid' }`)
- THEN the view MUST display `t('pipelinq', 'No product found for barcode {barcode}', { barcode })`
- AND the error MUST appear in the empty-state area of the view
- AND it MUST be dismissed automatically after 5 seconds or on the next scan

#### Scenario: Ambiguous match shows Dutch error

- GIVEN a scan returns `{ status: 'ambiguous' }`
- THEN the view MUST display `t('pipelinq', 'Multiple products found for barcode {barcode}', { barcode })`

#### Scenario: Loading state shown during lookup

- GIVEN a scan event is received
- WHEN `useBarcodeProductLookup` is awaiting a server response
- THEN `BarcodeScanner.vue` MUST display a spinner or loading indicator
- AND the HID text input MUST be disabled during the lookup to prevent double-scans

#### Scenario: i18n keys present in both locale files

- GIVEN `l10n/en.json` and `l10n/nl.json`
- THEN both files MUST contain all 8 translation keys defined in `design.md`
- AND no hardcoded Dutch or English strings MUST appear in Vue component templates
