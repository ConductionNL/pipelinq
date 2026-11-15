# Delta: unify-client-contact

## ADDED Requirements

### Requirement: Contact and client help text is English source text with a Dutch translation
Every description a contact or client form shows MUST be English source text, with a Dutch translation in `l10n/nl.json`. Engineering rationale MUST live in `x-notes`, which forms do not show. A description the form shows MUST fit inline (120 characters, in both languages), so the form does not cut it at the first sentence.

#### Scenario: The contact create dialog in English
@e2e exclude Asserted in tests/vitest/r6ContactActivityRelationsCopy.spec.js over the merged schema: no visible contact or client description is Dutch, each has a Dutch translation, and the BSN verified and confidentiality texts fit inline.
- **GIVEN** a user whose Nextcloud runs in English
- **WHEN** the user opens the contact create dialog
- **THEN** the "BSN verified" and "Confidentiality" fields MUST show English help text in full
- **AND** a user whose Nextcloud runs in Dutch MUST see the Dutch translation
