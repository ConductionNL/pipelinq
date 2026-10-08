# Delta for product-catalog-quoting: a quote from the lead's lines, sent as a PDF

## ADDED Requirements

### Requirement: A line is filled from the catalogue (REQ-QSLICE-001)

Adding a line to a lead MUST let the user pick a product and MUST fill the unit price, unit and VAT rate from the price resolver for the entered quantity. The user MUST be able to overwrite the unit price.

#### Scenario: Tier price

- **GIVEN** the product "Advies" has a tier of 10 euro per hour from 20 hours and 12 euro below that
- **WHEN** Sanne adds 25 hours of "Advies" to the lead
- **THEN** the line shows unit price 10 euro and the tier label

#### Scenario: Negotiated price

- **GIVEN** the same line
- **WHEN** Sanne changes the unit price to 9 euro
- **THEN** the line keeps 9 euro and shows no tier label

### Requirement: A quote is sent as a PDF and logged (REQ-QSLICE-002)

"Send quote" on a lead MUST render the lead's lines to a PDF through filinq, store the PDF in the sender's Files, mail it to the lead's contact or, without a contact email, the client, and log an outbound contact moment carrying the quote number, the total and a link to the file. When filinq is not installed the action MUST NOT be shown.

#### Scenario: Send to the contact

- **GIVEN** the lead "Nieuw kantoor" has two lines and a contact with an email address
- **WHEN** Sanne chooses "Send quote", picks the quote template and a valid-until date
- **THEN** the contact receives a mail with the PDF attached, and the lead's timeline shows an outbound contact moment "Quote Q-2026-0001"

#### Scenario: No recipient

- **GIVEN** the lead has no contact and the client has no email
- **WHEN** Sanne chooses "Send quote"
- **THEN** a message asks her to add an email address and nothing is sent or logged

#### Scenario: filinq absent

- **GIVEN** filinq is not installed
- **WHEN** Sanne opens the lead
- **THEN** no "Send quote" action is shown

### Requirement: The quote shows VAT per rate (REQ-QSLICE-003)

The quote MUST show, for each VAT rate present on the lines, the net amount, the VAT and the gross amount, and the totals excluding and including VAT.

#### Scenario: Two rates

- **GIVEN** a lead has one line of 1000 euro at 21 percent and one of 500 euro at 9 percent
- **WHEN** the quote is rendered
- **THEN** it shows VAT 210.00 and 45.00, total excluding VAT 1500.00 and total including VAT 1755.00

### Requirement: A sent quote does not change afterwards (REQ-QSLICE-004)

Editing a line after sending MUST NOT alter the stored PDF, and sending again MUST create a new quote number.

#### Scenario: Second send

- **GIVEN** Sanne sent Q-2026-0001 and then changed a quantity
- **WHEN** she sends the quote again
- **THEN** a new PDF is created as Q-2026-0002 and Q-2026-0001 is unchanged

