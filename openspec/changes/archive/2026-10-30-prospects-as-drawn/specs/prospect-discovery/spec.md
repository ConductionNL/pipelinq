# prospect-discovery: delta for prospects-as-drawn

## ADDED Requirements

### Requirement: The prospects list offers lenses, a Filter menu and a search

The prospects page MUST offer the lenses All, Good fit (a score above 70) and New this week (registered in the KVK register in the last seven days) with their counts, a Filter menu on the place, and a search on company, SBI code and place, all narrowing the list.

#### Scenario: good fit

- GIVEN three companies scoring 40, 75 and 90
- WHEN the user chooses Good fit
- THEN the list shows the two companies scoring 75 and 90

#### Scenario: search a place

- GIVEN companies in Utrecht and Veenendaal
- WHEN the user searches "veenendaal"
- THEN the list shows the company in Veenendaal

### Requirement: The profile card lists its facts with Edit profile

The ideal customer profile card MUST list each set criterion, keywords included, as a label over its value, and offer Edit profile.

#### Scenario: keywords

- GIVEN a profile with the keywords horeca and retail
- WHEN the prospects page opens
- THEN the card lists "Keywords: horeca, retail" after the other criteria
