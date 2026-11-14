# Proposal: bi-export-board

## Why

pl2 measured BI-export against board PqBiExport: the board draws one tab strip over the jobs, the destinations and
the runs ("Taken 4 · Bestemmingen 2 · Uitvoeringen 38"), a Filter button beside the views, and a job's format and mode
in words ("Parquet · incrementeel"); live had three separate lists with no way between them, no Filter button, and the
stored values ("parquet · incremental").

## What changes

- The three BI export lists (ExportJobs, ExportDestinations, ExportRuns) share one page tab strip, each tab counting its
  list (`views/export/exportPageTabs.js`).
- The jobs list offers the board's Filter button (`filterMenu`).
- A job's format and mode read in words under its name.
