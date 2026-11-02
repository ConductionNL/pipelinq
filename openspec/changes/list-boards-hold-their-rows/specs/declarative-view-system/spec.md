# declarative-view-system

## ADDED Requirements

### Requirement: A list shows its board's page size

An index page whose board draws a page of rows SHALL set that page size with
`pagination.limit`: 5 for Campaigns and Contacts, 8 for Bookings and Clients.
The Templates list SHALL sort by name. Every cash shift row SHALL carry a row
actions button whose View action opens the shift. The organisations list
SHALL show the columns the dossiq organisations list shows: Name, KvK
number, Legal form, Place, Open cases, Open tickets and Last contact.

#### Scenario: Campaigns show five a page

- **GIVEN** the Zuiddrecht set with six campaigns
- **WHEN** a user opens Campaigns
- **THEN** the table shows 5 rows and the pager offers a second page

#### Scenario: A cash shift row has its actions button

- **GIVEN** the cash drawer list
- **WHEN** a user opens it
- **THEN** every row ends with an actions button whose View opens the shift
