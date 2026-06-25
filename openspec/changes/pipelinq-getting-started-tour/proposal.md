# pipelinq-getting-started-tour — the empty-env sales journey, end to end

## Why

A new pipelinq user on an empty environment needs to be walked through the core
sales journey once, against real data they create themselves: **product → contact →
lead → move the lead through the pipeline → quote → contract (a signable quote *is*
the contract) → hand off to shillinq for billing.** This change declares that
journey as a `manifest.walkthrough` tour, rendered by the abstract engine
(`cn-walkthrough-engine`, ADR-043). It is the first real consumer of the engine and
the reference example for the fleet.

## What changes

1. **`manifest.walkthrough` block** on pipelinq's base manifest with one
   `getting-started` tour, `trigger: first-visit`, all steps `sinceVersion: 1.0.0`,
   each spotlighting a real pipelinq element and gating advance on the real action:

   1. **Welcome** (`info`, centered) — what we'll build together.
   2. **Create a product** — spotlight the `Products` menu item → land on `Products`
      → spotlight the add button → `advanceOn: object-created (product)`,
      `capture: { productId }`.
   3. **Create a contact** — `Contacts` → add → `object-created (contact)`,
      `capture: { contactId }`.
   4. **Create a lead** — `Leads` → add → `object-created (lead)`,
      `capture: { leadId }`; then route to `LeadDetail` capturing `:id`.
   5. **Move the lead through the pipeline** — spotlight the `Pipeline` board → task:
      drag the lead to the next stage → `advanceOn: object-created`/stage-change.
   6. **Create a quote** — from the lead, create the quotation; spotlight the quote
      action → `object-created`/route capture `quoteId`.
   7. **Quote = contract** — explain that a signable quotation *is* the contract;
      spotlight `Contracts` / the sign action; capture `contractId`.
   8. **Send to shillinq for billing** — cross-app hand-off step: deep-link to
      shillinq with a resume token so the tour finishes there (the billing leg),
      using the engine's cross-app primitive.

2. **`data-walkthrough-id` instrumentation** on the handful of pipelinq elements the
   tour targets that lack a stable manifest identity (add buttons, the sign action,
   the pipeline board), reusing/`data-testid` where journeydoc already added it.

3. **i18n** — tour copy (titles, bodies, tasks) as `pipelinq.tour.*` keys in
   `l10n/en.json` + `l10n/nl.json`.

4. **Restart entry** — the abstract "Replay walkthrough" entry lists this tour
   (no per-app work beyond declaring it).

## Non-goals

- The engine, schema, editor — changes 1 and 2.
- shillinq's side of the billing leg beyond the hand-off target (a follow-up may add
  a short shillinq-side continuation tour keyed to the same resume token).
- Seeding demo data — the tour drives the user to create real records (empty-env
  journey), with the engine's manual-Next escape hatch when they deviate.

## Consumer impact

pipelinq-only manifest addition + a few `data-walkthrough-id`s. Depends on
`cn-walkthrough-engine` being released. Backward compatible (walkthrough is opt-in).
