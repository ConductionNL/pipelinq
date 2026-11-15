---
kind: code
---

# Proposal: lead-contact-roles

## Summary

Board PqLead: the lead overview lists the people on the deal with the role each plays ("Contacten en hun rol": Beslisser, Pleitbezorger, Poortwachter) and a note, with "Contactrol toevoegen".

- A role is a `relationship` (category `CRM Rol`) from the lead to a contact. No lead field is added: the relationship schema already holds contact, type and notes.
- `relationship.fromType` gains `lead` (schema 1.2.0). The add dialog already wrote `fromType: lead`, which the enum refused.
- The existing LeadContactRoles section becomes the `LeadContactRolesWidget` grid widget and replaces the `lead-contacts` widget on the overview. The Contacts tab keeps the list of everyone linked through `contact.leads`.
- Role labels are translated (decision maker, champion, gatekeeper, influencer, evaluator, end user) and listed in that order. The stored values do not change.
