# Product Catalog Quoting — Semantic Emit Delta

**Spec refs**: ADR-051 + hydra change `semantic-object-handoff` (verify contract against HEAD at apply time), ADR-048 precedent
**Standards**: Semantic kind URIs `https://openregister.app/ns#Quote`, `https://openregister.app/ns#Invoice`

Note: the quote schema is specced but not yet registered in any `register.d/` fragment at HEAD (Enterprise tier, unbuilt). This delta binds the future implementation, so quotes are born into the ADR-051 contract — no declaration ships until the schema itself does.

## ADDED Requirements

### Requirement: Quote semantic kind declaration and invoicing emit [Enterprise]

When the `quote` schema is registered (per the Quote entity requirement of this capability), it MUST declare that it implements the semantic kind `https://openregister.app/ns#Quote` per the ADR-051 declaration dialect, and the quote lifecycle MUST offer a "Send to invoicing" emit from status `geaccepteerd` to whichever installed app implements `https://openregister.app/ns#Invoice`, via OR's `SemanticTypeResolver` + the `x-openregister-handoff` dialect with field mappings per the hydra contract (line items→lines, total→amount, currency, client→customer, quoteNumber + uuid→provenance). The emit MUST be kind-addressed (no hard-coded app id), the action MUST be hidden when no implementer is installed, and a failed handoff MUST NOT mutate the quote.

**Feature tier**: Enterprise

#### Scenario: Registered quote schema carries the declaration

- WHEN the `quote` schema is registered by the quoting implementation
- THEN it MUST carry the `ns#Quote` implements declaration in the ADR-051 dialect form

#### Scenario: Accepted quote handed to the invoice implementer

- GIVEN an installed app implementing `ns#Invoice`
- AND a quote in status `geaccepteerd`
- WHEN the user triggers "Send to invoicing"
- THEN the target invoice object MUST be created through OR's handoff engine with the mapped fields
- AND the quote MUST record the handoff provenance link

#### Scenario: Hidden without an implementer

- GIVEN no installed app implements `ns#Invoice`
- WHEN the user views an accepted quote
- THEN the "Send to invoicing" action MUST NOT be rendered
- AND a direct endpoint call MUST be refused with a not-available error
