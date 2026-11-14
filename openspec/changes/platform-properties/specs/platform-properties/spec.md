# platform-properties Specification (delta)

## Purpose

What pipelinq is as a product, independent of any page: where it runs, under which licence, how users and login work, and where its look comes from. Covers rows plat-self-host, plat-source-code, plat-no-seat-fee, plat-in-workspace, plat-existing-login and plat-house-style.

## ADDED Requirements

### Requirement: The app runs on the customer's own Nextcloud (REQ-PLAT-001)

pipelinq SHALL install and run as a Nextcloud app on a server the customer operates, with OpenRegister as its only required app dependency. It SHALL NOT require a service operated by Conduction or another vendor to start, store data or serve its pages. Integrations with external services (BRP, KvK, payment providers, mail providers) SHALL be optional and configured by the customer.

#### Scenario: An installation without internet access to vendor services
- GIVEN a Nextcloud server with OpenRegister installed and no route to any Conduction service
- WHEN an administrator installs and enables pipelinq
- THEN the app enables, its pages load and clients, leads and tickets can be created and read

#### Scenario: An external integration is not configured
- GIVEN no KvK or BRP connection is configured
- WHEN a user creates a client by hand
- THEN the client is saved, and the lookup buttons say the connection is not set up instead of failing the save

### Requirement: The source code is open under the EUPL-1.2 (REQ-PLAT-002)

The repository SHALL carry the EUPL-1.2 licence text in `LICENSE`, `appinfo/info.xml` SHALL declare `<licence>EUPL-1.2</licence>`, and every PHP file under `lib/` SHALL carry an SPDX licence identifier. No part of the app needed to run it SHALL be shipped only in compiled or obfuscated form.

#### Scenario: The licence is declared consistently
- GIVEN the repository at any release tag
- WHEN `LICENSE` and `appinfo/info.xml` are read
- THEN both name the EUPL-1.2

#### Scenario: A PHP file without a licence header
- GIVEN a new file under `lib/` without an SPDX identifier
- WHEN the quality gates run
- THEN the SPDX gate fails on that file

### Requirement: Users are not counted or licensed per seat (REQ-PLAT-003)

pipelinq SHALL take its users from Nextcloud and SHALL NOT count them, limit their number, or check a licence key, subscription or seat allowance before a user may work. Access SHALL be governed only by Nextcloud groups and the app's own role settings.

#### Scenario: A colleague is added
- GIVEN an instance with 25 users working in pipelinq
- WHEN an administrator adds a 26th Nextcloud user to the group that may use pipelinq
- THEN that user can open pipelinq and work in it, and no screen or log mentions a seat limit

### Requirement: The app lives in the Nextcloud workspace (REQ-PLAT-004)

pipelinq SHALL register itself as a Nextcloud app with an entry in the Nextcloud navigation, and SHALL use the workspace's own services for files, calendar, contacts, activity, notifications and comments through the Nextcloud OCP interfaces (`IRootFolder`, `OCP\Calendar\IManager`, `OCP\Contacts\IManager`, `OCP\Activity\IManager`, `OCP\Notification\IManager`, `ICommentsManager`) instead of keeping its own copies.

#### Scenario: A document on a client
- GIVEN a client in pipelinq
- WHEN a user adds a document to the client
- THEN the file is stored in Nextcloud Files and can be opened from the Files app

#### Scenario: An appointment with a client
- GIVEN a user schedules a follow-up with a client
- WHEN the appointment is saved
- THEN it appears in the user's Nextcloud Calendar

### Requirement: Login is the Nextcloud login (REQ-PLAT-005)

pipelinq SHALL have no login, password store or session of its own. Every route SHALL declare its access posture with a Nextcloud attribute, routes for signed-in users SHALL rely on the Nextcloud session (including any SSO, SAML, OIDC or two-factor setup the instance has), and routes reachable without a session SHALL be explicitly marked `#[PublicPage]` and check their own token or signature.

#### Scenario: Single sign-on
- GIVEN an instance where users sign in through the organisation's identity provider
- WHEN a signed-in user opens pipelinq
- THEN pipelinq opens without asking for credentials

#### Scenario: A public route
- GIVEN a route for a public intake form or a provider webhook
- WHEN it is called without a Nextcloud session
- THEN it is marked `#[PublicPage]` and refuses the call unless its token or signature checks out

### Requirement: The look comes from the theme, not the code (REQ-PLAT-006)

pipelinq's styles SHALL use the Nextcloud theme variables (and through them the NL Design System tokens that thematiq supplies) for colours, fonts and radii, and SHALL NOT hard-code colour values in components. An administrator SHALL be able to change the app's look through Nextcloud theming or thematiq without a code change.

#### Scenario: A municipality applies its house style
- GIVEN thematiq is configured with a municipality's design tokens
- WHEN a user opens the pipelinq dashboard
- THEN buttons, links and headings use the municipality's colours

#### Scenario: A hard-coded colour is added
- GIVEN a component in `src/` with a literal hex colour in its styles
- WHEN the style lint runs
- THEN it reports the colour and names the theme variable to use instead
