---
kind: code
---

# Proposal: contacts-sync-settings

## Summary

Board PqPersoonlijk draws a "Contacten" section in the user settings dialog: the sync status, the addressbook new cards go to, a switch for writing changes back to Nextcloud Contacts, the last run with any card that could not be written, and the buttons "Nu synchroniseren" and "Importeren uit Contacten".

- A per-user write-back switch, on by default. Off, a save no longer writes the linked card (the write-back endpoint answers `skipped`).
- A status endpoint: how many clients and contact persons are linked, the addressbook name, the switch and the last run.
- A run endpoint that writes every linked record back now. Like the single write-back, it needs the CRM capability. It remembers when it ran, how many cards it wrote and which records failed.
- The section sits in the user settings dialog before "E-mail en agenda". Its import button opens the existing Nextcloud Contacts import dialog.

## Not in this change

"Foto's en groepen synchroniseren" is not built: the write-back writes no photo or groups, and a switch that changes nothing would mislead. The board drops it until it is built.
