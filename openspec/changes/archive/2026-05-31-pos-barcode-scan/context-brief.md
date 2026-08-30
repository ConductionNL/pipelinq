---
status: draft
---

# POS Barcode Scan (HID + camera)

## Purpose

EAN/UPC/GTIN lookup; HID scanner + browser camera fallback (BarcodeDetector API).

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 13/13 competitors
- **Dependencies:** pos-product-catalogue

## Competitor Evidence (from intelligence-db)

- chromis-pos :: Barcode scan + product master :: EAN/UPC; product image, attributes
- chromis-pos :: Stock control + supplier (basic) :: Min stock, reorder, simple PO
- dvi-salonsoftware :: Barcodescanner voor retail producten :: USB/Bluetooth scanner; shampoo/verzorging
- dvi-salonsoftware :: Behandelingen + retail in 1 catalogus :: Diensten met duur + retail SKUs
- erpnext-pos :: Item with variants, batches, serials :: Full ERP item master in POS
- korona-cloud :: Advanced inventory (size matrix, kits, expiry) :: Apparel matrix; food/pet expiry tracking
- korona-cloud :: KORONA.pet for pet stores (subscriptions, weight pricing) :: Pet store SKUs, frequent buyer per category
- lightspeed-retail :: Advanced inventory (matrix, serial, kit) :: Size/colour matrix, serial numbers, assembly/bundles
- mews-pos :: Menu/product editor :: Items, modifiers, courses per outlet
- odoo-pos :: Barcode scanner (USB/HID) + product variants :: Variant matrix, attributes, internal references
- salonized :: Barcode scanner support :: USB/Bluetooth scanner for retail products
- salonized :: Treatment + retail product catalogue :: Services (with duration) + retail SKUs in one catalogue
- salonkee-pos :: Barcode scanner support (USB/Bluetooth) :: Retail product lookup
- salonkee-pos :: Treatment + retail product catalogue :: Services with duration + retail SKUs
- shopify-pos :: Smart product search (camera, code, name) :: Visual + barcode + text search
- square-pos :: Barcode scanning via camera or USB scanner :: iPad/phone camera or Bluetooth/USB scanner
- square-pos :: Item library with variants and modifiers :: SKU, size/colour variants, modifier sets
- toast-pos :: Menu builder with modifiers, 86 list :: Item, mod groups, sold-out propagation
- unicenta-opos :: Barcode scanner (HID/serial) + label printing :: EAN/UPC; built-in label designer (DYMO/Zebra)
- unicenta-opos :: Product + product attributes + categories :: Hierarchical category tree; product variants

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 20 competitor implementations. See `/tmp/pipelinq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.
