# The letter dialog shows the letter and what was filled in

## Why

PqBriefOpstellen draws the letter dialog with a preview of the letter beside "Voor je ingevuld": each merge field with its value and where it came from, a line that says whether anything is missing, "Versturen als", and "Pdf downloaden" beside the make button. The dialog had only the choices and the make button, so a user made a letter without seeing it.

## What changes

- `POST /api/clients/{id}/letters/preview` reads the client, contact and ticket as a letter does and asks filinq for a merge preview (filinq `template-merge-preview`). It returns the preview HTML, the filled fields and whether all are filled. Nothing is made, filed or logged.
- The dialog shows the preview (the body only, sanitised), the filled fields, the check line, "Versturen als" (PDF to print and post, the one way a letter goes out today), and "Pdf downloaden".
- `POST /api/clients/{id}/letters` takes `downloadOnly`: the PDF comes back without a copy in Files and without a contact moment.
- Without filinq's merge preview the dialog says the preview could not be made, and the letter can still be made.
