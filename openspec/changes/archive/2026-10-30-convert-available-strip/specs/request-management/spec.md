## ADDED Requirements

### Requirement: The convert dialog says the case app is available in a green strip

The Convert to a case dialog MUST open with a status strip on the success tint, with a check outline and the sentence "{app} is available and can take over this request as a case." in bold success ink. The facts that go with the case MUST show each label over its value.

#### Scenario: Pieter converts the street party request
- GIVEN dossiq is installed and ticket PQ-2026-0417 can be converted
- WHEN Pieter opens Convert to a case
- THEN the dialog opens with the green strip "dossiq is beschikbaar en kan dit verzoek als zaak overnemen."
- AND under "Gaat mee naar de zaak" Titel, Aanvrager and Omschrijving each stand over their value
