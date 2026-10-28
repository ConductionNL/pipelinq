## ADDED Requirements

### Requirement: The lead page reads as the board

The lead page MUST name the owner by display name in its header line. It MUST offer Log contact first. In the qualified and proposal stages, the "what now" card's button MUST make a quote for the lead. The Deal card MUST show the eight deal facts in two columns, and the win chance MUST read as a percentage.

#### Scenario: Woonportaal voor huurders
- GIVEN the qualified lead "Woonportaal voor huurders" owned by Pieter Jansen
- THEN the header reads "... eigenaar Pieter Jansen", the what-now card offers "Offerte maken", and Winkans reads "62%"

### Requirement: The Related card names earlier deals and hours

`GET /api/leads/{id}/related-summary` MUST answer `earlier`, which counts the client's other closed deals and says how they ended ("2 earlier deals, both won"), and `hours`, the hours written on the lead. Each MUST be empty when there is nothing to say. Only a caller who may read the lead MUST get an answer. The lead page's Related card MUST start with "Prospect data from the KVK", the earlier deals and the hours, above the related objects.
