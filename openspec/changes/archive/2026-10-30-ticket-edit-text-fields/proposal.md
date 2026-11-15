---
kind: code
---

# Proposal: ticket-edit-text-fields

## Summary

Board PqTicketBewerken draws the ticket edit dialog with Titel across both
columns, then Omschrijving as a three-line text field, and "Bericht aan de
klant" as a two-line text field. Live drew Omschrijving as a one-line input
in half a row beside Titel, and the customer message as a one-line input in
half a row. The edit form's field overrides now name the textarea widget, its
rows and the full width. `rows` and `width` need nc-vue
fix/form-field-full-width-and-rows; before it the two texts are four-line
textareas across both columns and Titel keeps half a row.
