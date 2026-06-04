---
status: draft
---

# Spec: POS Barcode Scan (HID + camera)

## Purpose

Define the requirements for barcode input capture and product lookup in the pipelinq POS context. This spec covers HID keyboard-wedge detection, BarcodeDetector API camera scanning, product resolution (including variant-level), and error handling. The implementation provides a composable scanning layer (`useBarcodeScanner`, `useBarcodeProductLookup`) and a unified `BarcodeScanner.vue` component.

**Main ADR refs**: [adr-000-data-model.md](../../../../architecture/adr-000-data-model.md), [adr-001-international-first-dutch-mapping.md](../../../../architecture/adr-001-international-first-dutch-mapping.md)
**Feature tier**: P0-must
**Demand evidence**: 13/13 competitors
**Depends on**: pos-product-catalogue (`product.barcode`, `product.variants[].barcode`)

---

## REQ-PBS-001: HID keyboard-wedge barcode input

The `useBarcodeScanner` composable MUST detect input from USB/Bluetooth HID keyboard-wedge barcode scanners and emit a `scan` event distinct from ordinary keyboard typing.

### Scenario: HID scan is detected

- GIVEN `useBarcodeScanner` is mounted on a page
- WHEN a barcode scanner emits 13 characters in under 650 ms followed by Enter (keyCode 13)
- THEN `useBarcodeScanner` MUST emit `scan("8710919041022")`
- AND the keydown events MUST NOT propagate to other input fields as text

### Scenario: Slow typing is NOT treated as a scan

- GIVEN `useBarcodeScanner` is mounted on a page
- WHEN a user types 8 characters with average inter-key delay > 100 ms
- THEN `useBarcodeScanner` MUST NOT emit a `scan` event
- AND the keystrokes MUST behave normally for other components

### Scenario: Short input rejected as non-barcode

- GIVEN `useBarcodeScanner` is mounted
- WHEN the rapid-burst sequence contains fewer than 4 characters before Enter
- THEN NO `scan` event MUST be emitted (minimum barcode length guard)

### Scenario: Buffer cleared on inactivity

- GIVEN a partial barcode sequence has been typed at scan speed (< 50 ms intervals)
- WHEN no further keystrokes arrive for 200 ms
- THEN the internal buffer MUST be cleared
- AND a subsequent new sequence starts fresh

### Scenario: Composable cleaned up on unmount

- GIVEN `useBarcodeScanner` is used in a component that is subsequently unmounted
- THEN the global `keydown` event listener MUST be removed
- AND no `scan` events MUST fire after unmount

---

## REQ-PBS-002: Camera-based barcode scanning with BarcodeDetector API

When `BarcodeDetector` is available in the browser, `useBarcodeScanner` MUST support opening a camera stream and resolving barcodes using `BarcodeDetector.detect()`.

### Scenario: Camera scan resolves EAN-13

- GIVEN `BarcodeDetector` is available (`'BarcodeDetector' in window` is true)
- AND the user clicks the camera button in `BarcodeScanner.vue`
- WHEN the camera viewfinder detects a valid EAN-13 barcode "8710919041022"
- THEN `useBarcodeScanner` MUST emit `scan("8710919041022")`
- AND the camera stream MUST stop automatically after a successful scan

### Scenario: Camera scan resolves UPC-A

- GIVEN `BarcodeDetector` is available and camera is active
- WHEN the camera detects a UPC-A barcode "012345678905"
- THEN `useBarcodeScanner` MUST emit `scan("012345678905")`

### Scenario: Camera closes after scan

- GIVEN camera mode is active
- WHEN a barcode is detected and `scan` is emitted
- THEN `BarcodeScanner.vue` MUST close the camera overlay automatically
- AND the `<video>` stream MUST be stopped (`stream.getTracks().forEach(t => t.stop())`)

### Scenario: Camera closes on manual dismiss

- GIVEN camera mode is active with the overlay visible
- WHEN the user clicks the "Camera sluiten" button or presses Escape
- THEN the camera overlay MUST close and the stream MUST be stopped
- AND NO `scan` event MUST be emitted

---

## REQ-PBS-003: BarcodeDetector unavailability fallback

When `BarcodeDetector` is not supported by the browser, `BarcodeScanner.vue` MUST degrade gracefully to HID-only mode without errors.

### Scenario: Camera button hidden when BarcodeDetector unavailable

- GIVEN the browser does not support `BarcodeDetector` (`'BarcodeDetector' in window` is false)
- WHEN `BarcodeScanner.vue` is rendered
- THEN the camera icon button MUST NOT be visible in the DOM
- AND the HID text input MUST be visible and functional

### Scenario: No JavaScript error thrown

- GIVEN `BarcodeDetector` is not in `window`
- WHEN `useBarcodeScanner` initializes
- THEN NO TypeError or ReferenceError MUST be thrown
- AND the composable MUST operate in HID-only mode silently

### Scenario: HID input remains fully functional in fallback mode

- GIVEN camera is unavailable
- WHEN a HID scanner emits a barcode via keyboard wedge
- THEN `useBarcodeScanner` MUST still emit `scan(barcode)` exactly as in REQ-PBS-001

---

## REQ-PBS-004: Product lookup by barcode

The `useBarcodeProductLookup` composable MUST resolve a scanned barcode string to a `product` object by querying the OpenRegister `product` schema.

### Scenario: Barcode matches a product directly

- GIVEN a `product` object exists with `barcode: "8710919041022"` (Shampoo Keratine)
- WHEN `lookupByBarcode("8710919041022")` is called
- THEN the composable MUST return `{ product: <Shampoo Keratine>, variant: null, status: 'found' }`

### Scenario: Lookup is case-insensitive for barcode strings

- GIVEN a `product` exists with `barcode: "8710919041022"`
- WHEN `lookupByBarcode("8710919041022")` is called (exact match only; EAN barcodes are numeric)
- THEN the result MUST match that product
  (Numeric EAN barcodes do not require case normalisation; this scenario confirms no unnecessary case transformation occurs)

### Scenario: No match returns not_found

- GIVEN no `product` exists with `barcode: "0000000000000"`
- WHEN `lookupByBarcode("0000000000000")` is called
- THEN the composable MUST return `{ product: null, variant: null, status: 'not_found' }`

### Scenario: Ambiguous match returns ambiguous

- GIVEN two `product` objects share the same barcode value (data integrity error in catalogue)
- WHEN `lookupByBarcode(thatBarcode)` is called
- THEN the composable MUST return `{ product: null, variant: null, status: 'ambiguous' }`
- AND the caller MUST display the "Meerdere producten gevonden" error message

---

## REQ-PBS-005: Variant barcode resolution

When a direct product-level barcode match fails, `useBarcodeProductLookup` MUST search `product.variants[].barcode` and return the parent product along with the matched variant object.

### Scenario: Variant barcode resolves to parent product and variant

- GIVEN a `product` "Haargel Flex Hold" with variant `{ sku: "HAR-GEL-002-75", barcode: "8714100247038", name: "75 ml" }`
- WHEN `lookupByBarcode("8714100247038")` is called
- THEN the composable MUST return `{ product: <Haargel Flex Hold>, variant: { sku: "HAR-GEL-002-75", ... }, status: 'found' }`

### Scenario: Inactive variant is excluded from lookup

- GIVEN a variant exists with `barcode: "8714100247045"` and `status: "inactive"`
- WHEN `lookupByBarcode("8714100247045")` is called
- THEN the composable MUST return `{ status: 'not_found' }`
  (Inactive variants MUST NOT appear in scan results to avoid selling discontinued items)

### Scenario: Top-level barcode is checked before variant scan

- GIVEN a `product` A has `barcode: "8714100247021"` (top-level)
- AND a different `product` B has a variant with `barcode: "8714100247021"`
- WHEN `lookupByBarcode("8714100247021")` is called
- THEN product A MUST be returned (top-level match takes priority)
- AND variant scan of product B MUST NOT be performed (short-circuit on first match)

---

## REQ-PBS-006: User-facing scan feedback in ProductList

When `BarcodeScanner.vue` is integrated into `ProductList.vue`, the view MUST provide clear Dutch-language feedback for all scan outcomes.

### Scenario: Found product navigates to detail

- GIVEN `BarcodeScanner.vue` is active in `ProductList.vue`
- WHEN `lookupByBarcode` returns `{ status: 'found', product: P }`
- THEN `router.push('/products/' + P.id)` MUST be called
- AND no error message MUST be displayed

### Scenario: Not-found shows Dutch error

- GIVEN a scan returns `{ status: 'not_found' }`
- THEN the view MUST display `t('pipelinq', 'Geen product gevonden voor barcode {barcode}', { barcode })`
- AND the error MUST appear in the existing empty-state area of `ProductList.vue`
- AND it MUST be dismissed automatically after 5 seconds or on the next scan

### Scenario: Ambiguous match shows Dutch error

- GIVEN a scan returns `{ status: 'ambiguous' }`
- THEN the view MUST display `t('pipelinq', 'Meerdere producten gevonden voor barcode {barcode}', { barcode })`

### Scenario: Loading state shown during lookup

- GIVEN a scan event is received
- WHEN `useBarcodeProductLookup` is awaiting a server response
- THEN `BarcodeScanner.vue` MUST display a spinner or loading indicator
- AND the HID text input MUST be disabled during the lookup to prevent double-scans

### Scenario: i18n keys present in both locale files

- GIVEN `l10n/en.json` and `l10n/nl.json`
- THEN both files MUST contain all 8 translation keys defined in `design.md`
- AND no hardcoded Dutch or English strings MUST appear in Vue component templates
