# Proposal: reports-page-as-board

## Why

Board PqReports draws "Rapportages" as a catalogue: an h1 header with the buildiq square and "Nieuw analyserapport", the
categories as chips with counts, the report cards with their category and Open, and an aside with the category index and
"Mis je een rapportage?" (pl2: 17 items).

## What changes

The Reports page sets `catalogue: true` (nextcloud-vue reports-page-catalogue), a "New analysis report" header button that
opens Nextcloud Analytics, the aside note, and the board's "Staff performance" (Prestaties medewerkers) label. The SLA
attainment card the simple profile appends stays.
