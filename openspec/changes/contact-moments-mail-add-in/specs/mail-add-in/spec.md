# mail-add-in Specification (delta)

## Purpose

A colleague reading a mail in Outlook sees the sender's pipelinq record, adds an
unknown sender as a contact, and logs the mail as a contact moment, without
leaving the mail. From pipelinq matrix row `mail-client-plugin`.

## ADDED Requirements

### Requirement: pipelinq serves an Outlook add-in (REQ-MAI-001)

pipelinq SHALL serve an Office add-in manifest and a task pane page for Outlook's
mail read view. The pane SHALL sign in with the colleague's Nextcloud account
through Nextcloud's login flow and SHALL act only with that account's rights.

#### Scenario: A colleague signs in once

- GIVEN the add-in is deployed for the organisation and a colleague opens a mail in Outlook
- WHEN they open the pipelinq pane and sign in with their Nextcloud account
- THEN the pane shows the sender's lookup result
- AND Nextcloud's security settings list an app password for the add-in

### Requirement: The pane shows the sender's record or adds it (REQ-MAI-002)

For the open mail the pane SHALL show the contacts and clients whose address
matches the sender and that the colleague may read, each with Open in pipelinq.
Without a match it SHALL offer Add as contact, prefilled from the sender, and
SHALL suggest an organisation client by mail domain when one matches.

#### Scenario: A known sender

- GIVEN a mail from anna@bakkerij-korenschoof.nl, who is a pipelinq contact
- WHEN the colleague opens the pane
- THEN it shows Anna and her client Bakkerij de Korenschoof with Open in pipelinq

#### Scenario: An unknown sender from a known organisation

- GIVEN a mail from piet@bakkerij-korenschoof.nl, who is not in pipelinq
- WHEN the colleague presses Add as contact and saves
- THEN Piet exists as a contact of Bakkerij de Korenschoof in pipelinq

### Requirement: A mail can be logged as a contact moment (REQ-MAI-003)

The pane SHALL offer Log this mail, which SHALL create a contact moment with
channel email on the chosen record, holding the subject, the date and at most
the first 1,000 characters of the mail.

#### Scenario: Logging a mail on a contact

- GIVEN the pane shows contact Anna
- WHEN the colleague presses Log this mail
- THEN ContactDetail of Anna lists a contact moment with channel email and the mail's subject
