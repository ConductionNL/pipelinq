## ADDED Requirements

### Requirement: Converting can tell the customer the case number

When a request is converted with `informCustomer`, the request MUST get an answer to the customer that names the new case's number, and its handler when known. The answer MUST be appended to `portalAnswers` like any other answer.

#### Scenario: Sanne hears her request is now a case
- GIVEN Sanne's request is converted to case 2026-4890 with "Klant informeren in Mijn Zuiddrecht" ticked
- THEN her request reads "Uw verzoek is nu zaak 2026-4890. U kunt de zaak volgen op uw persoonlijke pagina." in Mijn Zuiddrecht
