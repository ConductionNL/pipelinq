## Purpose

One piece of content, shaped per network, approved by a person, and published to the accounts it names. This capability owns the composer, the per-network variants, the campaign link, the approval gate, the timed publishing job and its retry, the spend stop on X, and the advocacy flow for the accounts no application may post to.

## ADDED Requirements

### Requirement: One Post Carries Per-Network Variants

A post SHALL have one body that applies everywhere and an optional variant per network. Resolving a variant SHALL be a merge onto the post's own values, not a replacement: a variant that carries only a body SHALL still use the post's link and media. Each network SHALL declare the longest body it accepts, and a variant longer than its network's limit SHALL be refused before the post can be approved rather than at publish time.

#### Scenario: A network without a variant uses the post's own body

- **GIVEN** a post with a body, a link and a variant for `x` that carries only a shorter body
- **WHEN** the post is resolved for `x` and for `mastodon`
- **THEN** `x` SHALL get the variant's body with the post's own link and media
- **AND** `mastodon` SHALL get the post's own body, link and media

@e2e exclude the resolution is a pure merge asserted on both sides of the seam. Asserted by tests/Unit/Service/SocialPostServiceTest.php (testAVariantMergesOntoThePostRatherThanReplacingIt) and tests/vitest/socialComposer.spec.js.

#### Scenario: A variant longer than its network allows is refused before approval

- **GIVEN** a post whose `x` variant is longer than X accepts
- **WHEN** the marketer submits it for approval
- **THEN** the submission SHALL be refused naming the network and the limit
- **AND** the post SHALL still be a draft

@e2e exclude the limit table is a pure module shared by the composer and the service. Asserted by tests/Unit/Service/SocialPostServiceTest.php (testAVariantOverTheNetworkLimitIsRefusedAtApproval) and tests/vitest/socialComposer.spec.js.

### Requirement: A Post's Link Carries Its Campaign

A post that belongs to a campaign SHALL publish a link decorated with that campaign's UTM parameters, using the same decorator the mailings already use, so a click from a post and a click from a newsletter land in one attribution vocabulary. The stored link SHALL stay undecorated, so moving a post to another campaign does not mean unpicking a query string. A post with no campaign SHALL publish its link unchanged.

#### Scenario: A post in a campaign publishes a decorated link

- **GIVEN** a post whose campaign is `najaarscampagne` and whose link has no query string
- **WHEN** it is published
- **THEN** the published body SHALL carry the link with the campaign's UTM parameters
- **AND** the stored post's link SHALL still be the undecorated one

@e2e exclude the decoration happens on the way into an adapter request, which no browser run reaches. Asserted by tests/Unit/Service/SocialPostServiceTest.php (testACampaignPostPublishesADecoratedLinkAndStoresACleanOne).

#### Scenario: A post with no campaign publishes the link unchanged

- **GIVEN** a post with a link and no campaign
- **WHEN** it is published
- **THEN** the published link SHALL be byte-identical to the stored one

@e2e exclude same publish path as above. Asserted by tests/Unit/Service/SocialPostServiceTest.php (testAPostWithoutACampaignPublishesTheLinkUnchanged).

### Requirement: Nothing Leaves the Instance Without a Human Approval

A post SHALL reach `scheduled` only after a person has approved it, and the approval SHALL record who decided, when, and what they decided. The approval SHALL be taken from the session and never from a request body. A rejected post SHALL return to `draft` with the rejection recorded and SHALL NOT be published. A post an agent drafted SHALL carry the ADR-088 mark, stamped by the write path, and the mark SHALL be visible wherever the post is read.

#### Scenario: A draft cannot be published

- **GIVEN** a post in status `draft` whose scheduled moment has passed
- **WHEN** the publishing job runs
- **THEN** nothing SHALL be sent and the post SHALL still be a draft

@e2e exclude a background job's decision not to act is asserted on the store. Asserted by tests/Unit/Service/SocialPostServiceTest.php (testAnUnapprovedPostIsNeverPublished).

#### Scenario: An approval records the person who gave it

- **GIVEN** a post waiting for approval
- **WHEN** `marieke` approves it, with a body claiming somebody else approved it
- **THEN** the recorded approval SHALL name `marieke`
- **AND** the post SHALL be `scheduled`

@e2e exclude the CI instance runs one identity, so the claim-somebody-else half cannot be produced in a browser. Asserted by tests/Unit/Service/SocialPostServiceTest.php (testAnApprovalIsStampedFromTheSessionNotTheBody).

#### Scenario: A rejected post goes back to the marketer

- **GIVEN** a post waiting for approval
- **WHEN** a reviewer rejects it with a note
- **THEN** the post SHALL be `draft` with the rejection and its note recorded
- **AND** nothing SHALL have been sent

@e2e exclude same approval seam as above. Asserted by tests/Unit/Service/SocialPostServiceTest.php (testARejectionReturnsThePostToDraft).

### Requirement: Publishing Runs on a Timed Job, One Account at a Time

Publishing SHALL run on a `TimedJob` (ADR-069) that picks up scheduled posts whose moment has arrived. A publication row SHALL exist for every account the post names before anything is sent. One account failing SHALL NOT stop the others. A failed publication SHALL carry a failure code from a closed set and a reason a marketer can act on, and SHALL be retryable. A grant that is gone SHALL ask for a reconnect rather than be retried, because a retry cannot fix it.

#### Scenario: A scheduled post publishes at its moment

- **GIVEN** an approved post scheduled for a moment that has passed, naming two connected accounts
- **WHEN** the publishing job runs
- **THEN** both accounts SHALL have a publication with the network's own id and URL
- **AND** the post SHALL be `published`

@e2e exclude publishing calls out to a network the CI instance is not connected to. Asserted by tests/Unit/Service/SocialPostServiceTest.php (testAScheduledPostPublishesToEveryNamedAccount).

#### Scenario: One failing account does not stop the others

- **GIVEN** an approved post naming three accounts, one of which the network refuses
- **WHEN** the publishing job runs
- **THEN** the two that succeeded SHALL be `published` with their ids
- **AND** the third SHALL be `failed` with its reason
- **AND** the post SHALL be `failed` with a reason naming that one account

@e2e exclude same outbound path as above. Asserted by tests/Unit/Service/SocialPostServiceTest.php (testOneFailingAccountDoesNotStopTheOthers).

#### Scenario: A dead grant asks for a reconnect instead of retrying

- **GIVEN** an approved post naming an account whose grant the network has revoked
- **WHEN** the publishing job runs
- **THEN** the publication SHALL be `failed` with failure code `relink_needed`
- **AND** the account SHALL be `relink_needed` and its owner SHALL be notified
- **AND** a retry SHALL NOT call the network again while the account is in that state

@e2e exclude the relink outcome originates in an exception the broker raises on an outbound call. Asserted by tests/Unit/Service/Social/SocialBrokerGatewayTest.php (testARelinkExceptionIsCaughtBeforeItsParent) and tests/Unit/Service/SocialPostServiceTest.php (testARelinkNeededPublicationDoesNotRetry).

#### Scenario: A failure shows its reason and can be retried

- **WHEN** a marketer opens a post whose publication failed for a reason a retry can fix
- **THEN** the page SHALL show the account, the reason and a Retry action

### Requirement: Publishing to X Stops at the Tenant's Spend Budget

X charges for every post and every read. Publishing to an X account SHALL check the tenant's spend budget for the `x` provider before the call is made, on the existing `messageSendBudget` semantics, and SHALL record the realised cost afterwards. An exhausted hard-stop budget SHALL refuse with failure code `budget_exhausted` and SHALL NOT call the network. Reading X metrics SHALL be gated the same way.

#### Scenario: A post to X is refused once the budget is exhausted

- **GIVEN** a tenant whose `x` spend budget is a hard stop that has been reached
- **WHEN** an approved post naming an X account is published
- **THEN** the publication SHALL be `failed` with failure code `budget_exhausted`
- **AND** no request SHALL have been made to X

@e2e exclude the budget row and the outbound call are both server-side. Asserted by tests/Unit/Service/SocialPostServiceTest.php (testAnExhaustedSpendBudgetStopsThePostBeforeTheCall).

#### Scenario: A published X post records what it cost

- **GIVEN** a tenant whose `x` budget has room
- **WHEN** a post to an X account is published
- **THEN** the publication SHALL carry the realised cost
- **AND** the budget SHALL have been advanced by it

@e2e exclude same server-side seam. Asserted by tests/Unit/Service/SocialPostServiceTest.php (testAPublishedXPostRecordsItsCostAgainstTheBudget).

### Requirement: An Account No Application May Post To Asks Its Owner to Share

An account whose publish mode is `share` SHALL NOT be posted to. Its publication SHALL be created as `awaiting_share`, and its owner SHALL be notified in Nextcloud with the prepared text, the media, a copy action and a deep link into that network's own composer. The share SHALL be recorded only when the owner confirms it, and only the owner or an administrator SHALL be able to confirm. No consent record and no external call SHALL be involved.

#### Scenario: The owner is asked to share, with the text prepared

- **GIVEN** an approved post naming a personal Instagram account
- **WHEN** the publishing job runs
- **THEN** the publication SHALL be `awaiting_share` with the moment the owner was asked
- **AND** the owner SHALL have a Nextcloud notification carrying the prepared text
- **AND** nothing SHALL have been sent to Instagram

@e2e exclude the notification is delivered to a second user the CI instance does not have. Asserted by tests/Unit/Service/SocialAdvocacyServiceTest.php (testAShareModeAccountNotifiesItsOwnerAndCallsNothing).

#### Scenario: Confirming the share records it

- **GIVEN** a publication in `awaiting_share`
- **WHEN** its owner confirms they posted it, naming the address it landed on
- **THEN** the publication SHALL be `shared` with that address and the moment they confirmed

@e2e exclude same second-identity limit. Asserted by tests/Unit/Service/SocialAdvocacyServiceTest.php (testConfirmingAShareRecordsItAgainstTheOwner).

#### Scenario: Only the owner may confirm a share

- **GIVEN** a publication in `awaiting_share` owned by `ruben`
- **WHEN** `marieke`, who is not an administrator, confirms it
- **THEN** the confirmation SHALL be refused and the publication SHALL still be `awaiting_share`

@e2e exclude same second-identity limit. Asserted by tests/Unit/Service/SocialAdvocacyServiceTest.php (testOnlyTheOwnerMayConfirmAShare).

### Requirement: Each Network's Request Is Shaped as That Network Documents It

Every network SHALL have an adapter that shapes the request its own API documents, and the adapter SHALL pass a path and a body, never a host and never an authorization header. Mastodon SHALL post a status, Bluesky SHALL create a repository record, LinkedIn SHALL post as the connected member or organisation, X SHALL post a tweet, a Facebook page SHALL post to its feed, Instagram business SHALL create a media container and then publish it, and Threads SHALL do the same in two steps.

#### Scenario: Each adapter builds the request its network documents

- **WHEN** a post is shaped for each of the seven networks
- **THEN** each adapter SHALL produce the method and path that network's API documents
- **AND** none of them SHALL set a host or an authorization header

@e2e exclude an adapter's request shape is asserted before any call is made, which is the point of asserting it. Asserted by tests/Unit/Service/Social/SocialAdapterRequestShapeTest.php, one test per network.

#### Scenario: Instagram publishes in two steps and never in one

- **GIVEN** a post with an image for an Instagram business account
- **WHEN** it is published
- **THEN** a media container SHALL be created first and published second
- **AND** a failure of the first step SHALL NOT attempt the second

@e2e exclude same pre-call assertion. Asserted by tests/Unit/Service/Social/SocialAdapterRequestShapeTest.php (testInstagramCreatesAContainerThenPublishesIt, testInstagramDoesNotPublishWhenTheContainerFails).
