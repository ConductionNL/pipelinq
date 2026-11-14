---
kind: code
---

## Why

The board PqKassaAfrekenen draws the receipt preview as the receipt text in a fixed-width column. pipelinq drew it with its own `<pre>` and styling. nextcloud-vue now offers this as `CnTextWidget` with `content.monospace` and `content.textField`.

## What Changes

- `ReceiptPreviewPane` keeps its label and spinner and shows the receipt text through `CnTextWidget` with `monospace: true`. Its own monospace styling is removed.
- The print and email dialogs keep using `ReceiptPreviewPane`, so both show the same preview.

## Impact

- Needs a nextcloud-vue release that contains `CnTextWidget` `monospace` and `textField` (nextcloud-vue development, merge #2088). The dependency bump follows that release.
- PqKassabon, the receipt on the transaction page, is not built yet: the receipt text comes from the preview endpoint, not from a field on the transaction, and a text widget reads a field. It stays open.
