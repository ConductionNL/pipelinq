---
kind: code
depends_on: []
---

# Proposal: beta-hydra-gates

## Summary

The development to beta release PR (pipelinq#2012) failed three Hydra gates on
work from the 6 and 7 October review rounds and older work it carried along.
This change makes those gates pass without changing what the app does: an
abstract repair base is marked as held in info.xml, register overlays that
rewrite a property's description also carry its title, and changed frontend
methods get their `@spec` tag.

## Motivation

- gate-98 (repair-step-registration) read the abstract `CreateServiceGroup`
  (pipelinq#2174) as a step nobody registered. It cannot be registered: it is
  the base of `CreatePortalServiceGroup` and `CreateMessagingServiceGroup`,
  which are. `RelinkOrphanedLeads` (pipelinq#2213) was already registered.
- gate-51 (schema-property-titles) counts a property that sets a description
  as a property that documents itself, so it wants a title too. 28 properties
  in `97-lead-line-names-and-score.json` (#2213) and
  `99-zz-form-presentation.json` (#2177) set only a description.
- gate-16 (spec-coverage) found nine changed methods without `@spec`.

## What changes

- `appinfo/info.xml` gets a `hydra-gate-98 held:` marker for
  `CreateServiceGroup`, with the reason.
- The 28 overlay properties carry the title the merged register already gives
  them. The merged register is identical before and after.
- Nine frontend methods get a `@spec` tag (or the file's existing
  `@spec exclude` reason).

## Out of scope

gate-23 (DrainMdmSyncQueue, SemanticHandoffService, TimeBillingHandoffService)
and gate-26 (FeaturesRoadmapView, StoreGallery) fail on older work and stay as
they are.
