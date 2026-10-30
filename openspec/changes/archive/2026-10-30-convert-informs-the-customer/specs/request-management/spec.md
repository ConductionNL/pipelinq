## ADDED Requirements

### Requirement: Converting can tell the customer the case number

When a request is converted with `informCustomer`, the request MUST get an answer to the customer that names the new case's number, and its handler when known. The answer MUST be appended to `portalAnswers` like any other answer.

#### Scenario: Sanne hears her request is now a case
- GIVEN Sanne's request is converted to case 2026-4890 with "Klant informeren in Mijn Zuiddrecht" ticked
- THEN her request reads "Uw verzoek is nu zaak 2026-4890. U kunt de zaak volgen op uw persoonlijke pagina." in Mijn Zuiddrecht

### Requirement: The conversion options start ticked

The convert-to-case dialog MUST open with "Keep this ticket open until the case is made" and "Inform the customer" both ticked, as board PqTicketOmzetten draws them. The handler MAY untick either before confirming.

#### Scenario: The handler converts without changing anything
- GIVEN the convert-to-case dialog is open on Sanne's request
- WHEN the handler picks a case type and confirms
- THEN the request is converted with keepOpen true and informCustomer true
