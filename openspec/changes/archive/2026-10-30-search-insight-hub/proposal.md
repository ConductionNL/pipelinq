---
kind: code
---

# Proposal: search-insight-hub

## Summary

pl2 PqZoekinzicht: the search queries page (`/marketing/search-queries`) becomes the search insight page the board draws. It brings four existing pages under Marketing together, each as a titled card:

- **Search queries:** the existing table, with the period as a segmented control.
- **Keywords:** the keyword proposals, from the embedded KeywordIntelligence view.
- **Competitors:** the competitor watches, from the embedded CompetitorWatches view.
- **Connection audit:** the first five stored rows, with Check again.

The page header is a CnPageHeader titled "Search insight", with Download (the query rows as CSV) and the buildiq square, and the page takes the page look. The menu entry and the modules card follow the new title.

KeywordIntelligence and CompetitorWatches gain an `embedded` prop. It hides their own header, and on Keywords it also hides the period picker and the ranking buckets; the host passes the period. Their own pages are unchanged. No new endpoint is added.
