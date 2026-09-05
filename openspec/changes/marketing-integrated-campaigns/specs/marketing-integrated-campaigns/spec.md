# Audiences from the bookkeeping, journeys, and a weekly review

**Spec refs**: `marketing-segmentation` (the rule tree this change extends), `marketing-compliance` (the consent gate suppression is added to), `marketing-campaigns` (the campaign report and `ShillinqInvoiceReader`), `marketing-lists`, `marketing-blast-delivery`, ADR-088 (agent-authored artefacts are marked), ADR-094 and ADR-065 (automation targets OpenRegister's flow engine), ADR-107 (money has one home), ADR-112 (reports are one page)
**Standards**: EN 16931 invoice lines, JSONLogic for flow conditions

## ADDED Requirements

### Requirement: The bookkeeping supplies six segment fields and pipelinq stores none of them

`SegmentSignalService` MUST publish six fields derived from shillinq: `shillinqRecognisedRevenue`, `shillinqValueTier`, `shillinqMonthsSinceLastInvoice`, `shillinqPurchasedProducts`, `shillinqPurchasedServices` and `shillinqDunningState`.

Every read MUST go through `ShillinqInvoiceReader`, which has no write path. Pipelinq MUST NOT store an amount, a tier or an invoice on any of its own objects: a tier cached on a client is a second source of truth that goes stale without saying so.

Recognised revenue MUST be the sum of that customer's **paid** invoices over a trailing window, twelve months by default and configurable through `marketing.signal_window_months`. The tier MUST be derived from that number against `marketing.value_tier_top` and `marketing.value_tier_mid`, and MUST be one of `top`, `mid`, `low` or `none`.

Months since the last invoice MUST be counted from the most recent invoice **past the draft stage**, paid or not. A draft is a document nobody sent, so counting it would date a lapsed customer from a note somebody typed.

Purchased products and services MUST be resolved by matching an invoice line's `itemName` against pipelinq's own product catalogue, which already records whether an entry is a product or a service. A line matching nothing MUST contribute to neither list. Guessing from a unit code would put the wrong people in a cross-sell audience and nobody could see why.

The dunning state MUST be the worst state across that customer's invoices, in the order `written-off`, `overdue`, `disputed`, and `current` otherwise.

#### Scenario: Revenue and tier come from paid invoices only

@e2e exclude the CI instance installs openregister and pipelinq and nothing else, so shillinq's register does not exist there and every one of these six fields correctly resolves to nothing; the derivation is asserted against a stubbed reader by tests/Unit/Service/Marketing/SegmentSignalServiceTest.php (testRecognisedRevenueSumsPaidInvoicesInTheWindow, testValueTierFollowsTheConfiguredThresholds).
- **WHEN** a customer has two paid invoices of EUR 20,000 and EUR 8,000 inside the window and one unpaid invoice of EUR 90,000
- **THEN** `shillinqRecognisedRevenue` is 28000 and `shillinqValueTier` is `top`

#### Scenario: A draft invoice does not count as contact

@e2e exclude same reason. Asserted by tests/Unit/Service/Marketing/SegmentSignalServiceTest.php (testDraftInvoicesAreNotContact).
- **WHEN** a customer's only recent invoice is a draft and the last sent one is fourteen months old
- **THEN** `shillinqMonthsSinceLastInvoice` is 14

#### Scenario: An invoice line that matches no catalogue entry is not classified

@e2e exclude same reason. Asserted by tests/Unit/Service/Marketing/SegmentSignalServiceTest.php (testOnlyCatalogueItemsAreClassified).
- **WHEN** an invoice carries a line named `[Demo] Adviesuur`, which the catalogue records as a service, and a line named `Reiskosten`, which the catalogue does not hold
- **THEN** `shillinqPurchasedServices` contains the advice hour and `shillinqPurchasedProducts` is empty, and neither list mentions the travel cost

### Requirement: Two more signals come from pipelinq's own contracts and leads

`SegmentSignalService` MUST also publish `pipelinqContractRenewalDays`, the days until the nearest contract of that customer ends, and `pipelinqStalledLeadDays`, the days the longest-waiting open lead of that customer has sat in its current stage.

A contract that already ended MUST report a negative number rather than nothing, so "renewing in ninety days" and "lapsed last month" are two rules over one field.

Both fields MUST resolve on every instance, because both read collections pipelinq owns. A customer with no dated contract or no dated open lead MUST resolve to nothing rather than to zero.

#### Scenario: The renewal signal counts to the nearest contract end

@e2e exclude a derived field has no rendered form of its own: the browser sees only the audience it produces, and a wrong number and a right one look identical there. Asserted by tests/Unit/Service/Marketing/SegmentSignalServiceTest.php (testRenewalDaysTakesTheNearestContractEnd, testAPastContractEndIsNegative).
- **WHEN** a customer holds one contract ending in 45 days and one ending in 245 days
- **THEN** `pipelinqContractRenewalDays` is 45

#### Scenario: A customer with no dated lead resolves to nothing

@e2e exclude same reason. Asserted by tests/Unit/Service/Marketing/SegmentSignalServiceTest.php (testStalledDaysIsNullWithoutADatedOpenLead).
- **WHEN** a customer's only lead is closed
- **THEN** `pipelinqStalledLeadDays` resolves to nothing, and not to zero

### Requirement: The segment builder lists the signals and validates a rule on one

`SegmentService::validateRules()` MUST accept a rule leaf whose field is one of the eight signals, and MUST validate its operator against the signal's declared type exactly as it validates a rule on a stored property.

The schema MUST win a name collision: the signal catalogue is merged **under** the schema's own properties, so a segment written before this change keeps meaning the stored field.

`GET /api/segments/signals` MUST return the catalogue and an availability report. Listing a field without saying whether the bookkeeping behind it can be read would offer the marketer a rule that saves, validates, and silently matches nobody.

#### Scenario: A rule on a signal validates
- **WHEN** a marketer saves a customer segment whose rule is `shillinqMonthsSinceLastInvoice >= 12`
- **THEN** the segment is accepted, and a rule of `shillinqPurchasedServices > 50` is refused because a list cannot be greater than a number

#### Scenario: The signals endpoint says whether shillinq can be read
- **WHEN** a marketer opens the signals endpoint on an instance without shillinq
- **THEN** all eight fields are listed, and `availability.shillinq` is false with the reason `shillinq_not_installed`

### Requirement: An unresolved signal shrinks the audience

A rule leaf on a signal that resolves to nothing MUST evaluate to false, whatever the operator. `isNull` is the single exception, because "this customer has no bookkeeping" is a question a marketer may legitimately ask.

This rule is not defensive tidiness. `SegmentService`'s numeric comparison returns 0 when either side is not a number, so `gte`, `lte` and `between` all answer TRUE for a value the evaluator could not read. Without this rule, "no invoice for twelve months" matches **every** customer on an instance without shillinq, and the resulting send list looks exactly like a correct one.

#### Scenario: A twelve-month rule matches nobody without shillinq

@e2e exclude the failure this scenario guards is invisible in a browser: a segment that matches everybody and one that matches the right people both render as a list of contacts. Asserted by tests/Unit/Service/SegmentServiceTest.php (testAnUnresolvedSignalIsFalseUnderEveryComparisonOperator).
- **WHEN** shillinq is absent and a segment's rule is `shillinqMonthsSinceLastInvoice >= 12`
- **THEN** no customer matches, and the same rule with `lte`, `between` and `equals` also matches nobody

#### Scenario: isNull still answers

@e2e exclude same reason. Asserted by tests/Unit/Service/SegmentServiceTest.php (testIsNullMatchesAnUnresolvedSignal).
- **WHEN** shillinq is absent and a segment's rule is `shillinqValueTier isNull`
- **THEN** every customer matches

### Requirement: Five standard audiences ship as segments a marketer copies

The register fragment MUST seed five `segment` objects: lapsed customers, top-tier customers, customers of one product without another, contracts renewing within ninety days, and leads stalled in a stage for thirty days.

Each MUST satisfy the segment schema's required list of `name`, `rules` and `entityType`, because OpenRegister refuses an object that does not and the import drops it without an error.

Each description MUST say whether the audience reads shillinq, and therefore whether it resolves to nobody on an instance without it. An example audience that silently matches nothing teaches the marketer that the feature is broken.

#### Scenario: The five audiences are listed and each one names its source
- **WHEN** a marketer opens the Segments page
- **THEN** the five seeded audiences are listed, and the three that read shillinq say so in their description

#### Scenario: The two pipelinq audiences resolve against the demo data

@e2e exclude the CI instance seeds the register fragment but not the demo contracts and leads, whose dates are relative offsets resolved by DemoSeedService at install; the rule trees are therefore evaluated against the demo data itself by tests/Unit/Service/Marketing/StandardAudiencesTest.php (testRenewalAudienceMatchesTheDemoContract, testStalledLeadAudienceEvaluates), which reads both the seeded rules and demo_seed_data.json rather than restating either.
- **WHEN** the renewing-within-ninety-days audience is evaluated against the demo customer holding a contract that ends in 45 days
- **THEN** that customer matches, and the customer whose contract ends in 245 days does not

### Requirement: A journey is an OpenRegister flow and pipelinq ships no scheduler

A `journey` MUST carry a trigger, an optional wait, an optional condition and one action. `JourneyFlowCompiler` MUST turn it into a flow document and `JourneyService` MUST hand that document to OpenRegister's flow service, which owns the timing (ADR-094).

Pipelinq MUST NOT ship a background job, a tick, a queue or a timer for journeys. A wait MUST compile to `openregister.wait` and a bookkeeping-signal trigger MUST compile to `openregister.trigger-schedule` with an explicit `runAs`, because the flow's owner is not a fallback there.

A condition MUST compile to exits on an `openregister.switch` node, never to a condition on an edge: a condition written onto an edge saves, validates, and then takes every branch.

The action MUST compile to `pipelinq.journey-action`, a node type pipelinq contributes to the engine's registry on `RegisterFlowNodesEvent`. That node MUST re-check its own configuration inside `execute()`, because `validateConfig()` only runs when a flow is saved and a seeded or imported flow reaches execution having never passed through that path.

Every compilation outcome MUST be recorded on the journey as `flowStatus`, one of `compiled`, `engine_missing`, `refused` or `not_compiled`, with the engine's own words in `flowError`. A journey that could not be compiled MUST stay inert and MUST NOT fall back to a pipelinq-side loop.

#### Scenario: A journey compiles to a trigger, a wait, a switch and the action node

@e2e exclude the compiled document is never rendered: the browser sees a flow status, and a document with the condition on the wrong element produces the same status as a correct one. Asserted by tests/Unit/Service/Marketing/JourneyFlowCompilerTest.php (testCompilesTriggerWaitSwitchAndAction, testTheConditionLivesOnTheSwitchExitsNotTheEdge, testASkipExitAlwaysReachesTheEnd).
- **WHEN** a journey waits five days after a lead stage change and acts when `status` is `open`
- **THEN** the document carries `openregister.trigger-object` on `lead`, `openregister.wait` for five days, an `openregister.switch` whose `match` exit holds the JSONLogic and whose `skip` exit reaches the end, and `pipelinq.journey-action`

#### Scenario: A bookkeeping-signal journey compiles to a schedule with an identity

@e2e exclude same reason. Asserted by tests/Unit/Service/Marketing/JourneyFlowCompilerTest.php (testABookkeepingSignalCompilesToAScheduleWithARunAs).
- **WHEN** a journey triggers on a bookkeeping signal
- **THEN** the document carries `openregister.trigger-schedule` with a five-field cron and a non-empty `runAs`

#### Scenario: A journey saved without a flow engine says so

@e2e exclude the CI instance runs a version of OpenRegister whose flow engine may or may not carry these node types, so the browser cannot distinguish "no engine" from "an engine that refused"; both paths are asserted by tests/Unit/Service/Marketing/JourneyServiceTest.php (testRecordsEngineMissingWhenTheFlowServiceIsAbsent, testRecordsTheEnginesOwnWordsOnARefusal).
- **WHEN** a journey is saved on an instance whose OpenRegister has no flow service
- **THEN** the journey is stored with `flowStatus` `engine_missing`, and the form says it will not run

#### Scenario: The Journeys page is reachable and lists the journey
- **WHEN** a marketer opens Journeys under Marketing
- **THEN** the page lists journeys with their status and flow status, and the New journey action opens the journey form

### Requirement: A journey refuses a send without consent and names the contact

Every mailing a journey sends MUST pass `ComplianceService::permitsSend()`, the same gate an ordinary blast's audience is filtered by. A journey MUST NOT be able to reach anyone a blast could not.

A refusal MUST be recorded as a `journeyRun` carrying the contact and the reason, one of `no_consent` or `suppressed_dunning`. Skipping quietly would make a journey with no consent look exactly like a journey with a small audience, and the difference would only surface months later in a report that never adds up.

A journey's deliveries MUST be written to the `blastDelivery` ledger against a `blast` the journey owns, so tracking, unsubscribe links and the campaign report keep working on a journey send without a second code path. That blast MUST carry `segmentId`, present even when empty, because it is on the blast schema's required list and an absent key is refused without an error.

A `createTask` action MUST NOT consult the consent gate: a task is internal work, not a message to the contact.

#### Scenario: A contact without consent is refused by name

@e2e exclude a journey send needs the flow engine to fire a trigger and a mail transport to accept a message, and the CI instance has neither openconnector nor a mail sink. Asserted by tests/Unit/Service/Marketing/JourneyStepRunnerTest.php (testRefusesASendToAContactWithoutConsent, testRecordsTheContactAndTheReasonOnTheRun).
- **WHEN** a journey's mailing step reaches a contact with no lawful basis on the email channel
- **THEN** nothing is sent, and a `journeyRun` records that contact with the state `refused` and the reason `no_consent`

#### Scenario: A task step asks no consent question

@e2e exclude same reason. Asserted by tests/Unit/Service/Marketing/JourneyStepRunnerTest.php (testATaskStepDoesNotConsultTheConsentGate).
- **WHEN** a journey's action is `createTask` and the contact has no consent
- **THEN** the task is written and the run records `task-created`

#### Scenario: The run log names refusals in words
- **WHEN** a marketer opens a journey and reads what it did
- **THEN** each refused contact is listed with the reason in words, not as a stored code

### Requirement: A promotional send skips a customer in dunning

`ComplianceService` MUST learn a send intent. `permitsSend()` MUST refuse a **promotional** send to a contact whose customer is in a suppressing dunning state, and MUST return the reason `suppressed_dunning`. A **service** message MUST never be suppressed.

The suppressing states MUST default to `overdue` and `written-off` and MUST be configurable through `marketing.suppression_states`. The whole rule MUST be switchable off through `marketing.suppress_late_payers`.

Suppression MUST live inside the consent gate, not beside it. A second rule engine is a second place to forget a rule, and forgetting this one mails somebody who is being chased for money.

`checkSegmentCompliance()` MUST report suppressed contacts separately from contacts without consent, and `compliant` MUST stay keyed on lawful basis alone: a contact the tenant MAY mail and chose not to is not a compliance failure, and collapsing the two would report a lawful campaign as non-compliant.

A dunning state that cannot be read MUST NOT suppress. Refusing to mail everybody the moment shillinq is uninstalled is worse than mailing a late payer once.

#### Scenario: A promotional blast skips the late payer and a service message does not

@e2e exclude suppression needs shillinq's invoices, which the CI instance does not have, so every contact there correctly resolves to no dunning state at all. Asserted by tests/Unit/Service/ComplianceServiceTest.php (testAPromotionalSendIsSuppressedForAnOverdueCustomer, testAServiceMessageIsNeverSuppressed, testAnUnreadableDunningStateDoesNotSuppress).
- **WHEN** a contact's customer has an overdue invoice
- **THEN** a promotional send is refused with `suppressed_dunning`, and a service message is allowed

#### Scenario: Compliance separates suppressed from unlawful

@e2e exclude same reason. Asserted by tests/Unit/Service/ComplianceServiceTest.php (testSuppressedContactsAreReportedSeparatelyFromMissingConsent).
- **WHEN** a segment holds one contact without consent and one suppressed contact
- **THEN** `missingConsent` names the first, `suppressed` names the second, and `compliant` is false only because of the first

### Requirement: The weekly review reads three sources and names the one it cannot

`WeeklyReviewService` MUST compose one page per week in a single read: what moved, what to try, and three topic ideas drawn from what changed.

It MUST read blasts, touchpoints, social publications and Search Console rows. It MUST NOT fan out per object before the page renders (pipelinq#1781).

A source it cannot read MUST be listed under `degraded` and MUST NOT be counted as zero. Phase 5's `watchEvent` collection is not on `development`, so the review draws its topic ideas from search queries instead and says which source is absent. Reporting "0 competitor moves" for a collection that does not exist is the kind of number that gets believed.

The review MUST take no action. There is no send path and no publish path on it, which is why the agent that reads it has none either.

`GET /api/weekly-review` MUST return the whole record, and the page MUST be a card on the Reports page rather than a menu entry (ADR-112).

#### Scenario: The review names the source it could not read
- **WHEN** a marketer opens the Weekly review card on the Reports page
- **THEN** the page shows the week, what moved, what to try and the topic ideas, and names `watchEvent` as a source it could not read

#### Scenario: An absent source is not reported as zero

@e2e exclude the browser sees the rendered sentence either way; what this scenario guards is the difference between an empty list and a zero, which only the composed record shows. Asserted by tests/Unit/Service/Marketing/WeeklyReviewServiceTest.php (testAnAbsentSourceIsNamedNotCountedAsZero, testComposesFromOneReadOfEachCollection).
- **WHEN** the review is composed on an instance with no watch events
- **THEN** `degraded` holds `watchEvent`, and no highlight or suggestion claims a competitor number

### Requirement: An agent may write the narrative and is marked as its author

`WeeklyReviewService::recordNarrative()` MUST stamp `agentAuthored` and `agentAuthoredBy` itself and MUST ignore any such value arriving in a request body, because a mark a client can set is not a mark (ADR-088).

The review page MUST show the mark whenever the summary was written by an agent, naming the agent.

#### Scenario: An agent narrative carries its author

@e2e exclude writing a narrative needs hermiq, which the CI instance does not install. Asserted by tests/Unit/Service/Marketing/WeeklyReviewServiceTest.php (testAnAgentNarrativeIsMarkedWithItsAuthor, testTheMarkIsNotTakenFromTheCaller).
- **WHEN** an agent writes the summary for a stored week
- **THEN** the review carries `agentAuthored` true and `agentAuthoredBy` naming the agent, and the page says an agent wrote it

### Requirement: The weekly review ships as an agent template with no send tool

An idempotent repair step MUST seed a `Weekly marketing review` template into hermiq's `agenttemplate` schema, carrying a system prompt, a read-only tool grant and a suggested Monday-morning schedule that delivers to Talk.

The grant MUST contain no send tool and no publish tool. An agent drafts and analyses; a person sends (ADR-088, marketing rule 4).

The step MUST be a no-op when hermiq or OpenRegister is absent, and MUST NOT be a register fragment: seeding into a foreign register needs that register to exist at import time, and a missing one would take pipelinq's own register import down with it.

#### Scenario: The template grants nothing that sends

@e2e exclude the CI instance installs neither hermiq nor a Talk room, so the seed correctly does nothing there. Asserted by tests/Unit/Repair/SeedWeeklyReviewAgentTemplateTest.php (testGrantsOnlyReadOnlyTools, testIsANoOpWithoutHermiq, testIsIdempotentOnASecondRun).
- **WHEN** the repair step runs on an instance with hermiq installed
- **THEN** the template is seeded once with a read-only tool grant, and a second run writes nothing
