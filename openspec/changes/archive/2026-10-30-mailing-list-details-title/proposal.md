# Proposal: mailing-list-details-title

## Why

pl4 on board PqMailinglijst (5): the Overview tab heads the list details with the title "Lijstgegevens". The tab holds
one widget, and nc-vue's tab panel drew a single widget's title only when the widget has a description, so live
dropped it.

## What changes

- The mailing list page's Overview tab sets `showTitle: true` (nc-vue fix/pq-c11-tab-panel-title), so the panel reads
  "Lijstgegevens" above the fields. Before nc-vue has the key, the panel stays as it is.
- nl "List details" reads "Lijstgegevens", the board's word (was "Lijstdetails"). The msgid is used only on this card.
