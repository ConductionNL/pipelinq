# Design: pipeline-insights

## Architecture

All changes are frontend-only — no backend modifications needed. OpenRegister already provides `_dateModified` on every object.

### PipelineBoard.vue Changes

#### Stage Revenue Summary
- The `getStageTotalValue(stageName)` method already exists and sums lead values per stage
- Enhance the stage column header to show this value more prominently
- Format as `EUR X,XXX` using `toLocaleString('nl-NL')`
- Show for all stages (including "EUR 0" for empty/request-only stages)

#### Aging Column in List View
- Add "Age" column to the list table showing days since `_dateModified`
- Color code: normal (<7d), amber (7-13d), red (14d+)
- New helper: `getDaysAge(item)` — `Math.floor((now - new Date(item._dateModified)) / 86400000)`

#### Stale Badge in List View
- Add stale indicator next to lead titles in list view
- Only for leads where `getDaysAge(item) >= 14`

#### Overdue Styling in List View
- Add `overdue` CSS class to rows where item is overdue
- Red text on the date cell, subtle red-tinted background on the row

### PipelineCard.vue Changes

#### Aging Indicator
- Show `Xd` badge in the card footer next to the date
- Uses `_dateModified` to calculate days in stage
- Color coding: default (<7d), amber (7-13d), red (14d+)

#### Stale Badge
- Show "Stale" badge in card header for leads with 14+ days since modification
- Orange/amber pill badge, positioned next to entity type badge

#### Overdue Card Styling
- Add red left border (`border-left: 3px solid var(--color-error)`) to overdue cards
- Show date in red when overdue
- Extend existing `isOverdue` computed to cover both leads and requests

### MyWork.vue Changes

#### Overdue Highlighting
- The "Overdue" group already exists — enhance styling:
  - Red date text for overdue items
  - Red dot/count in the group header
  - Subtle red background tint on overdue group

#### Stale Badge
- Show stale indicator next to lead titles in My Work items
- Same logic: leads with 14+ days since `_dateModified`

### Shared Helper: `pipelineUtils.js`

Create `src/services/pipelineUtils.js` with shared functions:

```js
export function getDaysAge(item) {
  if (!item._dateModified) return 0
  return Math.floor((Date.now() - new Date(item._dateModified).getTime()) / 86400000)
}

export function isStale(item, entityType) {
  if (entityType !== 'lead') return false
  return getDaysAge(item) >= 14
}

export function getAgingClass(days) {
  if (days >= 14) return 'aging-alert'
  if (days >= 7) return 'aging-warning'
  return ''
}

export function formatAge(days) {
  if (days === 0) return 'Today'
  if (days === 1) return '1d'
  return `${days}d`
}
```

## Files Changed

- `src/services/pipelineUtils.js` (new — shared aging/stale helpers)
- `src/views/pipeline/PipelineBoard.vue` (modified — revenue header, age column, stale badge, overdue styling)
- `src/views/pipeline/PipelineCard.vue` (modified — aging badge, stale badge, overdue border)
- `src/views/my-work/MyWork.vue` (modified — overdue highlighting, stale badge)
