---
status: in-progress
---

# Portal Contribution Specification

**Spec refs**: hydra ADR-046 (portaliq external portal, contract v2), ADR-005 (server-derived subject), ADR-022 (apps consume OR abstractions)
**Change**: `openspec/changes/portal-contribution/` (Wave 1 — tracking issue Conduction/pipelinq#343; requirement text below mirrors the in-flight change delta and is authoritative once the change archives)

## Purpose

Declare pipelinq's contribution to portaliq, the ONE shared external portal for
people without Nextcloud accounts: which OpenRegister collections a `client`
(B2B org contact) or `customer` (B2C) subject may read, and which whitelisted
create-actions they may perform. The contribution is a single dependency-free
class discovered by convention FQCN and duck-typed by portaliq — pipelinq is
inert without portaliq installed. The claim-names contract
(`claims.pipelinq.clientId` / `contactId` / `customerUid`), the
schema→scopeField→claim scoping map, and the internal-data exclusions
(contactmoment, booking, berichtenboxMessage) are specified in the change's
`design.md` and are STABLE once shipped.

## Requirements

### Requirement: Dependency-Free Provider Discovery

pipelinq MUST expose exactly one portal contribution class at the convention FQCN `OCA\Pipelinq\Portal\PortalContributionProvider`. The class MUST be plain and dependency-free: no portaliq imports, no `implements` clause, no info.xml dependency, no constructor dependencies — portaliq duck-types it via `method_exists()`, and without portaliq installed the class MUST be inert (pipelinq behaves exactly as before). It MUST implement both `getAudiences(): array` (contract v2) and `getAudience(): string` (contract v1 fallback returning the primary audience).

#### Scenario: Provider is discoverable and inert without portaliq

- **WHEN** the class `OCA\Pipelinq\Portal\PortalContributionProvider` is constructed directly (no container, no portaliq)
- **THEN** construction MUST succeed without any portaliq class being loadable
- **AND** the class MUST declare no constructor parameters and reference no portaliq symbol
- `@e2e exclude` discovery is portaliq-side; pipelinq-side inertness is pinned by direct-construction PHPUnit tests

#### Scenario: Audiences advertised on both contract versions

- **WHEN** portaliq probes the provider
- **THEN** `getAudiences()` MUST return `['client', 'customer']`
- **AND** `getAudience()` MUST return `'client'` (the primary audience) for v1 registries
- `@e2e exclude` pure data contract with no UI in pipelinq; asserted by PHPUnit

### Requirement: Client Audience Contribution

For a subject with `audience = 'client'`, `getContribution()` MUST return a manifest whose collections expose exactly `request`, `complaint`, and `contract` from the `pipelinq` register — scoped by `client`, `client`, and `clientRef` respectively, all via `scopeClaim: "clientId"` (the pipelinq `client` object UUID) — and whose actions whitelist exactly two `create` actions: `request` with fields `title`, `description`, `category` and `complaint` with fields `title`, `description`, `category`. No action may expose `status`, assignment, pipeline/queue, or SLA fields.

#### Scenario: Client sees org-scoped read collections

- **GIVEN** a resolved subject with `audience = 'client'`
- **WHEN** `getContribution($subject)` is called
- **THEN** the manifest MUST contain collections for `request` (scopeField `client`), `complaint` (scopeField `client`) and `contract` (scopeField `clientRef`), each with `scopeClaim` `clientId`, register `pipelinq`, `listable: true`
- **AND** it MUST NOT contain a `contactmoment` collection (internal `notes` would leak — see the change design.md exclusions)
- `@e2e exclude` portal rendering happens in portaliq, not pipelinq CI; manifest shape pinned by PHPUnit

#### Scenario: Client create actions are conservative whitelists

- **GIVEN** a resolved subject with `audience = 'client'`
- **WHEN** `getContribution($subject)` is called
- **THEN** the manifest actions MUST be exactly `create request` (fields `title`, `description`, `category`) and `create complaint` (fields `title`, `description`, `category`)
- **AND** no action field list may include `status`, `assignee`, `assignedTo`, `priority`, `pipeline`, `queue`, `stage`, or any SLA property
- `@e2e exclude` whitelist is declarative data enforced portaliq-side; pinned by PHPUnit

### Requirement: Customer Audience Contribution

For a subject with `audience = 'customer'`, `getContribution()` MUST return a manifest whose collections expose exactly `avgVerzoek` (scopeField `verzoekerContact`, `scopeClaim: "contactId"`, `minTrust: "substantial"`) and `customerLoyaltyAccount` (scopeField `customerId`, `scopeClaim: "customerUid"`) from the `pipelinq` register, and whose actions whitelist exactly one `create` action: `avgVerzoek` (DSAR intake) with fields `artikel`, `specifiekeVraag`, `scope`. Lifecycle, handler, deadline and verification properties MUST stay server-side.

#### Scenario: Customer sees own DSAR and loyalty surfaces

- **GIVEN** a resolved subject with `audience = 'customer'`
- **WHEN** `getContribution($subject)` is called
- **THEN** the manifest MUST contain an `avgVerzoek` collection scoped by `verzoekerContact` via claim `contactId` with `minTrust` `substantial`
- **AND** a `customerLoyaltyAccount` collection scoped by `customerId` via claim `customerUid`
- **AND** it MUST NOT contain `booking` (staff-only `internalNotes` — see the change design.md) or any `berichtenboxMessage` inbox (BSN-scoped, not a contact/customer UUID ref)
- `@e2e exclude` portal rendering happens in portaliq, not pipelinq CI; manifest shape pinned by PHPUnit

#### Scenario: DSAR intake is the only customer action

- **GIVEN** a resolved subject with `audience = 'customer'`
- **WHEN** `getContribution($subject)` is called
- **THEN** the manifest actions MUST be exactly `create avgVerzoek` with fields `artikel`, `specifiekeVraag`, `scope`
- **AND** the field list MUST NOT include `status`, `behandelaar`, `verzoekerBsn`, `verzoekerBsnGeverifieerd`, `wettelijkeTermijnVerloopt`, or any retention/outcome property
- `@e2e exclude` whitelist is declarative data enforced portaliq-side; pinned by PHPUnit

### Requirement: Fail-Closed Contribution

`getContribution()` MUST return `null` for any subject whose `audience` is not one pipelinq serves (including a missing audience key), and MUST branch only on server-derived subject data (`subjectRef`, `audience`, `organisation`, `trust`) — never on client-supplied input. The manifest MUST NOT contain `endpoint` actions in Wave 1.

#### Scenario: Unknown audience yields null

- **WHEN** `getContribution()` is called with `audience` `'supplier'`, an empty subject, or no audience key
- **THEN** it MUST return `null` in every case
- `@e2e exclude` negative-path data contract; asserted by PHPUnit

#### Scenario: No endpoint actions in Wave 1

- **WHEN** `getContribution()` is called for any served audience
- **THEN** every declared action MUST have `type = 'create'` (receiver-side assertion verification does not exist yet)
- `@e2e exclude` static manifest property; asserted by PHPUnit
