---
kind: code
---

# Proposal: newsletter-results-board-look

## Summary

pl3 PqNieuwsbriefPrestaties, PqNieuwsbriefABTest and PqNieuwsbriefToeschrijving: the newsletter results page (`/blasts/performance`) in the board look, as the three boards draw it.

- **Back link:** "Back to blasts" is a plain link (14px/600 with a 16px arrow), not a tertiary button. The button was 34px high and put the title 13px under the board's.
- **Header:** the header ends 8px under its line with no margin of its own, so the page's 20px gap follows. Download and the buildiq square are 40px high.
- **Tab panel:** the open tab's panel is a white card with the board's border and a 0 12px 12px 12px radius. Before, the panel had no ground, so the tables and the variant cards stood on the page's grey.
- **Tables:** the head row stands on the card's ground (no grey band), and head and cells sit on a 1.2 line.
- **A/B variant cards:** the variant title sits on a 1.2 line and the figures start at the card's text edge.
- **Site traffic:** the per-blast traffic reads run a few at a time, as the attribution reads already do. They ran one at a time, so a page with 46 blasts showed "Loading site traffic" long after the rest of the tab had loaded.

The page stays a custom view (decision of 27 Oct: fix the existing view, no dashboard rebuild). The standalone header's 4px top inset and its 13px line gap are nc-vue's (look-board.css), because every standalone CnPageHeader needs them (PqForecast measures the same gap).
