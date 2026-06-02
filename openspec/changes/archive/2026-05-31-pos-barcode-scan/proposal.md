# Proposal: pos-barcode-scan

## Problem

POS product lookup in pipelinq is text-only. Market intelligence covering 13/13 sampled competitors shows that barcode scanning is a universal, expected POS input method:

1. **No HID keyboard-wedge support** — USB and Bluetooth barcode scanners operate as keyboard wedge devices, emitting a barcode string terminated by Enter/CR within ≈50 ms. There is no component in pipelinq that distinguishes this rapid-burst input pattern from normal typing and routes it to a product lookup.
2. **No camera-based scanning** — The browser `BarcodeDetector` API (supported in Chromium, Chrome for Android) enables tablet and laptop cameras to read barcodes natively without a library. No existing view uses this API for product lookup.
3. **No variant-level barcode resolution** — The `pos-product-catalogue` change added per-variant barcodes (`product.variants[].barcode`). There is no lookup path that resolves a variant barcode to its parent product and variant index, so scanning a size-specific T-shirt barcode yields no result even though the data is present.
4. **No unified scan-to-product flow** — Camera icon, text input, and HID input are treated separately in different views, creating an inconsistent operator experience. Competitors (Shopify, Square, Odoo, Unicenta) provide a single unified scanning interface across checkout, product management, and inventory contexts.

Without barcode scanning, POS operators must type product names or SKUs manually for every checkout line item — increasing transaction time, error rate, and operator friction.

## Solution

Implement a composable barcode scanning layer consisting of:

1. **`useBarcodeScanner` composable** — Provides two input modes unified under a single `scan(barcode)` event:
   - *HID mode*: captures keystrokes accumulating faster than 100 ms/character, treats the sequence as a barcode when terminated by Enter/CR (minimum 4 characters). Ignores sequences that take longer (normal typing).
   - *Camera mode*: opens a `<video>` stream and polls `BarcodeDetector.detect()` at 10 fps; emits `scan` on first stable decode. Falls back to HID-only when `BarcodeDetector` is not available in the browser.

2. **`useBarcodeProductLookup` composable** — Resolves a barcode string to a pipelinq `product`:
   - Queries `objectStore.fetchCollection('product', { barcode })` for top-level product match.
   - On no match, fetches all products with non-empty `variants` and checks `variant.barcode` against the scanned string client-side; returns parent product and matched variant object.
   - Returns `{ product, variant | null, status: 'found' | 'not_found' | 'ambiguous' }`.

3. **`BarcodeScanner.vue` component** — Wraps `useBarcodeScanner` with visual UI: a compact text input (always visible for HID), a camera toggle button (shown only when `BarcodeDetector` is supported), a live camera viewfinder overlay, and a scan-status indicator (spinner, checkmark, or error icon). Emits `scan(barcodeString)`.

4. **Integration into ProductList** — Replaces the basic barcode input in `ProductList.vue` with `BarcodeScanner.vue` so that scanning on the product list view navigates directly to the matched product, with clear empty-state feedback when no product is found.

## Scope

- `useBarcodeScanner.js` — HID timing detection + BarcodeDetector camera composable
- `useBarcodeProductLookup.js` — barcode → product + variant resolver
- `BarcodeScanner.vue` — unified input component (HID + camera, fallback-safe)
- Replace existing `BarcodeInput.vue` stub in `ProductList.vue` with `BarcodeScanner.vue`
- i18n keys for all scan feedback messages (Dutch + English)
- Seed data: 5 product objects with realistic EAN-13 barcodes

## Out of scope

- Barcode label printing (DYMO/Zebra) — separate change
- Inventory / stock-level display on scan
- Loyalty card or gift card barcode scanning
- GS1-128 / DataMatrix / QR code formats (EAN-13 and UPC-A only in V1)
- Offline barcode cache
- POS checkout cart integration — covered by `pos-transaction-core`

## Success Criteria

- A USB HID barcode scanner connected to any desktop or laptop triggers a product lookup within 300 ms of the Enter keystroke
- A Chromium-based browser with camera access resolves an EAN-13 barcode via `BarcodeDetector` in under 2 seconds
- Scanning a variant barcode (e.g. "8712345600002" for T-shirt S Wit) returns the parent product with the matching variant identified
- When no product matches the scanned barcode, the Dutch error message "Geen product gevonden voor barcode {barcode}" is shown
- `BarcodeScanner.vue` renders correctly in browsers where `BarcodeDetector` is unavailable: camera button is hidden, HID text input remains functional
- `npm run build` produces zero errors after all changes
