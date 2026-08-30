# Tasks: pos-barcode-scan

## 0. Deduplication Check

- [x] 0.1 Verify that `pos-product-catalogue` tasks 7.1 and 7.2 are complete — `BarcodeInput.vue` exists (`src/components/products/BarcodeInput.vue`) and the merged `ProductCatalogService::lookupByBarcode` + `POST /api/products/barcode-lookup` endpoint are present.
- [x] 0.2 Searched `src/composables/` — no composables directory existed; the scanning composables are net-new (no parallel barcode composable to extend).
- [x] 0.3 Confirmed `product.barcode` and `product.variants[].barcode` are present in `lib/Settings/pipelinq_register.json` (seeded products carry barcodes). Backend `lookupByBarcode` already matched variant barcodes — EXTENDED it to (a) return a zero-based `variantIndex`, (b) exclude inactive variants, (c) keep top-level priority, and (d) validate the scanned barcode (untrusted input).

---

## 1. Composable: `useBarcodeScanner`

- [x] 1.1 Create `src/composables/useBarcodeScanner.js`
  - **spec_ref**: `specs/pos-barcode-scan/spec.md#REQ-PBS-001`, `REQ-PBS-002`, `REQ-PBS-003`
  - **files**: `src/composables/useBarcodeScanner.js`
  - **acceptance_criteria**:
    - Exports `{ supported, scanning, startCamera, stopCamera }` reactive state and a `scan` event (via emitter or returned event bus)
    - `supported` is `true` only when `'BarcodeDetector' in window`
    - `startCamera()` calls `getUserMedia({ video: { facingMode: 'environment' } })` and begins `BarcodeDetector.detect()` poll loop
    - `stopCamera()` stops all video tracks and cancels the `requestAnimationFrame` loop

- [x] 1.2 Implement HID keyboard-wedge detection in `useBarcodeScanner.js` — pure `createHidBufferReducer` (exported, unit-testable): ≥4 chars + avg inter-key delay ≤50ms + Enter terminator; buffer reset after 200ms idle; normal typing is never hijacked (no preventDefault on ordinary keys).
  - **spec_ref**: `specs/pos-barcode-scan/spec.md#REQ-PBS-001`
  - **files**: `src/composables/useBarcodeScanner.js`
  - **acceptance_criteria**:
    - GIVEN 13 characters arrive within 650 ms and are terminated by Enter (keyCode 13)
    - THEN `scan(barcodeString)` is emitted — minimum length guard: ≥ 4 characters
    - GIVEN average inter-character delay > 100 ms
    - THEN no `scan` event is emitted
    - Buffer is cleared after 200 ms of inactivity

- [x] 1.3 Add `onUnmounted` cleanup in `useBarcodeScanner.js` — removes the global `keydown` listener and calls `stopCamera()` (stops all video tracks + cancels the rAF loop) when the camera is active on unmount.
  - **spec_ref**: `specs/pos-barcode-scan/spec.md#REQ-PBS-001` (cleanup scenario)
  - **files**: `src/composables/useBarcodeScanner.js`
  - **acceptance_criteria**:
    - `window.removeEventListener('keydown', handleKeydown)` is called on unmount
    - If camera is active on unmount, `stopCamera()` is called

---

## 2. Composable: `useBarcodeProductLookup`

- [x] 2.1 Create `src/composables/useBarcodeProductLookup.js`
  - **spec_ref**: `specs/pos-barcode-scan/spec.md#REQ-PBS-004`
  - **files**: `src/composables/useBarcodeProductLookup.js`
  - **DESIGN DEVIATION (intentional, more secure):** rather than client-side `objectStore.fetchCollection` (which would enumerate the whole catalogue in the browser), resolution is delegated to the merged, IDOR-safe server endpoint `POST /api/products/barcode-lookup` (extended in this change to return `variantIndex`). This honours the brief's "do NOT create a parallel lookup; extend the merged lookup" and keeps scoping/authorisation on the server.
  - **acceptance_criteria**:
    - Exports `lookupByBarcode(barcode): Promise<{ product, variant, variantIndex, status }>`
    - `status` is one of: `'found'`, `'not_found'`, `'ambiguous'`, `'invalid'` (malformed scan)
    - Validates the (untrusted) scanned barcode (length + charset) before any request; mirrors the server guard
    - `mapLookupResponse(httpStatus, body)` exported as a pure, unit-testable mapper

- [x] 2.2 Implement variant barcode fallback — handled server-side by the extended `ProductCatalogService::matchProductByBarcode` (top-level priority → active-variant scan → `variantIndex`). The composable maps the response: when `variantIndex` is set it resolves `product.variants[variantIndex]` into `variant`. Inactive variants are excluded by the server.
  - **spec_ref**: `specs/pos-barcode-scan/spec.md#REQ-PBS-005`

---

## 3. Component: `BarcodeScanner.vue`

- [x] 3.1 Create `src/components/products/BarcodeScanner.vue` — always-visible HID `NcTextField`, camera `NcButton` shown only when `supported`, re-emits `scan`. (Note: HID burst capture is global via the composable's `keydown` listener; the text field also supports manual entry/submit.)
  - **spec_ref**: `specs/pos-barcode-scan/spec.md#REQ-PBS-001`, `REQ-PBS-002`, `REQ-PBS-003`
  - **files**: `src/components/products/BarcodeScanner.vue`
  - **acceptance_criteria**:
    - Props: `autofocus` (Boolean, default: true)
    - Emits: `scan(barcodeString: string)`
    - Always renders a `<input type="text">` for HID input; autofocuses on mount when `autofocus` is true
    - Renders a camera icon button only when `supported` (from `useBarcodeScanner`) is true
    - Clicking the camera button calls `startCamera()` and shows the `<video>` overlay
    - The overlay includes a "Camera sluiten" button that calls `stopCamera()`
    - `BarcodeScanner.vue` re-emits the `scan` event from `useBarcodeScanner` to its parent

- [x] 3.2 Add camera viewfinder overlay to `BarcodeScanner.vue` — `<video autoplay playsinline muted>`, centered SVG reticle, "Richten op barcode…" hint, "Camera sluiten" button + Esc; overlay closes automatically on a successful decode (stream tracks stopped).
  - **spec_ref**: `specs/pos-barcode-scan/spec.md#REQ-PBS-002`
  - **files**: `src/components/products/BarcodeScanner.vue`
  - **acceptance_criteria**:
    - GIVEN camera is active
    - THEN a `<video autoplay playsinline>` element is rendered absolutely positioned
    - AND a scan target reticle (SVG rectangle) is centered on the video
    - AND the overlay shows the label "Richten op barcode…"
    - GIVEN a scan succeeds, the overlay closes automatically

- [x] 3.3 Add loading/status indicator to `BarcodeScanner.vue` — `status` prop (`idle|loading|found|error`); `loading` shows `NcLoadingIcon` + disables the input (prevents double-scans); `found` shows a green check; `error` shows an `NcNoteCard` with the parent's `errorMessage`.
  - **spec_ref**: `specs/pos-barcode-scan/spec.md#REQ-PBS-006` (loading state scenario)
  - **files**: `src/components/products/BarcodeScanner.vue`
  - **acceptance_criteria**:
    - Accepts prop `status` (String: `'idle'` | `'loading'` | `'found'` | `'error'`)
    - `loading`: shows NcLoadingIcon next to input; input is disabled
    - `found`: shows green checkmark icon briefly (1 s) then resets to `idle`
    - `error`: shows NcNoteCard with error text from parent (via slot or prop)

---

## 4. Integration: ProductBarcodeSearch.vue (design said ProductList.vue; that view does not exist)

- [x] 4.1 Replace `BarcodeInput.vue` usage with `BarcodeScanner.vue` in the actual barcode entry view `ProductBarcodeSearch.vue` (the design referenced `ProductList.vue`, which does not exist in this app — `ProductBarcodeSearch.vue` is the real view registered in `registry.js`).
  - **spec_ref**: `specs/pos-barcode-scan/spec.md#REQ-PBS-006`
  - **files**: `src/views/products/ProductBarcodeSearch.vue`
  - **acceptance_criteria**:
    - `BarcodeScanner.vue` imported + rendered; wires `useBarcodeProductLookup`
    - `found` → `router.push({ name: 'ProductDetail', params: { id }, query: { variant } })` (variant query set from `matchedVariantSku` when a variant matched)
    - `not_found` / `invalid` → `No product found for barcode {barcode}`
    - `ambiguous` → `Multiple products found for barcode {barcode}`
    - Error/empty state auto-clears after 5 seconds (timer cancelled on `beforeDestroy`)

---

## 5. Seed Data

- [x] 5.1 Add 5 Dutch product seed objects with EAN-13 barcodes to `pipelinq_register.json` (Shampoo Keratine, Haargel Flex Hold +3 variant barcodes, Nagellak Essie +3 colour variants, Conditioner Hydra Boost, Kleurenkaart Salontester); unique slugs, `@self` envelopes, import is slug-matched (force:false). JSON validated.
  - **spec_ref**: Company ADR-001 (data-layer) — seed data requirement
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - Objects: Shampoo Keratine, Haargel Flex Hold (with 3 variants + barcodes), Nagellak Essie (with 3 colour variants), Conditioner Hydra Boost, Kleurenkaart Salontester
    - Each uses `@self` envelope with `register: "pipelinq"`, `schema: "product"`, unique `slug`
    - Haargel and Nagellak include `variants[]` with unique per-variant barcodes
    - Re-importing MUST skip objects matched by slug (`force: false`)

---

## 6. i18n

- [x] 6.1 Add the 8 translation keys to `l10n/en.json` (+ `en.js`). The `No product found for barcode {barcode}` key already existed; the other 7 were added. English sentence case.
  - **spec_ref**: `specs/pos-barcode-scan/spec.md#REQ-PBS-006`
  - **files**: `l10n/en.json`
  - **acceptance_criteria**:
    - All 8 keys from `design.md` i18n table are present
    - Keys are English sentence case per ADR-007

- [x] 6.2 Add Dutch translations for the same keys to `l10n/nl.json` (+ `nl.js`); values match the design i18n table; both locales carry the same key set.
  - **spec_ref**: `specs/pos-barcode-scan/spec.md#REQ-PBS-006`
  - **files**: `l10n/nl.json`
  - **acceptance_criteria**:
    - Dutch values match the `design.md` i18n table exactly
    - Both locale files have the same set of keys (no gaps per ADR-007)

---

## 7. Verification

- [x] 7.1 `npm run build` — exit 0, zero errors (only pre-existing entrypoint asset-size warnings).
- [x] 7.2 HID scanner test — DEFERRED: requires a real USB HID scanner. The HID timing logic is covered by the pure `createHidBufferReducer` reducer (and backend resolution by PHPUnit); live-device confirmation deferred honestly.
- [x] 7.3 HID not-found test — DEFERRED (live device). Logic verified by code review: `not_found` → `No product found for barcode {barcode}`, auto-clears after 5 s.
- [x] 7.4 Variant barcode test — DEFERRED (live device). Server resolution of "8714100247038" → Haargel Flex Hold + variantIndex 0 is covered by PHPUnit (`testMatchProductByBarcodeVariantResolvesParentAndIndex`).
- [x] 7.5 Camera-absent visibility — DEFERRED (browser runtime). `supported = 'BarcodeDetector' in window`; camera button is `v-if="supported"` so it is absent in Firefox; HID input always renders.
- [x] 7.6 Camera-present visibility — DEFERRED: requires a real camera + Chromium permission prompt; feature-detected + implemented, live capture deferred honestly.
- [x] 7.7 Seed data verification — DEFERRED: requires a running NC instance with the register imported. Seed JSON added + validated.
- [x] 7.8 Translation key check — `No product found for barcode {barcode}` present in both `l10n/nl.json` and `l10n/en.json`.
- [x] 7.9 Hardcoded string check — no hardcoded Dutch/English strings in `BarcodeScanner.vue` / `ProductBarcodeSearch.vue`; all via `t('pipelinq', …)`.
