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
- THEN the header shows Johannes Jansen with preferred name Jan
- AND the Nextcloud contact of Johannes Jansen shows the nickname Jan

#### Scenario: A nickname set in Nextcloud Contacts arrives in pipelinq

- GIVEN a contact whose Nextcloud contact gets the nickname Hanneke in the Contacts app
- WHEN the contact syncs
- THEN ContactDetail shows preferred name Hanneke

### Requirement: The preferred name is used where a person is addressed (REQ-CPN-002)

The telephony screen pop SHALL show a matched caller's preferred name first.
Templates SHALL offer `{{preferredName}}`, which SHALL fall back to the full
name when no preferred name is set.

#### Scenario: An agent greets a caller by the right name

- GIVEN a caller matched to contact Johannes Jansen with preferred name Jan
- WHEN the screen pop opens for the agent
- THEN it shows Jan first and Johannes Jansen below it

#### Scenario: A template falls back to the full name

- GIVEN a mail template starting with Beste {{preferredName}}
- WHEN it is sent to a contact without a preferred name
- THEN the mail starts with Beste and the contact's full name
