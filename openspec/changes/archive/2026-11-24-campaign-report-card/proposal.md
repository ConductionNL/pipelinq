---
kind: code
screens: [PqCampagne]
---

# Proposal: campaign-report-card

## Summary

Board PqCampagne draws the campaign report on the campaign page as a card of its own: hairline frame, 20px 22px inside,
the title 17px bold with the period under it, and the tiles and channel table inset with the card. Live drew the report
bare on the page ground, so the table stood 23px further left and 46px wider. The board reads "Toeschrijvingsmodel"
where live read "Attributiemodel". Live also clipped "Niet vastgelegd" in the Kosten tile.

## What changes

- CampaignReport, embedded: the root is a card (border, radius 12px, main background, padding 20px 22px), the title
  17px/700, header, tiles and table 14px apart.
- A tile starts at 90px and grows, without a fixed minimum, so a long figure widens its tile instead of being clipped.
- nl "Attribution model" reads "Toeschrijvingsmodel", as on the PqCampagne and PqNieuwsbriefToeschrijving boards.

The model label stays above its select: NcSelect owns that label (inputLabel), and moving it beside the field needs a
live check that this lane could not run.
