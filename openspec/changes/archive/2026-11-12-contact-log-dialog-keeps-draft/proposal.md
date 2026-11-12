# The ticket's contact dialog keeps a draft

## Why
Board PqTicketContactLoggen (design-system #846) draws the kept draft in the ticket's "Contact vastleggen" dialog: "Je was hier al mee bezig." with Concept herstellen and Weggooien. contact-moments-keep-draft (REQ-CMD2-001/002) built the draft into the client page's quick log only, so a half typed contact moment on a ticket was lost when the tab closed or the session ended.

## What changes
- The draft keeping of `ContactmomentQuickLog` moves into one mixin, `contactMomentDraftMixin`, which both the quick log and `ContactLogDialog` use. No second draft mechanism: the same `ticket` of type `interaction` in OpenRegister's status `draft`, the same autosave, the same owner action on Save.
- `ContactLogDialog` offers its author's draft for the ticket it is opened on, whatever customer the draft names, in a warning-toned card above the form.
- `isDraftFormEmpty` learns the form's defaults, so the dialog's preset channel and direction are not a draft on their own; `pickDraft` matches on the request alone when no client is asked for.

## Out of scope
The ticket schema, OpenRegister's draft handling and the cleanup after seven days stay as contact-moments-keep-draft built them.
