# Tasks

## 1. Manifest
- [x] 1.1 Add `"control": "pills"` to the Commercial `Dashboard` page `dateRange` config
- [x] 1.2 Compact the Commercial `layout` so charts/tables sit directly below the KPI rows (no dead gap)

## 2. Verify
- [x] 2.1 `npm run build` green; manifest is valid JSON
- [x] 2.2 Live-verify on :8080: pills row present (no select/date-inputs), no whitespace gap before charts, KPI/gauge tiles have no scrollbars
- [x] 2.3 `npm run test:unit` at baseline (pre-existing recurringRevenue orphan ignored)
