# Proposal: marketing-integrated-campaigns

Phase 6 of the marketing programme (`docs/Technical/marketing-architecture.md`), and the last one. The bookkeeping becomes a source of audiences, an audience becomes a journey that runs itself, and Monday morning starts with a page that says what moved.

Five phases already shipped. Lists and double opt-in (#1767), transports (#1774), the article hub, social publishing (#1789) and campaigns with attribution (#1786) are live. This change joins them to each other and to shillinq. It adds no second ledger, no second consent gate and no second scheduler.

## Problem

1. **A marketer cannot segment on what a customer is worth.** Every rule in the segment builder reads a CRM field. Revenue, the last invoice, what was bought and whether the invoice was paid all live in shillinq, and the builder cannot see any of it. "Everyone who has not bought in a year" is a question the tenant can answer in the bookkeeping and cannot ask in the marketing tool.
2. **Every segment starts from an empty rule tree.** The five audiences a B2B marketer builds first are the same five in every tenant. Each one is rebuilt by hand, differently, and the differences are invisible until a mailing goes to the wrong people.
3. **A campaign is one send.** There is no way to say "wait five days, then, if they have not replied, send the follow-up". A marketer who wants that today schedules the second blast by hand and prunes the recipients by hand, or asks an engineer for a cron job.
4. **A late payer receives the promotion.** Consent gating asks whether a contact may be mailed. Nothing asks whether mailing this contact right now is a good idea while a reminder for an unpaid invoice is in the post.
5. **Nobody reads the numbers.** The campaign report, the social metrics and the search queries each render on their own page. There is no weekly reading of the three together, so a mailing that underperformed for four weeks is noticed in the fifth.

## Solution

1. **Signals as segment fields.** `SegmentSignalService` publishes a catalogue of eight derived fields the rule builder can use next to the schema's own properties: recognised revenue, a value tier, months since the last invoice, purchased products, purchased services and dunning state from shillinq, plus days to the nearest contract renewal and days a lead has sat in its stage from pipelinq itself. Every shillinq read goes through `ShillinqInvoiceReader`, which is read-only by construction. Pipelinq stores no money (ADR-107).
2. **A signal that cannot be resolved shrinks the audience, never widens it.** A leaf on a signal field that resolves to nothing evaluates to false whatever the operator, and `isNull` is the one exception. Without that rule `monthsSinceLastInvoice >= 12` matches every customer on an instance without shillinq, because the evaluator's numeric comparison returns 0 for a value it cannot read.
3. **Five standard audiences, seeded and copyable.** Lapsed customers, top-tier customers, one product without another, contracts renewing inside ninety days, and leads stalled in a stage for thirty days. They ship as `segment` objects in the register fragment, so a marketer opens one, reads the rule tree and saves a copy.
4. **Journeys are OpenRegister flows.** A `journey` names a trigger, a wait, a condition and an action. `JourneyFlowCompiler` turns it into a flow document that OpenRegister's flow engine owns and runs (ADR-094). Pipelinq writes no scheduler and no tick job. The action step is a pipelinq node the engine calls back into, so the send passes the same `ComplianceService` gate an ordinary blast passes. A journey that would send to a contact without consent stops, names the contact, and records why.
5. **Suppression sits inside the consent gate.** `ComplianceService` learns a send intent. A promotional send skips a contact whose customer is in dunning; a service message is unaffected. It is one more reason the existing gate can return, not a second rule engine beside it.
6. **A weekly review agent that only reads.** Pipelinq composes the numbers from campaigns, social publications and search queries, and the page is a card on the Reports page. A hermiq agent template reads them and delivers one page as a Talk message through hermiq's own schedule. It has no send tool and no publish tool. It also has no write tool: pipelinq ships no MCP tool provider, so the ADR-088 mark has storage and a renderer and no writer yet, and the change says so rather than shipping a stamp nothing can apply.

## Scope

- New schemas `journey`, `journeyRun` and `weeklyReview`, and the five seeded standard audiences, in `lib/Settings/register.d/99-marketing-integrated-campaigns.json`.
- New services `SegmentSignalService`, `BookkeepingSignals`, `ShillinqInvoiceProjection`, `JourneyService`, `JourneyFlowCompiler`, `JourneyStepRunner`, `WeeklyReviewService` and `WeeklyReviewNumbers`. Suppression is a reason `ComplianceService` returns, not a service of its own.
- `ShillinqInvoiceReader` gains one read method for the whole invoice picture. Its existing paid-invoice read is untouched.
- `SegmentService` merges the signal catalogue into the validator's property map and asks the signal service for a signal leaf's value.
- `ComplianceService` gains an optional send intent and reports suppressed contacts next to contacts without consent.
- New routes under `/api/journeys` and `/api/weekly-review`.
- Manifest fragment `79-marketing-journeys.json` with the Journeys pages, and one card on the Reports page.

## Out of scope

- A journey canvas. A journey is authored as a form over its steps. Drawing it belongs to OpenRegister's flow canvas, which already exists and already renders the compiled flow.
- Recomputing revenue, writing to shillinq, or caching an amount on a pipelinq object. Money has one home.
- Branching journeys with more than one condition per step. One trigger, one wait, one condition, one action is the shape phase 6 commits to.
