# Tasks: pos-barcode-scan

## 0. Deduplication Check

- [x] 0.1 Verify that `pos-product-catalogue` tasks 7.1 and 7.2 are complete — `BarcodeInput.vue` exists (`src/components/products/BarcodeInput.vue`) and the merged `ProductCatalogService::lookupByBarcode` + `POST /api/products/barcode-lookup` endpoint are present.
- [x] 0.2 Searched `src/composables/` — no composables directory existed; the scanning composables are net-new (no parallel barcode composable to extend).
- [x] 0.3 Confirmed `product.barcode` and `product.variants[].barcode` are present in `lib/Settings/pipelinq_register.json` (seeded products carry barcodes). Backend `lookupByBarcode` already matched variant barcodes — EXTENDED it to (a) return a zero-based `variantIndex`, (b) exclude inactive variants, (c) keep top-level priority, and (d) validate the scanned barcode (untrusted input).

---

## 1. Composable: `useBarcodeScanner`

- [ ] 1.1 Create `src/composables/useBarcodeScanner.js`
  - **spec_ref**: `specs/pos-barcode-scan/spec.md#REQ-PBS-001`, `REQ-PBS-002`, `REQ-PBS-003`
  - **files**: `src/composables/useBarcodeScanner.js`
  - **acceptance_criteria**:
    - Exports `{ supported, scanning, startCamera, stopCamera }` reactive state and a `scan` event (via emitter or returned event bus)
    - `supported` is `true` only when `'BarcodeDetector' in window`
    - `startCamera()` calls `getUserMedia({ video: { facingMode: 'environment' } })` and begins `BarcodeDetector.detect()` poll loop
    - `stopCamera()` stops all video tracks and cancels the `requestAnimationFrame` loop

- [ ] 1.2 Implement HID keyboard-wedge detection in `useBarcodeScanner.js`
  - **spec_ref**: `specs/pos-barcode-scan/spec.md#REQ-PBS-001`
  - **files**: `src/composables/useBarcodeScanner.js`
  - **acceptance_criteria**:
    - GIVEN 13 characters arrive within 650 ms and are terminated by Enter (keyCode 13)
    - THEN `scan(barcodeString)` is emitted — minimum length guard: ≥ 4 characters
    - GIVEN average inter-character delay > 100 ms
    - THEN no `scan` event is emitted
    - Buffer is cleared after 200 ms of inactivity

- [ ] 1.3 Add `onUnmounted` cleanup in `useBarcodeScanner.js`
  - **spec_ref**: `specs/pos-barcode-scan/spec.md#REQ-PBS-001` (cleanup scenario)
  - **files**: `src/composables/useBarcodeScanner.js`
  - **acceptance_criteria**:
    - `window.removeEventListener('keydown', handleKeydown)` is called on unmount
    - If camera is active on unmount, `stopCamera()` is called

---

## 2. Composable: `useBarcodeProductLookup`

- [ ] 2.1 Create `src/composables/useBarcodeProductLookup.js`
  - **spec_ref**: `specs/pos-barcode-scan/spec.md#REQ-PBS-004`
  - **files**: `src/composables/useBarcodeProductLookup.js`
  - **acceptance_criteria**:
    - Exports `lookupByBarcode(barcode: string): Promise<{ product, variant, status }>`
    - `status` is one of: `'found'`, `'not_found'`, `'ambiguous'`
    - Direct product match: `objectStore.fetchCollection('product', { barcode })` — if `total === 1`, return `{ product, variant: null, status: 'found' }`
    - If `total > 1`, return `{ product: null, variant: null, status: 'ambiguous' }`
    - If `total === 0`, proceed to variant scan (task 2.2)

- [ ] 2.2 Implement variant barcode fallback in `useBarcodeProductLookup.js`
  - **spec_ref**: `specs/pos-barcode-scan/spec.md#REQ-PBS-005`
  - **files**: `src/composables/useBarcodeProductLookup.js`
  - **acceptance_criteria**:
    - Fetches products (limit 200) and iterates `product.variants` client-side
    - Skips variants with `status !== 'active'`
    - Returns first matching `{ product, variant, status: 'found' }` or `{ product: null, variant: null, status: 'not_found' }`

---

## 3. Component: `BarcodeScanner.vue`

- [ ] 3.1 Create `src/components/products/BarcodeScanner.vue`
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

- [ ] 3.2 Add camera viewfinder overlay to `BarcodeScanner.vue`
  - **spec_ref**: `specs/pos-barcode-scan/spec.md#REQ-PBS-002`
  - **files**: `src/components/products/BarcodeScanner.vue`
  - **acceptance_criteria**:
    - GIVEN camera is active
    - THEN a `<video autoplay playsinline>` element is rendered absolutely positioned
    - AND a scan target reticle (SVG rectangle) is centered on the video
    - AND the overlay shows the label "Richten op barcode…"
    - GIVEN a scan succeeds, the overlay closes automatically

- [ ] 3.3 Add loading/status indicator to `BarcodeScanner.vue`
  - **spec_ref**: `specs/pos-barcode-scan/spec.md#REQ-PBS-006` (loading state scenario)
  - **files**: `src/components/products/BarcodeScanner.vue`
  - **acceptance_criteria**:
    - Accepts prop `status` (String: `'idle'` | `'loading'` | `'found'` | `'error'`)
    - `loading`: shows NcLoadingIcon next to input; input is disabled
    - `found`: shows green checkmark icon briefly (1 s) then resets to `idle`
    - `error`: shows NcNoteCard with error text from parent (via slot or prop)

---

## 4. Integration: ProductList.vue

- [ ] 4.1 Replace `BarcodeInput.vue` usage in `ProductList.vue` with `BarcodeScanner.vue`
  - **spec_ref**: `specs/pos-barcode-scan/spec.md#REQ-PBS-006`
  - **files**: `src/views/products/ProductList.vue`
  - **acceptance_criteria**:
    - `BarcodeScanner.vue` is imported and rendered in the product list search area
    - On `@scan` event: call `useBarcodeProductLookup.lookupByBarcode(barcode)`
    - `status: 'found'` → `router.push('/products/' + product.id)`
    - `status: 'not_found'` → show `t('pipelinq', 'Geen product gevonden voor barcode {barcode}', { barcode })`
    - `status: 'ambiguous'` → show `t('pipelinq', 'Meerdere producten gevonden voor barcode {barcode}', { barcode })`
    - Error messages clear after 5 seconds

---

## 5. Seed Data

- [ ] 5.1 Add 5 Dutch product seed objects with EAN-13 barcodes to `pipelinq_register.json`
  - **spec_ref**: Company ADR-001 (data-layer) — seed data requirement
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - Objects: Shampoo Keratine, Haargel Flex Hold (with 3 variants + barcodes), Nagellak Essie (with 3 colour variants), Conditioner Hydra Boost, Kleurenkaart Salontester
    - Each uses `@self` envelope with `register: "pipelinq"`, `schema: "product"`, unique `slug`
    - Haargel and Nagellak include `variants[]` with unique per-variant barcodes
    - Re-importing MUST skip objects matched by slug (`force: false`)

---

## 6. i18n

- [ ] 6.1 Add 8 new translation keys to `l10n/en.json`
  - **spec_ref**: `specs/pos-barcode-scan/spec.md#REQ-PBS-006`
  - **files**: `l10n/en.json`
  - **acceptance_criteria**:
    - All 8 keys from `design.md` i18n table are present
    - Keys are English sentence case per ADR-007

- [ ] 6.2 Add Dutch translations for the same 8 keys to `l10n/nl.json`
  - **spec_ref**: `specs/pos-barcode-scan/spec.md#REQ-PBS-006`
  - **files**: `l10n/nl.json`
  - **acceptance_criteria**:
    - Dutch values match the `design.md` i18n table exactly
    - Both locale files have the same set of keys (no gaps per ADR-007)

---

## 7. Verification

- [ ] 7.1 Run `npm run build` in the pipelinq app directory — MUST produce zero errors
- [ ] 7.2 HID scanner test: connect a USB barcode scanner; open ProductList; scan barcode "8710919041022" → confirm navigation to Shampoo Keratine product detail
- [ ] 7.3 HID not-found test: scan barcode "0000000000000" → confirm Dutch error "Geen product gevonden voor barcode 0000000000000" appears and clears after 5 s
- [ ] 7.4 Variant barcode test: scan "8714100247038" → confirm navigation to Haargel Flex Hold product detail
- [ ] 7.5 Camera button visibility test: open ProductList in Firefox (no BarcodeDetector) → confirm camera button is absent, HID input is visible
- [ ] 7.6 Camera button visibility test: open ProductList in Chrome → confirm camera button is visible; clicking it requests camera permission
- [ ] 7.7 Seed data verification: navigate to Producten, confirm 5 new seed products appear with correct `barcode` values
- [ ] 7.8 Run translation key check: `grep -n "Geen product gevonden" l10n/nl.json l10n/en.json` → both files MUST contain the key
- [ ] 7.9 Run hardcoded string check: `grep -n "Geen product\|Camera openen\|Barcode scannen" src/components/products/BarcodeScanner.vue src/views/products/ProductList.vue` → all strings MUST use `t()`, not hardcoded
