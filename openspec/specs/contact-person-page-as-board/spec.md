# contact-person-page-as-board Specification

## Purpose
The contact person page follows its drawn board: an Overview tab of headed sections for details, deals, relationships and channels, a card that says whether the contact is in a Nextcloud address book, and relationships and channels listed compactly.

## Requirements

### Requirement: The contact page is the board

The contact page SHALL open on an Overview tab of headed sections (details, deals, relationships, channels), SHALL offer
every list and panel of the person as a tab with History last, and SHALL keep its side column to open deals, related
records and the three newest changes.

#### Scenario: PqContactpersoon

- **WHEN** a user opens Joke Smit
- **THEN** the strip reads Overzicht, Deals, Verzoeken, Contactmomenten, Boekingen, Relaties, Abonnementen, BSN en BRP,
  Kanalen, Mail, Berichten, Afspraken, Bestanden, Geschiedenis
- **AND** the side column shows Openstaande deals, Gerelateerd and Geschiedenis

### Requirement: The contact page shows its address book card

The contact page SHALL show a Nextcloud Contacten card with whether the contact's address book card exists, which
address book holds it and when it last changed, and SHALL offer to write the contact to its card again.

#### Scenario: A synced card

- **GIVEN** Joke Smit's card in the address book "Pipelinq CRM", changed today at 09:12
- **THEN** the card reads "Gesynchroniseerd met Contacten" and "Kaart in het adresboek Pipelinq CRM, vandaag 09:12
  bijgewerkt."
- **WHEN** the user presses "Bijwerken in Contacten"
- **THEN** the contact is written to the card and the status is read again

#### Scenario: No card

- **GIVEN** a contact without `contactsUid`, or whose card is gone
- **THEN** the card reads "Niet in Contacten"

### Requirement: Overview lists relationships and channels compactly

Overview SHALL list each relationship as its type and the other party, and each channel as a label and one line, with a
pill to add one; the Relationships and Channels tabs keep the full lists with edit and remove.

#### Scenario: A contact person with relationships and channels
- GIVEN a contact person who works at Acme B.V. and has an e-mail address and a phone number
- WHEN the user opens the Overview
- THEN it lists "Works at Acme B.V." and each channel as a label and one line, with a pill to add one
- AND the Relationships and Channels tabs list them in full with edit and remove
