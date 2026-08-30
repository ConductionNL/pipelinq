# Tasks: 10 Docs

## CHANGELOG (Task 5.1 of giant)

- [x] Add entry under "Unreleased" / next version to `CHANGELOG.md`
  - Added under `## [Unreleased] → ### Added` as
    `Marketing segmentation and blast campaigns
    (marketing-segmentation-and-blast, 11-slice chain — this entry
    covers the user-visible feature; slice 10 ships docs, slice 11
    the manual verification + pre-merge review checklist)`.
- [x] Summary: marketing segmentation and blast campaigns (rule-based segments, multi-channel email/SMS, GDPR/CAN-SPAM compliance, A/B testing, revenue attribution)
  - Top-line summary covers all five capabilities; sub-bullets
    reference the concrete services (`SegmentService`, `BlastService`,
    `ComplianceService`, `AttributionService`), schemas (`segment`,
    `blast`, `campaignTemplate`), the `BlastSendJob` dispatcher and
    the provider-webhook ingest.
- [x] Highlight: segment builder, real-time delivery tracking, consent gating, attribution
  - Sub-bullets list the segment rule builder, the `BlastMonitor`
    live delivery log, the consent preflight + List-Unsubscribe
    routing, and the first-click attribution model with configurable
    window.

## User guide (Task 5.2 of giant)

- [x] Create `docs/user/marketing-blasts.md`
  - English user guide, EUPL-1.2 + © Conduction B.V. header, top
    "at a glance" surface map, six body sections (1 segments,
    2 templates, 3 send wizard, 4 A/B, 5 monitor + attribution,
    troubleshooting + see-also).
- [x] Section 1: creating segments with rule builder
  - Section 1 — open the builder, compose AND/OR groups of leaf
    predicates (field × operator × value, type-checked against the
    entity's JSON schema), `Estimate size` + cached audience count,
    `Preview members`, save and reuse across blasts.
- [x] Section 2: creating email + SMS templates with compliance requirements
  - Section 2 — open the editor per channel, merge-field syntax
    (`{{contact.firstName}}`, `{{unsubscribe_url}}`,
    `{{view_in_browser_url}}`), and a compliance-requirements table
    enumerating the email/SMS rules (`{{unsubscribe_url}}`, physical
    address, List-Unsubscribe header, `STOP` keyword, sender
    identity) cross-referenced to CAN-SPAM and GDPR articles.
- [x] Section 3: scheduling and sending blasts
  - Section 3 — six-step wizard (name → segment → template →
    channel → schedule → A/B), connector-source pick when multiple
    providers are configured, consent preflight with the **Missing
    consent** dialog (Cancel / Request consent / Skip and send) and
    the `scheduled` → `sending` state transition.
- [x] Section 4: A/B testing workflows
  - Section 4 — how the deterministic hash-bucket split works
    (single split per blast, same recipient always in the same
    bucket), the 100-delivery-per-arm gating threshold on the **A/B
    Testing** tab, the chi-square significance marker (p < 0.05),
    and the manual "promote the winner" pattern.
- [x] Section 5: monitoring delivery and attribution
  - Section 5 — live monitor view (status + totals + per-delivery
    log fed by HMAC-verified webhooks), the three performance-tab
    KPIs (Overview / A-B / Attribution), the first-click attribution
    model with configurable window (default 30d) and the CSV-export
    button on Overview + Attribution.
- [x] Include UI screenshots; Dutch and English versions
  - Dutch translation lives at `docs/user/marketing-blasts.nl.md`
    with identical structure and the same Conduction B.V. /
    EUPL-1.2 header; both files cross-link to each other. UI
    screenshots are deferred to the journeydoc capture pipeline
    (ADR-030) which owns the asset directory under
    `docs/static/screenshots/`; no screenshot binaries are checked
    in by this docs-only slice to avoid duplicating the capture
    pipeline.
