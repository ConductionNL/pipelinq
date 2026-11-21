---
kind: code
depends_on: [detail-pages-read-at-a-glance]
---

# Proposal: booking-and-service-pages

## Summary

A service's composition is edited where it is shown, and a step can name a
product with a quantity and a unit, so a service such as "OpenWoo app" is made
of implementation hours, monthly hosting and an SLA. Service information and
Policies sit side by side. The booking page uses tabs: Timeline, Resources,
Notes (the notes leaf) and Documents. The audit table is gone.

## Motivation

Ruben's review of the pipelinq beta on 2026-10-06 (items E5, E6, E7).

- **E5.** The multi-step composition could only be changed through the
  general Edit dialog. Service information, the composition and Policies were
  three stacked cards. A step could not refer to a product.
- **E6.** The booking page had its own audit trail table, while OpenRegister's
  activity is already in the actions menu. Its timeline should become the
  library `timeline` widget type the nextcloud-vue lane is generalising from
  dossiq's case timeline.
- **E7.** Booking notes used a hand-written textarea editor instead of the
  notes leaf every other page uses, and the page had no tabs.

## What changes

- `appointmentService.multiStep[]` items gain `productId`, `quantity` and
  `unit` (hour, day, month, year, piece); schema version 1.1.0.
- ServiceStepsEditor gets a product picker (the product catalogue), a
  quantity and a unit per step. The composition card on ServiceDetail has
  "Edit steps" with Save and Cancel, and shows each step's product and amount.
  Service information and Policies sit in a two-column grid that collapses to
  one column on a phone.
- BookingDetailSection renders one part at a time (`context`, `assignments`,
  `timeline`) and is registered as three grid widgets. Status changes join the
  timeline. The notes editor and the audit table are removed.
- BookingDetail: data block with a real title, deposit in the reporting
  currency, a context widget, and a tab strip Timeline, Resources, Notes
  (`integrationId: notes`), Documents. No body section below the grid.

## Out of scope

- Swapping the Timeline tab to the library `timeline` widget type: it is not
  released yet. The tab is its own widget, so the swap is one manifest line.
