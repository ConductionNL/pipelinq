---
kind: code
---

# Proposal: pl2-booking-row-editors

## Summary

A resource's working hours and vacations, and a service's steps, were edited with four pipelinq components
(BookingRowsEditor and the three editors on it), and a new resource or service had its own full-page form
(ResourceForm, ServiceForm). They now use the library row editor, CnSubObjectsField, through field overrides:

- working hours and vacations are edited in the row (`inline`), with "open time before close time" and "start on or
  before end" as row rules that name the row and block the save;
- a service's steps open a dialog per step, the product picked from the catalogue, with "45 of 60 min" adding the step
  minutes up against the service duration;
- a new resource or service is made in the same dialog as an edit.

The four editors and the two forms are deleted.

## Dependency

`inline`, `summary`, `rowRules` and override labels come from nextcloud-vue `feature/pl-abstract-pl2-subobjects`. Until
that release, the rows open a dialog per row and the rules and summary do not show.
