## Purpose

A connected social account: a company page the tenant runs, or a colleague's own profile they connected themselves. This capability owns how an account is connected, reconnected and revoked, who is allowed to act on one, and what a network with no developer application filed reports instead of failing quietly.

The rule the whole capability exists to hold is rule 2 of the marketing architecture: no token, no refresh token and no client secret is ever a property of a Pipelinq object. An account carries a `credentialRef`, which is the UUID of a credential OpenRegister's broker holds in keepiq, and a `clientId`, which is public by design.

## ADDED Requirements

### Requirement: A Connected Account Stores a Reference, Never a Token

Connecting an account SHALL start OpenRegister's OAuth2 connect flow and SHALL store only the credential id it returns. Pipelinq SHALL NOT persist an access token, a refresh token, a client secret or an authorization code on any object, and SHALL NOT read one. Reconnecting SHALL re-authorise the existing credential in place, so every account and every scheduled post that names it keeps working. Revoking SHALL clear the reference and SHALL leave the publications that already went out intact.

#### Scenario: Connecting an account starts the broker flow and stores only the reference

- **GIVEN** a social account for a network whose developer application is filed
- **WHEN** a marketer starts the connection
- **THEN** Pipelinq SHALL call the broker's connect start with the network's provider, the account's scopes and `pipelinq` as the allowed app
- **AND** it SHALL return the authorization URL the broker answered with
- **AND** the account SHALL be stored with status `pending` and no credential reference yet

@e2e exclude the connect start is an outbound call to OpenRegister's broker, which the CI instance does not have configured with a developer application, so a browser run cannot reach a real authorization URL. Asserted by tests/Unit/Service/SocialAccountServiceTest.php (testConnectAsksTheBrokerAndStoresOnlyTheReference).

#### Scenario: A token in a connect response is never written to the account

- **WHEN** the connect completion carries a token, a refresh token or a client secret alongside the credential id
- **THEN** only the credential id SHALL be stored on the account
- **AND** none of the other values SHALL appear on any Pipelinq object

@e2e exclude a browser cannot observe what was NOT written; the assertion is on the stored payload. Asserted by tests/Unit/Service/SocialAccountServiceTest.php (testASecretInTheConnectResponseIsNeverStored).

#### Scenario: Reconnecting a dead grant re-authorises the same credential

- **GIVEN** an account whose status is `relink_needed`
- **WHEN** its owner reconnects it
- **THEN** the broker SHALL be asked to re-authorise the existing credential id rather than mint a new one
- **AND** the account's credential reference SHALL be unchanged afterwards

@e2e exclude same outbound connect flow as above. Asserted by tests/Unit/Service/SocialAccountServiceTest.php (testReconnectReauthorisesTheSameCredentialInPlace).

#### Scenario: Revoking an account keeps what it already published

- **GIVEN** an active account with two publications
- **WHEN** its owner revokes the connection
- **THEN** the account SHALL be `disabled` with an empty credential reference and SHALL no longer be selectable in the composer
- **AND** both publications SHALL still exist and still name the account

@e2e exclude revoking calls the broker's disconnect endpoint, which the CI instance cannot answer. Asserted by tests/Unit/Service/SocialAccountServiceTest.php (testRevokeClearsTheReferenceAndKeepsThePublications).

### Requirement: A Personal Account Belongs to the Person Who Connected It

A `person` account SHALL record the Nextcloud user who connected it in `ownerUserId`, stamped from the session and never taken from a request body. Only that user or an administrator SHALL publish as them, ask them to share, reconnect or revoke. An `organisation` account SHALL be reachable by any user who may use the marketing section. The publishing job SHALL assert the account's owner to the broker rather than borrow a session (ADR-099).

#### Scenario: A colleague cannot publish as another colleague

- **GIVEN** a personal account owned by `ruben`
- **WHEN** `marieke`, who is not an administrator, tries to publish to it, reconnect it or revoke it
- **THEN** every one of those SHALL be refused
- **AND** nothing SHALL be sent to the network

@e2e exclude the CI instance runs one logged-in user, so a second identity cannot be produced in a browser run. Asserted by tests/Unit/Service/SocialAccountServiceTest.php (testAnotherUserMayNotActOnAPersonalAccount) and tests/Unit/Controller/SocialAccountControllerTest.php (testAnUnprivilegedCallerIsRefusedOnEveryMutation).

#### Scenario: An administrator may act on any account

- **GIVEN** a personal account owned by `ruben`
- **WHEN** an administrator revokes it
- **THEN** the revoke SHALL be allowed

@e2e exclude same single-identity limit as above. Asserted by tests/Unit/Service/SocialAccountServiceTest.php (testAnAdministratorMayActOnAnyAccount).

#### Scenario: The publishing job acts as the account's owner

- **GIVEN** a scheduled post naming a personal account owned by `ruben`
- **WHEN** the publishing job runs, with no session at all
- **THEN** the broker call SHALL carry `ruben` as the acting user
- **AND** it SHALL NOT carry the identity of whoever created or approved the post

@e2e exclude a background job's outbound call is not observable from a browser. Asserted by tests/Unit/Service/SocialPostServiceTest.php (testThePublishCallActsAsTheAccountOwnerNotTheApprover).

### Requirement: A Network With No Filing Says So Instead of Failing Quietly

Each network SHALL declare its own readiness. A network whose developer application has not been filed, or whose account has no credential reference, SHALL report `not_configured` with a reason naming what is missing. Connecting such a network SHALL be refused with that reason, and publishing to it SHALL record a publication whose failure code is `not_configured`. A network the broker ships as a preview SHALL report `preview`, SHALL still be publishable, and SHALL carry a reason saying what is incomplete upstream.

#### Scenario: An unconfigured network refuses the connect with a named reason

- **WHEN** a marketer tries to connect an account on a network with no developer application filed
- **THEN** the connect SHALL be refused
- **AND** the refusal SHALL name the network and say that a developer application has to be filed first
- **AND** no account SHALL be left in status `pending` as if a connection were under way

@e2e exclude the reason depends on which providers the instance's broker catalogue carries, which the CI instance does not install. Asserted by tests/Unit/Service/Social/SocialAdapterRegistryTest.php (testAnUnfiledNetworkReportsNotConfiguredWithAReason).

#### Scenario: Publishing to an unconfigured network records a typed failure

- **GIVEN** a post naming an account on a network that reports `not_configured`
- **WHEN** the publishing job runs
- **THEN** the publication SHALL be `failed` with failure code `not_configured` and a readable reason
- **AND** no request SHALL have been made to the broker

@e2e exclude same reason as above; the outcome is asserted on the stored publication. Asserted by tests/Unit/Service/SocialPostServiceTest.php (testAnUnconfiguredNetworkFailsTypedWithoutCallingTheBroker).

#### Scenario: The accounts page shows what each account's status means

- **WHEN** a marketer opens the Social accounts page
- **THEN** the seeded accounts SHALL be listed with their network and their status
- **AND** an account that needs a developer application SHALL say so rather than offer a Connect button that cannot work
