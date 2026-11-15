---
kind: code
---

# Proposal: lead-overview-one-sheet

## Summary

pl4 PqLead 1-4, 8, 9, 17, 18. The board draws the lead's Overzicht tab as one sheet: the stage strip without a title, then Deal, Producten op deze lead, Contacten en hun rol and Uitvoeringen van flows as sections with a hairline between them. Live drew a "Fasen" card joined to the tab and four cards under it.

The Overview tab takes `sections: true` and the stage strip `showTitle: false` (nc-vue feat/pq-c20-tab-stack-sections; an older nc-vue ignores both keys and keeps the cards). The products table reads its lines as plain main-ink text (`rowTitle: "text"`, the board's 400 weight; `plain` is bold) and sorts on its heads (`sortable: true`) next to the filters it already had.

Configuration only, no PHP, no strings.
