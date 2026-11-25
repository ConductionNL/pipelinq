---
kind: code
depends_on: []
---

# Proposal: platform-client-retention

## Summary

Give a client and its contact persons a retention term that starts when the
relationship ends. When the term runs out, the client lands on a destruction
list, a records officer approves it, and OpenRegister destroys it. Contact
moments, tasks and BRP data already expire by themselves; client and contact
records never do. This change closes that gap with OpenRegister's retention
engine, and prepares the anonymise answer that OpenRegister is still building.

## Motivation

One row of the pipelinq capability matrix (`openspec/parity/capabilities.json`),
decided `build` by the OpenSpec pass of 2026-09-27.

**`plat-retention-destroy`**, "Have client records destroyed or anonymised by
themselves when their retention period ends". Rated partial, built.state built.
Matrix evidence: "lib/Settings/pipelinq_register.json:1516
\"x-openregister-archival\" (task, P2Y/P1Y) and
lib/Settings/register.d/99-unify-ticket-supertype.json:27 (ticket, contact
moments P2Y) are enforced by OpenRegister lib/BackgroundJob/ArchivalRetentionTask.php:77;
lib/BackgroundJob/BrpRetentionJob.php:189 \"deleteObject(uuid: $uuid)\" for BRP
person data past retentionTo. The client and contact schemas carry no archival
block, so client records themselves are never disposed". Note: "contact moments,
tasks and BRP data expire by themselves; client and contact records have no
retention term, and request/complaint tickets are set to P100Y".

The decision reason: partial and built, and the missing half is a retention
term and anonymisation for client and contact records. Demand: tender,
https://www.tenderned.nl/aankondigingen/overzicht/421969, with the requirements
"intelligence-db requirements#193358 (Gemeente Amersfoort, 2026-04-20:
bewaartermijnen en anonimisering)" and "intelligence-db requirements#3763 (NZa
CRM, 2024-12-06: vernietigingsproces standaard in de CRM Oplossing)".

Competitor cells, quoted from the matrix:

- hubspot-crm (partial): https://knowledge.hubspot.com/privacy-and-consent/manage-data-retention-policy-settings
  "turn on the setting to automatically delete data": "Once a contact is
  inactive for the defined period of time, it will be deleted with the option to
  restore it from the recycling bin within 90 days". Deletion by inactivity for
  contacts only, and no anonymisation.
- odoo-crm (partial): "addons/data_recycle/models/data_recycle_model.py:33-49 a
  recycle rule per model with recycle_mode \"Automatic\", recycle_action
  \"Archive\" or \"Delete\" and a time field plus delta (for example contacts not
  updated for 5 years) [...]; it archives or deletes but does not anonymise".
- espocrm (no): "No retention policy on records in 10.0.8 [...] personal data
  erasure (application/Espo/Tools/DataPrivacy/Erasor.php:78) is a manual action
  per record".
- kiss (no): "KISS has no retention or purge job [...]; open issue
  https://github.com/Klantinteractie-Servicesysteem/KISS-frontend/issues/1390
  \"Archiveren en vernietigen van Management info in KISS\"".
- pipedrive (unknown): deletion is manual with a 30 day restore window
  (https://support.pipedrive.com/en/article/how-can-i-delete-items-in-pipedrive).

## What changes

- A client gets a `relationshipEndedAt` date. It is stamped when the account
  status moves to inactive and cleared when it moves back to active, by a
  lifecycle action on the schema.
- The client and contact schemas declare a retention term through
  OpenRegister's `archive` configuration: two years after the relationship
  ended, then destroy. A contact person takes the date from its client.
- A client whose relationship never ended gets no destruction date at all, so
  an active client is never listed.
- A new settings page, Retention review, lists OpenRegister's destruction
  lists for clients and contact persons, and lets a records officer approve or
  reject each list.
- Existing clients and contact persons get their retention metadata by a
  repair step, once.
- Each schema declares which of its properties are personal data, as an
  anonymisation profile, so a reviewer can answer anonymise once OpenRegister
  offers that answer.

## Out of scope

- The P100Y term on request and complaint tickets. It is a separate DPO
  decision, recorded in `99-unify-ticket-supertype.json`.
- A term that a functional administrator edits on a screen. The term is
  declared in the register; changing it is a schema edit in OpenRegister.
- Deleting a client by inactivity with no review, as HubSpot does. Destroying a
  client is irreversible, so a person approves each list.
- Building the anonymise act. OpenRegister owns it (its change
  `anonymising-as-an-archival-outcome`).

## Impact

- `lib/Settings/pipelinq_register.json` (contact) and
  `lib/Settings/register.d/15-unify-client-contact.json` (client): the
  `relationshipEndedAt` property, a lifecycle on `accountStatus`, and an
  `archive` block on each schema.
- New custom page RetentionReview in `src/manifest.d/`, placed in the settings
  section by `src/menu-layout.json`.
- New repair step `lib/Repair/BackfillClientRetention.php`, registered in
  `appinfo/info.xml`.
- Cross-repo: two OpenRegister changes, named in design.md D3 and D6.
