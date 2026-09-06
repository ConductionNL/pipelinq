---
status: in-progress
---

# marketing-lists Specification

**Spec refs**: `docs/Technical/marketing-architecture.md` (rule 1, unsubscribes are ours; rule 4, no secret on an OpenRegister object), hydra ADR-031 (declare it in the schema where an extension fits), ADR-064 (secrets live in the broker, not in objects), ADR-082 (a public endpoint is throttled, and the attribute alone does nothing), ADR-108 (which public surface stays on the leaf app)

**OpenSpec changes**:
- `marketing-lists-and-double-opt-in` (in progress) — establishes the capability: the `mailingList` and `subscription` schemas, the signed confirm, unsubscribe and preference endpoints, the soft opt-in import, and a mailing list as a blast audience. The requirement text below mirrors the in-flight change delta and becomes authoritative once that change archives.

## Purpose

Mailing lists a person can join and leave on their own. A list holds its own sender identity and opt-in mode, a subscription records one person's membership with the ground it rests on, and four signed public endpoints let a recipient subscribe, confirm, unsubscribe and manage preferences without a Nextcloud account.

## Requirements
### Requirement: A Mailing List Carries Its Own Sender Identity and Opt-In Mode

A mailing list SHALL carry the sender name, sender address, reply-to address and postal footer used for every mailing sent to it, and an opt-in mode of either `double` or `soft`. New lists SHALL default to `double`. A list SHALL declare whether it accepts public signup; a list that does not SHALL refuse the public subscribe endpoint.

#### Scenario: A new list defaults to double opt-in

- **WHEN** a marketer creates a mailing list without naming an opt-in mode
- **THEN** the list SHALL be stored with `optInMode` `double`
- **AND** its status SHALL be `active` and its subscription counts SHALL be zero

#### Scenario: Public signup is refused on a closed list

- **GIVEN** a mailing list with `publicSignup` false
- **WHEN** the public subscribe endpoint is called for that list
- **THEN** the response SHALL be 404 and no subscription SHALL be created

#### Scenario: A list without a postal footer cannot be created

- **WHEN** a marketer creates a mailing list with no `footerAddress` and no `senderEmail`
- **THEN** the request SHALL be refused with a validation error naming the missing field
- **AND** no mailing list SHALL be stored

### Requirement: Self-Service Subscribe Creates a Pending Subscription

A public subscribe SHALL create a subscription in state `pending`, never `confirmed`, and SHALL send exactly one confirmation mail carrying a signed, single-use confirmation link. The endpoint SHALL answer the same way whether the address is new, already pending or already confirmed, so it cannot be used to test whether an address is on a list. A submission that fills the honeypot field SHALL be discarded silently.

#### Scenario: Subscribe creates a pending subscription and mails the link

- **GIVEN** a mailing list with `publicSignup` true and `optInMode` `double`
- **WHEN** the public subscribe endpoint receives an address that is not yet on the list
- **THEN** a subscription SHALL be stored with `state` `pending`, the submitted address and source `public-signup`
- **AND** one confirmation mail SHALL be sent to that address
- **AND** the response SHALL be 202 with a message that does not reveal whether the address was already known

#### Scenario: The honeypot field discards an automated submission

- **GIVEN** a mailing list open to public signup
- **WHEN** the subscribe endpoint receives a submission whose honeypot field is not empty
- **THEN** no subscription SHALL be created and no mail SHALL be sent
- **AND** the response SHALL be the same 202 an accepted submission receives

#### Scenario: Subscribing twice does not create a second membership

- **GIVEN** a subscription already in state `pending` for an address on a list
- **WHEN** the same address subscribes again
- **THEN** the existing subscription SHALL be reused and a fresh confirmation link SHALL be issued
- **AND** the list SHALL still hold exactly one subscription for that address

### Requirement: A Confirmation Token Is Verified Before a Subscription Is Confirmed

Confirmation SHALL require a token whose signature verifies and whose nonce matches the digest stored on the subscription. The subscription SHALL store only the digest, never the token. A token that is malformed, expired, wrongly signed or already used SHALL leave the subscription untouched. On success the subscription SHALL move to `confirmed`, the stored digest SHALL be cleared so the link cannot be replayed, and a consent record SHALL be written for that contact, channel and list.

#### Scenario: A valid token confirms the subscription and writes consent

@e2e exclude the confirmation link arrives by mail and the CI instance sends none, and nothing in the product mints that link for an authenticated caller — only the subscriber's own mail carries it, which is the whole point of double opt-in. A browser run can create the pending subscription and can watch a forged token be refused, but it cannot hold a valid one. Asserted by tests/Unit/Service/SubscriptionServiceTest.php (testConfirmMovesToConfirmedAndWritesConsent, testSubscribeCreatesPendingAndMailsOneLink, testSubscribeStoresADigestNotTheToken) and tests/Unit/Service/ListTokenServiceTest.php (testConfirmTokenRoundTrip).

- **GIVEN** a subscription in state `pending` whose confirmation link has not been used
- **WHEN** the confirm endpoint receives that link
- **THEN** the subscription SHALL move to `confirmed` with `confirmedAt` set
- **AND** a consent record SHALL exist for that contact and list with lawful basis `consent` and source `double-opt-in`
- **AND** a confirmation page SHALL be rendered naming the list

#### Scenario: A tampered token confirms nothing

- **GIVEN** a subscription in state `pending`
- **WHEN** the confirm endpoint receives a token whose payload was edited after signing
- **THEN** the response SHALL be 410 and the subscription SHALL still be `pending`
- **AND** the rejected attempt SHALL be registered with the throttler

#### Scenario: A confirmation link cannot be used twice

@e2e exclude replaying a link needs a valid link first, and the only copy is in the mail the CI instance never sends (see the scenario above). Asserted by tests/Unit/Service/SubscriptionServiceTest.php (testConfirmationLinkCannotBeSpentTwice, testConfirmRefusesAWrongNonceEvenWhenWellSigned).

- **GIVEN** a subscription already confirmed through its link
- **WHEN** the same link is opened again
- **THEN** the response SHALL be 410 and nothing about the subscription SHALL change

### Requirement: A Pending Subscription Never Receives a Blast

When a blast names a mailing list as its audience, the recipients SHALL be exactly the subscriptions in state `confirmed` whose list consent has not been withdrawn. A subscription in state `pending`, `unsubscribed` or `bounced` SHALL never be queued.

#### Scenario: Only confirmed subscribers are queued

@e2e exclude queueing a blast dispatches through `OCA\OpenConnector\Service\SourceService`, and the CI instance does not install openconnector — .github/workflows/code-quality.yml pins `additional-apps` to openregister only — so no browser run reaches a send. Asserted by tests/Unit/Service/SubscriptionServiceTest.php (testOnlyConfirmedMembersReachABlast, testConfirmedMemberWithWithdrawnConsentIsSkipped) and tests/Unit/Service/BlastServiceTest.php (testSendBlastQueuesCompliantSkipsNonCompliant).

- **GIVEN** a mailing list holding one confirmed, one pending and one unsubscribed subscription
- **WHEN** a blast targeting that list is sent
- **THEN** exactly one delivery SHALL be queued, for the confirmed subscriber
- **AND** the send summary SHALL report the other two as skipped for want of consent

### Requirement: Unsubscribe Is First Party and Takes One Click

Every mailing SHALL carry an unsubscribe link served by Pipelinq. A GET on that link SHALL render a confirmation page and change nothing. A POST on the same link SHALL move the subscription to `unsubscribed`, record the withdrawal in the consent ledger and answer 200 without a redirect, so an RFC 8058 `List-Unsubscribe-Post` header can name it. An unsubscribe SHALL also be available across every list a contact is on.

#### Scenario: One click unsubscribes and withdraws consent

- **GIVEN** a confirmed subscription and its unsubscribe link
- **WHEN** the link is posted to
- **THEN** the subscription SHALL move to `unsubscribed` with `unsubscribedAt` set
- **AND** the consent record for that contact and list SHALL carry `withdrawnAt` and reason `user-unsubscribed`
- **AND** any queued delivery for that contact SHALL be skipped before it is sent

#### Scenario: Opening the link changes nothing

- **GIVEN** a confirmed subscription and its unsubscribe link
- **WHEN** the link is opened with GET
- **THEN** a page SHALL be rendered naming the list and offering a confirm button
- **AND** the subscription SHALL still be `confirmed`

#### Scenario: A global unsubscribe leaves every list

- **GIVEN** a contact confirmed on three mailing lists
- **WHEN** the unsubscribe link is posted to with the global flag set
- **THEN** all three subscriptions SHALL move to `unsubscribed`
- **AND** a withdrawal SHALL be recorded for each list

### Requirement: Soft Opt-In Records Its Ground and the Objection Offered

An existing customer MAY be added to a list without double opt-in, only on a list whose `optInMode` is `soft`, and only when the import records the lawful ground and states that an objection was offered. An import that does not record the objection SHALL be refused. A soft opt-in subscription SHALL be stored as `confirmed` with lawful basis `soft-opt-in`.

#### Scenario: A soft opt-in import records its evidence

- **GIVEN** a mailing list with `optInMode` `soft` and an existing customer contact
- **WHEN** the import runs with the objection recorded as offered
- **THEN** a subscription SHALL be stored as `confirmed` with `lawfulBasis` `soft-opt-in` and source `soft-opt-in-import`
- **AND** a consent record SHALL carry the same basis, the list id and the evidence naming when and how the objection was offered

#### Scenario: An import without the objection is refused

- **GIVEN** a mailing list with `optInMode` `soft`
- **WHEN** the import runs without recording that an objection was offered
- **THEN** the request SHALL be refused with a validation error and no subscription SHALL be stored

#### Scenario: Soft opt-in is refused on a double opt-in list

- **GIVEN** a mailing list with `optInMode` `double`
- **WHEN** a soft opt-in import is attempted for that list
- **THEN** the request SHALL be refused and the contact SHALL be told to subscribe through the confirmation flow

### Requirement: The Preference Centre Shows and Saves a Subscriber's Lists

A signed preference link SHALL open a page listing every mailing list the contact may hold, each showing whether the contact is on it. Saving SHALL confirm the lists that were selected and unsubscribe the lists that were not, in one call, writing the consent ledger for each change. The page SHALL never reveal a contact identifier or an address belonging to anyone else.

#### Scenario: The preference centre lists the contact's lists

- **GIVEN** a contact confirmed on one list and unsubscribed from another
- **WHEN** the preference link is opened
- **THEN** both lists SHALL be shown with their current state and no other contact's data

#### Scenario: Saving preferences confirms and unsubscribes in one call

- **GIVEN** a contact confirmed on list A and not on list B
- **WHEN** preferences are saved with B selected and A cleared
- **THEN** the subscription on B SHALL be `confirmed` and the subscription on A SHALL be `unsubscribed`
- **AND** a consent record SHALL be written for B and a withdrawal recorded for A

### Requirement: Public List Endpoints Are Throttled and Fail Closed

Every public list endpoint SHALL be reachable without a session, SHALL carry an anonymous rate limit, and SHALL register a rejected token with the brute-force throttler. A missing list, a missing subscription and an unusable token SHALL be answered the same way, so the endpoints cannot be used to enumerate lists or addresses.

#### Scenario: A rejected token is counted

@e2e exclude the counting side is `IThrottler::registerAttempt()`, which writes to Nextcloud's bruteforce table and renders nowhere; a browser run can observe the 410 but not the counter, and driving the throttler to its own limit inside an e2e run would poison the shared CI IP for every later spec. Asserted by tests/Unit/Controller/ListPublicControllerTest.php (testConfirmRejectedTokenRegistersAttempt, testUnsubscribeRejectedTokenRegistersAttempt).

- **WHEN** any public list endpoint receives a token that does not verify
- **THEN** the attempt SHALL be registered with the throttler for the caller address
- **AND** the response SHALL be 410 with a message that names no list and no address

#### Scenario: A missing list answers like a closed one

- **WHEN** the public subscribe endpoint is called with a list id that does not exist
- **THEN** the response SHALL be 404 with the same body a list closed to public signup returns
