# Contact relationship mapping (delta)

## ADDED Requirements

### Requirement: The server owns the relationship types

The server SHALL serve the relationship types (stored value, translated label, category) and SHALL be the only place that knows each type's inverse and category.

#### Scenario: the types are offered
- WHEN the agent opens the relationship form
- THEN the type list comes from `GET /api/relationship-types`, grouped by category, in the reader's language

### Requirement: The server derives what a type and target imply

Before a relationship is stored, the server SHALL set `inverseType` and `category` from its type and `toType` from whether the target is a client or a contact.

#### Scenario: an import without inverse
- GIVEN a relationship of type `contactpersoon` to a client is written without `inverseType`
- WHEN it is stored
- THEN it carries `inverseType` `organisatie`, `category` `Professioneel` and `toType` `client`

### Requirement: The server refuses an unknown or repeated relationship

`POST /api/relationships` SHALL refuse a relationship without a party or type, of an unknown type, or to the same party (400), and one that already exists (409), with a message the form shows.

#### Scenario: a repeated relationship
- GIVEN Jan is already Sophie's partner
- WHEN the agent adds the same relationship again
- THEN the form says "This relationship already exists." and nothing is stored

### Requirement: A relationship moved to another party leaves no inverse behind

When a relationship's parties change, the server SHALL remove the inverse row at the old party and write it at the new one.

#### Scenario: a moved relationship
- GIVEN Jan is the parent of Sophie
- WHEN the relationship is changed to point at Piet
- THEN Sophie has no relationship back to Jan and Piet has one

### Requirement: Detail pages list relationships as an object list

The client and contact detail pages SHALL list the record's relationships grouped by category (an organisation's Organisatie first, a person's Familie first) with the other party, the type, the end date (red once past) and Active or Ended, open the other party on a click, and add, edit and remove relationships in place.
