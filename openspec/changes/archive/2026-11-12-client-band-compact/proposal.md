# Proposal: client-band-compact

## Why

pl6 on :8080 (PqKlant, PqOrganisatie): the six figures above the tabs wrapped into two rows (four, then "Open leads"
and "Laatste activiteit"), because a band item is at least 220px wide. The boards draw one row of six 67px tiles. The
second row pushed the tabs, the side column and every card under them 67 to 79px down. nc-vue has had the compact band
(`bandDensity: "compact"`: tiles down to 120px, 12px apart, 67px high) since 24t2o; the pipelinq head that set it
(PQB-3) was dropped in favour of PQB-6, which took the band but not its density.

## What changes

- ClientDetail sets `bandDensity: "compact"`.

## Reach

ClientDetail manifest only. Works on the released nc-vue.
