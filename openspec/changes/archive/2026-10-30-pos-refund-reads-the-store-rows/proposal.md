# A refund reads the sales and lines the object store holds

## Why
PqKassaRetour opened from POS-2026-1043 showed an empty "Original transaction", no items to take back and a refund of € 0,00. The page read `getCollection(type)?.results`, but the nc-vue object store keeps a collection as a plain array, so every list it read was empty. The refund detail widgets, the receipt template picker and the export destinations read the same way.

## What changes
- One helper, `collectionRows`, reads a collection as the store holds it (a plain array, or a `{ results }` page). The sale page's local copy moves there.
- PosRefundForm, the refund line loaders, PosRefundItemsWidget, the receipt templates and ExportJobForm read through it.
- The refund header holds only Save (no More menu); its notes field is 58px and the outcome pills 34px, as the board draws them.
