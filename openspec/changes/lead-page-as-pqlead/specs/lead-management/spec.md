## ADDED Requirements

### Requirement: The lead page reads as the board

The lead page MUST name the owner by display name in its header line. It MUST offer Log contact first. In the qualified and proposal stages, the "what now" card's button MUST make a quote for the lead. The Deal card MUST show the eight deal facts in two columns, and the win chance MUST read as a percentage.

#### Scenario: Woonportaal voor huurders
- GIVEN the qualified lead "Woonportaal voor huurders" owned by Pieter Jansen
- THEN the header reads "... eigenaar Pieter Jansen", the what-now card offers "Offerte maken", and Winkans reads "62%"

### Requirement: The Related card names earlier deals and hours

`GET /api/leads/{id}/related-summary` MUST answer `earlier`, which counts the client's other closed deals and says how they ended ("2 earlier deals, both won"), and `hours`, the hours written on the lead. Each MUST be empty when there is nothing to say. Only a caller who may read the lead MUST get an answer. The lead page's Related card MUST start with "Prospect data from the KVK", the earlier deals and the hours, above the related objects.

#### Scenario: A client with earlier deals
- GIVEN a lead whose client has two other closed deals, both won, and 6 hours written on the lead
- WHEN a user who may read the lead opens its page
- THEN the Related card starts with "Prospect data from the KVK", then "2 earlier deals, both won" and the 6 hours, above the related objects

#### Scenario: Nothing to say, or no access
- GIVEN a lead whose client has no other closed deals and no hours written
- WHEN `GET /api/leads/{id}/related-summary` is called by a user who may read the lead
- THEN `earlier` and `hours` are empty
- AND a caller who may not read the lead gets no answer
