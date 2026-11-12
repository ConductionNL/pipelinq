---
kind: spec
depends_on: []
---

# Proposal: specify-capability-rows

## Summary

Write down, as requirements with scenarios, what 27 rows of the pipelinq
capability matrix promise and no requirement said yet. Each row in
`openspec/parity/capabilities.json` then records the requirement it is held to
in `built.requirement`.

## Motivation

A row only counts as specified when it names a requirement whose scenarios say
what the row says. For these 27 rows the cited spec had requirements, but none
of them said the row. Ten of them (the customer portal rows and export schema
changes) were specified in the archived changes `2026-06-14-customer-portal`
and `2026-06-14-bi-export-and-data-warehouse-sink`, whose old `specs.md` format
was never carried into the main specs. This change carries that behaviour over
in the current format and adds the rest.

## What changes

Requirements are added to these main specs:

- `customer-portal`: login, second factor, requests and replies, documents,
  invoices and other records, delegation, data export, account closure and
  signing a document.
- `bi-export-and-data-warehouse-sink`: schema changes between runs and copying
  service conversations to an outside archive.
- `client-management`, `pipeline`, `dashboard`, `task-background-jobs`,
  `appointment-booking`, `contract-renewal-tracking`, `klachtenregistratie`,
  `first-time-setup`, `notifications-activity`, `prometheus-metrics`,
  `marketing-analytics-connectors`, `vng-klantinteracties-leaf` and
  `openregister-integration`: one or two requirements each.

## Out of scope

Code. Most of these rows are marked built; the tasks check that the code does
what the new scenarios say and open a fix where it does not.
