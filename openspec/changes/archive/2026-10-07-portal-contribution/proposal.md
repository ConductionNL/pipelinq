# Proposal: portal-contribution

**Tracking issue**: Conduction/pipelinq#343 (Wave 1 of the portaliq fleet rollout — do not close from this change; provider retirement phases live on the same issue)

## Summary

Contribute pipelinq's external surfaces to the shared **portaliq** external portal
(hydra ADR-046 + contract v2, 2026-07-06 amendment) instead of pipelinq hosting its
own bespoke portal. pipelinq ships ONE plain, dependency-free class
`OCA\Pipelinq\Portal\PortalContributionProvider` that declares — for the `client`
(B2B org contact) and `customer` (B2C) audiences — the OpenRegister collections a
portal subject may read and the whitelisted create-actions they may perform.
Portaliq discovers the class by convention FQCN, duck-types it (never
`instanceof`), and serves the collections RBAC-scoped to the subject. Without
portaliq installed the class is inert and pipelinq behaves exactly as before.

## Why

- One shared external portal for people WITHOUT Nextcloud accounts (ADR-046),
  not a portal per app.
- pipelinq keeps ownership of its data and domain logic; it only *declares* what
  a client/customer may see and do. No portal auth, shell, session, or inbox
  logic is added to pipelinq.
- Zero coupling: the provider imports nothing from portaliq, has no `implements`
  clause, no info.xml dependency, and no constructor dependencies.

## Scope (this change — code only)

- `lib/Portal/PortalContributionProvider.php` — the declarative manifest
  (collections + create actions per audience) with the v2 `getAudiences()` and
  v1 `getAudience()` fallback.
- PHPUnit unit tests pinning the manifest shape, scoping map, field whitelists,
  and fail-closed behaviour.
- No register/schema edits: every scoping property used already exists at HEAD
  (`request.client`, `complaint.client`, `contract.clientRef`,
  `avgVerzoek.verzoekerContact`, `klantLoyaltyAccount.klantId`).

## Out of scope (later phases on Conduction/pipelinq#343)

- Retiring pipelinq's bespoke portal (`lib/Controller/Portal*`,
  `lib/Service/Portal/*`) once portaliq renders the contribution.
- `endpoint` actions (receiver-side assertion verification does not exist yet —
  Wave 1 forbids them).
- A portal-safe `booking` projection (excluded this wave: `internalNotes` is
  staff-only and Wave-1 collections have no field projection — see design.md).
- Berichtenbox inbox surface (BSN-scoped, not contact/customer-UUID-scoped —
  see design.md).

## Depends on

- portaliq installed (discovery + rendering side); pipelinq stays inert without it.
- pipelinq's existing register (`pipelinq`) with the schemas listed above.
