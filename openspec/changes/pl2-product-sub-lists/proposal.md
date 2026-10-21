---
kind: code
---

# Proposal: pl2-product-sub-lists

## Summary

A product's price tiers, variants and modifier groups could not be edited any more: their editors (PriceTierTable,
ProductVariantPanel, ModifierGroupPanel) were mounted nowhere and have been deleted, and the three properties were
`visible: false`, which hides a property on every surface, the edit form included. Ruben chose to restore editing as
configuration (PL-ABSTRACT question 10).

- The three properties are visible again (register 1.12.0, product schema 1.1.0); no property changes.
- The product form (the page's Edit and the index's create) edits them as rows of the library row editor: price tiers
  in the row, variants and modifier groups in a dialog per row, a group's options as rows inside it.
- The product page shows each list as a read-only table in the Overview tab, modifier groups as a new card.

## Dependency

Inline rows and translated column labels come from nextcloud-vue `feature/pl-abstract-pl2-subobjects`; before that
release the rows open a dialog each.
