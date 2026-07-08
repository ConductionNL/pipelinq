# Spec: beta-alignment (Pipelinq)

## ADDED Requirements

### Requirement: Marketing and docs surfaces SHALL only claim code-verified features
Pipelinq's public product page (conduction.nl) and `docs/intro.md` SHALL only
describe integrations, third-party connectors, and named data structures that
can be traced to actual code in `lib/` or `src/`. Any such claim without a
traceable implementation is a beta blocker and MUST be removed or corrected
before it ships.

#### Scenario: Product page feature claim traced to code
- **WHEN** the product page or `docs/intro.md` asserts a feature (e.g. "quotes
  become the contract", "contactmomenten", "scheduled CSV export")
- **THEN** a corresponding controller, service, schema, or manifest page
  exists in the repository at HEAD that implements it.

#### Scenario: Unverifiable integration claim is removed
- **WHEN** a marketing surface claims an integration with a named third-party
  product or app (e.g. an accounting system, a document-signing app, a BI
  app)
- **THEN** that claim is removed unless a grep of `lib/`/`src/` finds the
  corresponding client, service, or dependency wiring it in.

### Requirement: info.xml version, dependency, and platform claims SHALL be internally consistent
`appinfo/info.xml` is the source of truth for the app version, licence, and
supported Nextcloud version range. Product-page and docs surfaces referencing
these values SHALL match `info.xml`, and any app-to-app dependency implied by
marketing copy (e.g. "Requires: OpenRegister") SHALL be documented in
`info.xml`'s `<dependencies>` block, even where the XML schema requires this
as a comment rather than a machine-checked tag.

#### Scenario: Docs Nextcloud version matches info.xml
- **WHEN** `docs/installation.md` (or any other doc) states a minimum
  Nextcloud version
- **THEN** it matches `<nextcloud min-version="..." max-version="...">` in
  `appinfo/info.xml`.

#### Scenario: Claimed app dependency is documented in info.xml
- **WHEN** the app description or product page states the app requires
  another Conduction/Nextcloud app to function
- **THEN** `appinfo/info.xml`'s `<dependencies>` block carries a comment
  naming that dependency and the code location that proves it (since
  app-info.xsd has no native cross-app dependency tag).
</content>
