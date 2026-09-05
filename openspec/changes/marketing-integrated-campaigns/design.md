# Design: marketing-integrated-campaigns

Phase 6 joins five shipped phases to each other and to the bookkeeping. Every decision below is about not building a second copy of something that already exists.

## Decision 1: signals are derived at read time, never stored

A value tier could be a property on `client`, recomputed nightly. It is not, for two reasons.

The first is ADR-107. A tier is a fact about money, and money has one home. A tier written onto a pipelinq object is a second copy of shillinq's answer, and the day the two disagree the marketing tool is the one that is wrong and the one nobody checks.

The second is that a stored tier goes stale silently. A nightly job that fails leaves last week's number in place, looking exactly like this week's. A derived field that cannot be read reports that it cannot be read.

The cost is real: evaluating a rule tree over every contact asks the bookkeeping once per customer. `SegmentSignalService` memoises per client for the life of the request, which is what turns "once per contact" into "once per customer".

## Decision 2: an unresolved signal is false, and this is the most important line in the change

`SegmentService::compareNumeric()` returns 0 when either side is not numeric. That is a sensible fail-closed choice for `gt` and `lt`. It is a disaster for `gte`, `lte` and `between`, which all read 0 as a match.

So on an instance without shillinq, the rule "no invoice in twelve months" would match **every single customer**, and the resulting send list would look exactly like a correct one. Nothing would error. The mailing would go out.

`evaluateSignalLeaf()` therefore asks whether the signal resolved before it asks the operator anything, and returns false when it did not. `isNull` is the one exception, because "this customer has no bookkeeping" is a real question.

The same latent bug exists for ordinary schema fields that are absent on an entity. It is not fixed here: changing it would silently change the meaning of every segment already saved in every tenant. It is recorded as a deferred question instead.

## Decision 3: a journey is compiled, not interpreted

ADR-094 says new automation targets OpenRegister's flow engine. The tempting shortcut is a `JourneyRunJob` that ticks every five minutes and walks pending waits. It would work, and it would be a second scheduler with its own idea of time, its own retry semantics and its own audit trail.

Instead `JourneyFlowCompiler` produces a flow document and OpenRegister runs it. Three specifics that cost time to get right:

1. **A condition lives on the node's `exits`, not on the edge.** The edge only carries `fromExit`. A condition written onto an edge saves, validates, and then takes every branch.
2. **A switch needs an else exit.** Without the `skip` exit an item the condition rejected has nowhere to go and the engine drops it silently.
3. **A schedule trigger needs an explicit `runAs`.** The flow's owner is not a fallback there, so a scheduled journey without one never fires.

The action step is `pipelinq.journey-action`, contributed to the engine's registry on `RegisterFlowNodesEvent`. That is the only sanctioned way to run leaf-app PHP inside a flow: there is deliberately no code node and no webhook node. The listener is registered without a `class_exists` guard, because `::class` is a compile-time string that autoloads nothing and the listener is only constructed when the event fires, which only happens where OpenRegister is present. An over-eager guard there is how a registry loses nodes.

## Decision 4: a journey send reuses the blast ledger

A journey could send through the transport directly and record nothing. Then the click redirect would not know the message, the unsubscribe link would have no membership to withdraw, and the campaign report would count the send as absent.

So a journey owns one `blast`, created the first time it sends, and every journey send is a `blastDelivery` against it. `MailTransportService::sendOneDelivery()` is the same per-recipient path an ordinary blast uses.

That blast must carry `segmentId`. It is on the blast schema's required list, and OpenRegister refuses an object that misses a required key and the write is dropped without an error. The key is therefore written even when it is empty, which is exactly how the list-targeted blasts phase 1 shipped already behave.

## Decision 5: suppression is a reason the consent gate returns

Late-payer suppression is not a compliance question. The tenant is allowed to mail these people; it has decided not to while an invoice is being chased.

It still belongs inside `ComplianceService`, as a second reason `permitsSend()` can refuse, rather than as a `SuppressionService` beside it. A second rule engine is a second place to forget a rule, and the rule being forgotten here is "do not send the promotion to the person we are chasing for money".

`compliant` stays keyed on lawful basis alone. Collapsing suppression into it would report a perfectly lawful campaign as non-compliant, and a compliance flag that cries wolf gets ignored.

## Decision 6: the agent gets the numbers, not the sending

The weekly review has two halves. Pipelinq composes the facts: what went out, what was clicked, what search showed. A hermiq agent may write the prose.

The agent template grants `openregister.searchObjects` and nothing else. No send tool, no publish tool. Its output reaches a person as a Talk message through hermiq's own schedule delivery, and the review it read is a card on the Reports page.

The template is seeded by a repair step rather than by a register fragment. Seeding into a foreign register through `components.objects[]` needs that register to exist at import time, and hermiq is an optional peer: a missing register would take pipelinq's own register import down with it, which is far worse than a template nobody received.

## Decision 7: what phase 5 did not bring

`watchEvent` and `competitorWatch` are not on `development`. The review therefore reads blasts, touchpoints, social publications and Search Console rows, and lists `watchEvent` under `degraded`.

Naming the absent source rather than counting it as zero is the whole discipline here. "0 competitor moves" is a number a reader believes.

## What the demo data can and cannot prove

Every seeded client's `shillinqOrganisationRef` is a nil-UUID placeholder, put there by `91-time-billing-handoff.json` with a note saying to replace it. So even on a workstation with shillinq installed, the six bookkeeping signals resolve to nothing against the demo data. That is correct behaviour and it is what the standard audiences' descriptions say.

The two pipelinq signals do resolve. The demo contract ending in 45 days matches the renewing-within-ninety-days audience and the one ending in 245 days does not, and `tests/Unit/Service/Marketing/StandardAudiencesTest.php` proves it by reading the seeded rule trees and the demo data itself rather than restating either.
