# Design: pos-barcode-scan

## Architecture

### Data Layer

No new OpenRegister schemas are introduced. This change reads the existing `product` entity (extended by `pos-product-catalogue` with `barcode` and `variants[].barcode` fields) via the standard `objectStore` API.

**Read paths used:**

| Query | API call | Purpose |
|---|---|---|
| Top-level barcode match | `fetchCollection('product', { barcode: '<scanned>' })` | Direct product lookup |
| Variant barcode match | `fetchCollection('product', { 'variants.barcode': '<scanned>' })` or client-side filter | Variant-level resolution |

If the OpenRegister filter engine does not support nested-array property filtering for `variants[].barcode`, the composable falls back to fetching products with `barcode` unmatched and performing client-side variant iteration — capped at 200 products to avoid memory issues.

No write operations. No schema changes. No migration needed.

### Frontend

#### New Composables

**`src/composables/useBarcodeScanner.js`**

Manages two input sources and unifies them under one `scan(barcodeString)` event.

*HID keyboard-wedge detection:*

```js
// Characters accumulate in a buffer with timestamps.
// A sequence qualifies as a barcode scan when:
//   1. At least 4 characters received
//   2. Average inter-character delay < 50 ms
//   3. Sequence terminated by Enter (keyCode 13) or CR

const SCAN_MIN_LENGTH = 4
const SCAN_MAX_INTERVAL_MS = 50

onMounted(() => {
  window.addEventListener('keydown', handleKeydown)
})
onUnmounted(() => {
  window.removeEventListener('keydown', handleKeydown)
})
```

The composable attaches a global `keydown` listener when mounted, accumulates characters with timestamps, and flushes the buffer as a `scan` event when Enter is received with qualifying timing. The buffer is cleared after 200 ms of inactivity.

*Camera / BarcodeDetector mode:*

```js
const supported = ref('BarcodeDetector' in window)

async function startCamera() {
  const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
  videoEl.value.srcObject = stream
  detector = new BarcodeDetector({ formats: ['ean_13', 'upc_a', 'ean_8', 'upc_e'] })
  scanLoop()
}

async function scanLoop() {
  const barcodes = await detector.detect(videoEl.value)
  if (barcodes.length > 0) {
    emit('scan', barcodes[0].rawValue)
    stopCamera()
    return
  }
  rafId = requestAnimationFrame(scanLoop)
}
```

When `BarcodeDetector` is not in `window`, `supported` is `false`; the camera button is hidden and the composable operates in HID-only mode.

**`src/composables/useBarcodeProductLookup.js`**

```js
async function lookupByBarcode(barcode) {
  // 1. Try direct product match
  const directResult = await objectStore.fetchCollection('product', { barcode })
  if (directResult.total === 1) {
    return { product: directResult.results[0], variant: null, status: 'found' }
  }
  if (directResult.total > 1) {
    return { product: null, variant: null, status: 'ambiguous' }
  }

  // 2. Try variant-level match (client-side)
  const allWithVariants = await objectStore.fetchCollection('product', {
    has_variants: true,    // filter hint; falls back to no filter if unsupported
    limit: 200
  })
  for (const product of allWithVariants.results) {
    const variant = (product.variants ?? []).find(v => v.barcode === barcode)
    if (variant) {
      return { product, variant, status: 'found' }
    }
  }

  return { product: null, variant: null, status: 'not_found' }
}
```

Returns `{ product, variant | null, status }` where `status` is one of `'found'`, `'not_found'`, or `'ambiguous'`.

#### New Component

**`src/components/products/BarcodeScanner.vue`**

Replaces the basic stub `BarcodeInput.vue` from `pos-product-catalogue` (or wraps it). Provides a complete scanning UI:

- A compact `<input type="text">` that is always visible, auto-focuses on mount, and accepts HID input via the `useBarcodeScanner` composable.
- A camera icon button (`IconCamera`) visible only when `BarcodeDetector` is supported. Clicking it opens a `<video>` overlay for camera scanning.
- A `<video>` overlay element (absolutely positioned, full-viewport) with a scan target reticle SVG when camera is active.
- A status indicator slot: spinner while looking up, `IconCheck` on success, `IconAlertCircle` with error text on failure.

Props:
- `autofocus` (Boolean, default true) — autofocus the HID input on mount

Emits:
- `scan(barcodeString: string)` — raw barcode string from either input source

The component does NOT perform the product lookup — that is the responsibility of the parent using `useBarcodeProductLookup`.

#### Modified Files

**`src/views/products/ProductList.vue`**

The basic `BarcodeInput.vue` scan handler added by `pos-product-catalogue` is updated to use `BarcodeScanner.vue` instead (or enhanced in-place if `BarcodeInput.vue` was not separately extracted). The scan handler:

```js
async function onScan(barcode) {
  scanStatus.value = 'loading'
  const { product, status } = await lookupByBarcode(barcode)
  if (status === 'found') {
    router.push(`/products/${product.id}`)
  } else {
    scanError.value = t('pipelinq', 'Geen product gevonden voor barcode {barcode}', { barcode })
    scanStatus.value = 'error'
  }
}
```

### Backend

No new PHP controllers or services. All product data is fetched via the existing OpenRegister REST API through `objectStore`. No migration is needed — all properties used (`barcode`, `variants`) were added by `pos-product-catalogue`.

### Integration Points

| System | Integration |
|---|---|
| OpenRegister `product` schema | Read-only; queries `barcode` and `variants[].barcode` |
| `BarcodeDetector` Web API | Browser-native; polled via `requestAnimationFrame` |
| `getUserMedia` Web API | Camera stream for live viewfinder |
| `pos-product-catalogue` | Provides `product.barcode` and `product.variants[].barcode` fields |

## Components

See `specs/pos-barcode-scan/spec.md` for formal requirements and BDD scenarios.

## i18n

| Key | English | Dutch |
|---|---|---|
| `Barcode scannen` | `Scan barcode` | `Barcode scannen` |
| `Camera openen` | `Open camera` | `Camera openen` |
| `Camera sluiten` | `Close camera` | `Camera sluiten` |
| `Geen product gevonden voor barcode {barcode}` | `No product found for barcode {barcode}` | `Geen product gevonden voor barcode {barcode}` |
| `Meerdere producten gevonden voor barcode {barcode}` | `Multiple products found for barcode {barcode}` | `Meerdere producten gevonden voor barcode {barcode}` |
| `Richten op barcode…` | `Aim at barcode…` | `Richten op barcode…` |
| `Camera niet beschikbaar` | `Camera not available` | `Camera niet beschikbaar` |
| `Product gevonden: {name}` | `Product found: {name}` | `Product gevonden: {name}` |

All keys follow ADR-007 sentence case with English as the key string.

## Files Changed

### New Files

| File | Purpose |
|---|---|
| `src/composables/useBarcodeScanner.js` | HID timing detection + BarcodeDetector camera composable |
| `src/composables/useBarcodeProductLookup.js` | Barcode → product + variant resolver |
| `src/components/products/BarcodeScanner.vue` | Unified scanner component (HID + camera) |
| `specs/pos-barcode-scan/spec.md` | Formal requirements and BDD scenarios |

### Modified Files

| File | Change |
|---|---|
| `src/views/products/ProductList.vue` | Replace `BarcodeInput.vue` handler with `BarcodeScanner.vue` + `useBarcodeProductLookup` |
| `l10n/en.json` | Add 8 new translation keys |
| `l10n/nl.json` | Add Dutch translations for the same 8 keys |

## Seed Data

Five realistic Dutch `product` objects with EAN-13 barcodes demonstrating the lookup scenarios exercised by `useBarcodeProductLookup`. These complement the seed data added by `pos-product-catalogue` — do not duplicate those objects; add these to `pipelinq_register.json` alongside them.

### 1. Shampoo Keratine (eenvoudig product, barcode top-level)

```json
{
  "@self": { "register": "pipelinq", "schema": "product", "slug": "product-shampoo-keratine" },
  "name": "Shampoo Keratine",
  "description": "Voedende keratineshampoo voor beschadigd haar, 250 ml",
  "sku": "HAR-SHA-001",
  "barcode": "8710919041022",
  "unitPrice": 14.95,
  "type": "product",
  "status": "active",
  "unit": "fles",
  "taxRate": 21,
  "btwClass": "hoog",
  "category": null
}
```

### 2. Herenkapper Basis Pakket (retailproduct met meerdere varianten en per-variant barcodes)

```json
{
  "@self": { "register": "pipelinq", "schema": "product", "slug": "product-haargel-flex-hold" },
  "name": "Haargel Flex Hold",
  "description": "Stijlvaste haargel met flexibele hold, zonder uitdrogen",
  "sku": "HAR-GEL-002",
  "barcode": "8714100247021",
  "unitPrice": 8.95,
  "type": "product",
  "status": "active",
  "unit": "pot",
  "taxRate": 21,
  "btwClass": "hoog",
  "variants": [
    { "sku": "HAR-GEL-002-75", "name": "75 ml", "attributes": { "inhoud": "75 ml" }, "unitPrice": 5.95, "barcode": "8714100247038", "status": "active" },
    { "sku": "HAR-GEL-002-150", "name": "150 ml", "attributes": { "inhoud": "150 ml" }, "unitPrice": 8.95, "barcode": "8714100247045", "status": "active" },
    { "sku": "HAR-GEL-002-300", "name": "300 ml", "attributes": { "inhoud": "300 ml" }, "unitPrice": 14.50, "barcode": "8714100247052", "status": "active" }
  ]
}
```

### 3. Nagellak Set (multi-variant kleurproduct)

```json
{
  "@self": { "register": "pipelinq", "schema": "product", "slug": "product-nagellak-set" },
  "name": "Nagellak Essie",
  "description": "Professionele nagellak, langhoudend, 13.5 ml",
  "sku": "NAG-LAK-001",
  "barcode": "30008361",
  "unitPrice": 10.50,
  "type": "product",
  "status": "active",
  "unit": "flesje",
  "taxRate": 21,
  "btwClass": "hoog",
  "variants": [
    { "sku": "NAG-LAK-001-RED", "name": "Ballet Slippers (roze)", "attributes": { "kleur": "Ballet Slippers" }, "unitPrice": 10.50, "barcode": "30008362", "status": "active" },
    { "sku": "NAG-LAK-001-BLU", "name": "Lapiz of Luxury (blauw)", "attributes": { "kleur": "Lapiz of Luxury" }, "unitPrice": 10.50, "barcode": "30008363", "status": "active" },
    { "sku": "NAG-LAK-001-NUD", "name": "Vanity Fairest (nude)", "attributes": { "kleur": "Vanity Fairest" }, "unitPrice": 10.50, "barcode": "30008364", "status": "active" }
  ]
}
```

### 4. Herstellende Conditioner (product zonder varianten, laag-BTW)

```json
{
  "@self": { "register": "pipelinq", "schema": "product", "slug": "product-conditioner-hydra" },
  "name": "Conditioner Hydra Boost",
  "description": "Intensieve herstelconditioner met hyaluronzuur, 300 ml",
  "sku": "HAR-CON-003",
  "barcode": "8720608064038",
  "unitPrice": 12.75,
  "type": "product",
  "status": "active",
  "unit": "tube",
  "taxRate": 9,
  "btwClass": "laag",
  "category": null
}
```

### 5. Kleurenkaart Salontester (demoartikel, actief maar €0,00)

```json
{
  "@self": { "register": "pipelinq", "schema": "product", "slug": "product-kleurenkaart-salontester" },
  "name": "Kleurenkaart Salontester",
  "description": "Haar-kleurenkaart voor gebruik in de salonruimte — niet voor verkoop",
  "sku": "SLN-TST-001",
  "barcode": "8720608999001",
  "unitPrice": 0.00,
  "type": "product",
  "status": "active",
  "unit": "stuk",
  "taxRate": 21,
  "btwClass": "hoog"
}
```
