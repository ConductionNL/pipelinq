---
kind: code
screens: [PqCampagne]
---

## Why

Board PqCampagne's "In deze campagne" card names how much the campaign holds: "2 nieuwsbrieven", "4 sociale
berichten", "1 artikel, de inhoud van de pagina", "208 leads". The live card listed four plain links with no counts.

## What changes

The card is a `menu` widget. Newsletters, social posts and leads are counted per campaign: `countFrom` uses the
filter `campaignId: @objectId`, and `countLabel` names the count in the line. This needs nextcloud-vue with
menu-item-count-labels. The page's own article is one line, shown only while the campaign has page content (`visibleIf` on
`object.articleBody`).
