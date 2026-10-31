# client-side-cards-title-add Specification

## Purpose
The client page's side cards, channels and relationships, carry their add button in the card title row, so adding a channel or a relationship starts where the card is named.

## Requirements

### Requirement: The side cards' add sits in the card title row
On the client page the Contact channels and Relationships side cards SHALL put their add in the card's title row as
the outlined card button labelled "Add", named "Add channel" and "Add relationship"; the channels add SHALL open a menu
of e-mail address, phone number and social profile. The Client notes card SHALL keep its composer behind its header
button.

#### Scenario: Relationships on PqKlant
- GIVEN Sanne de Vries's client page
- THEN the Relaties card shows "Toevoegen" beside its title, and choosing it opens the add-relationship dialog

#### Scenario: Contact channels on PqOrganisatie
- GIVEN Buurtvereniging Oost's client page
- THEN the Contactkanalen card shows "Toevoegen" beside its title, a menu of E-mailadres, Telefoonnummer and Sociaal profiel

#### Scenario: Notes
- GIVEN a client page
- THEN the Notities card shows "Notitie toevoegen" beside its title and opens on its notes
