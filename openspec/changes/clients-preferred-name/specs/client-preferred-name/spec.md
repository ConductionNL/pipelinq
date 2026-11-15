# client-preferred-name Specification (delta)

## Purpose

A person's preferred name is a shipped field that syncs with their Nextcloud
contact and is used wherever pipelinq addresses them. From pipelinq matrix row
`clients-preferred-name`.

## ADDED Requirements

### Requirement: A person carries a preferred name (REQ-CPN-001)

A contact and a person client SHALL have a preferred name field. It SHALL sync
both ways with the Nextcloud contact as the vCard NICKNAME property.

#### Scenario: A KCC agent records a roepnaam

- GIVEN a KCC agent on ContactDetail of Johannes Jansen
- WHEN they set Preferred name to Jan and save
- THEN the header shows Johannes Jansen with Jan under it
- AND the Nextcloud contact of Johannes Jansen shows the nickname Jan

#### Scenario: A person without a preferred name shows only their full name

- GIVEN contact Hanna de Wit without a preferred name
- WHEN a KCC agent opens ContactDetail
- THEN the header shows Hanna de Wit and no preferred name
- @e2e exclude covered by the second test in `tests/e2e/preferred-name.spec.ts`, which is tagged with the scenario above

#### Scenario: A nickname set in Nextcloud Contacts arrives in pipelinq

- GIVEN a contact whose Nextcloud contact gets the nickname Hanneke in the Contacts app
- WHEN the contact syncs
- THEN ContactDetail shows preferred name Hanneke
- @e2e exclude needs a card changed in the Contacts app; covered by PHPUnit on `ContactDataBuilder` and `ContactVcardPropertyBuilder`, both directions

### Requirement: The preferred name is used where a person is addressed (REQ-CPN-002)

The telephony screen pop SHALL show a matched caller's preferred name first.
A campaign mail template SHALL offer `{{preferredName}}`, filled at send time
from the recipient's own contact or client record. It SHALL fall back to the
full name when no preferred name is set, and SHALL be escaped in the HTML body.
A recipient that is no contact or client (a signup known only by its address)
has no name, and the variable SHALL render empty for them. The message
templates of `TemplateRenderer` SHALL offer the same variable with the same
fallback, from the `fullName` or `name` its caller supplies.

#### Scenario: An agent greets a caller by the right name

- GIVEN a caller matched to contact Johannes Jansen with preferred name Jan
- WHEN the screen pop opens for the agent
- THEN it shows Jan first and Johannes Jansen below it
- @e2e exclude the list of matched callers has no page that opens it yet; covered by Vitest on `ScreenPopModal` (`tests/vitest/preferredName.spec.js`)

#### Scenario: A template falls back to the full name

- GIVEN a mail template starting with Beste {{preferredName}}
- WHEN it is sent to a contact without a preferred name
- THEN the mail starts with Beste and the contact's full name
- @e2e exclude needs a mail transport and a sent blast; covered by PHPUnit from the caller, `MailTransportServiceTest::testSendOneDeliveryGreetsTheRecipientByTheirPreferredName`

#### Scenario: A mailing greets Jan by his preferred name

- GIVEN a campaign template starting with Beste {{preferredName}}
- WHEN it is sent to contact Johannes Jansen with preferred name Jan
- THEN the subject and both bodies of the mail read Jan
- @e2e exclude needs a mail transport and a sent blast; covered by the same PHPUnit test

#### Scenario: A subscriber known only by an address gets no name

- GIVEN a mailing list member who signed up with only an email address
- WHEN a template with {{preferredName}} is sent to them
- THEN the variable renders empty
- @e2e exclude covered by the same PHPUnit test and by `RecipientNameReaderTest`
