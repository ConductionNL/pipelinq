---
kind: code
---

# Proposal: data-portability-full-export

## Summary

A customer who leaves must be able to take all their data with them in an open format. Part of this is built: an export job can extract any schema of the pipelinq register (`ExportDataService::extractSchema()`), format it as CSV, JSON Lines or Parquet (`ExportDataService::format()`) and write it to a destination through `ExportSinkRegistry`. But that takes one configured job per destination and a list of schemas the administrator has to know. There is no single action that hands over everything, with the schema definitions and the files, in one archive.

This change writes the portability guarantee down and plans that action. It reuses OpenRegister's own register export where it exists (ADR-011, ADR-022) and adds only the packaging.

## The row this covers

Source: pipelinq `openspec/parity/capabilities.json`.

- **take-your-data** Take all your data with you if you decide to leave (built in part: `lib/Adapter/ExportSinkRegistry.php`, `lib/Service/Export/ExportDataService.php`). Board: PqBiExport.

## What changes

1. Spec `data-portability`: every schema can leave in an open format (built), and an administrator can download the whole register in one archive (to build).
2. Build: an admin action "Export all data" on the BI export page that produces a ZIP with one JSON Lines and one CSV file per schema, the schema definitions as JSON Schema, a manifest, and the files attached to objects, built as a background job with a notification when the archive is ready.

## Screens

PqBiExport (see `screens.md`). The action is a section on that page; the board should draw it (a board lane item, not this change).

## Out of scope

- Importing the archive into another product.
- Data of other apps (OpenRegister registers owned by dossiq, shillinq and so on); each app exports its own register.
