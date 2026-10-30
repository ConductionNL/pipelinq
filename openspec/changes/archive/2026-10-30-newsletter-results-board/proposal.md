---
kind: code
---

# Proposal: newsletter-results-board

## Summary

pl2 PqNieuwsbriefPrestaties, PqNieuwsbriefABTest and PqNieuwsbriefToeschrijving: the newsletter results page (`/blasts/performance`) now matches the three boards, one board per tab.

- **Header:** the page header is a CnPageHeader titled "Results of your sends", with Download and the buildiq square beside it, and the page takes the page look.
- **Tab strip:** an Actions menu sits at the end of the tab strip, outside the tablist.
- **Overview:** the status pill takes its colour from the blast status, and rates are whole percentages.
- **A/B testing:** the newest test is drawn in full. Each variant card shows the subject, Delivered / Clicked / Click rate and, on the variant that clicked better, a "Higher click rate" pill. Below the cards is the p-value line. Every older test is a one-line summary.
- **Attribution:** the attribution table has a title, and the site-traffic table gets an explanation line. Both tables sit in a bordered card. The traffic table shows Campaign / Opens / Clicks / Site sessions, as drawn.

The A/B arithmetic moves out of the view into `src/services/abTest.js` so it can be tested.

The page stays a custom view. Rebuilding it as a CnDashboardPage needs an endpoint-backed table widget, an A/B variant widget and one summary endpoint. That is a separate change.
