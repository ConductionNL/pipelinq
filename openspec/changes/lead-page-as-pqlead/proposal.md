# The lead page reads as the board

## Why
Board PqLead, re-measured on :8080 on 28 Oct after DQ-L3b released it, showed several differences:
- The header named the owner by user id.
- "Contact vastleggen" was missing.
- The "what now" card had no "Offerte maken" button.
- The Deal card showed fourteen facts in three columns where the board draws eight in two.
- The win chance read "70" instead of a percentage.

## What changes
- `headerMeta` names the owner with `{assignee|user}`.
- "Log contact" (a contact moment with `lead` set) is the first quick action. The existing "Bezoek vastleggen" (LogVisitAction) stays.
- "Make quote" opens a draft `salesQuote` for the lead. In the qualified and proposal stages it is the "what now" button.
- The Deal card shows value, win chance, expected closing, priority, source, category, owner and in-this-stage-since, in two columns (`fixedColumns`, nc-vue 24t2l). The other fields stay in the edit form and the client card.
- The win chance reads as a percentage.

Not built (data): the lead's pipeline stages, its source wording, the contacts with a role, the flow runs, the suggested colleagues and the next appointment.
