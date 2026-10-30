# Proposal: product-panels-fit-content

## Why

pl6 on :8080 (PqProduct): the product page's tab strip sat in a fixed 18-row grid cell. The Overview is taller than
that, so it was cut off under "Gebruikt op deals" and "Images & spec sheets" never showed. The three figures took two
80px rows and stood 32px taller than drawn, which put the tabs 32px low.

## What changes

- The three figures and the `product-panels` tab strip size to their content (`sizeToContent: true`).
- `gridFloat: false`, so the grid takes 1px rows and a fitted cell ends at its content, as on MailingListDetail.

## Reach

ProductDetail manifest only. Works on the released nc-vue.
