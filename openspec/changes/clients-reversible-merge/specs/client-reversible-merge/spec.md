# client-reversible-merge Specification (delta)

## Purpose

Two client or contact records can be merged through OpenRegister's merge engine
and the merge can be undone within thirty days, with every moved reference
returned. From pipelinq matrix row `clients-unmerge`.

## ADDED Requirements

### Requirement: Clients and contacts merge through OpenRegister's reversible merge (REQ-CRM-001)

The `client` and `contact` schemas SHALL declare `x-openregister-merge` with a
reversal window of thirty days. ClientDetail and ContactDetail SHALL offer
Merge into another record, SHALL show OpenRegister's preview of which values
win, and SHALL merge only on confirmation.

#### Scenario: An account manager merges a duplicate client

- GIVEN two clients, Gemeente Utrecht and Gem. Utrecht, the second with one open lead
- WHEN the account manager opens Gem. Utrecht, chooses Merge into another record, picks Gemeente Utrecht and confirms the preview
- THEN Gemeente Utrecht lists the open lead
- AND opening Gem. Utrecht shows that it was merged into Gemeente Utrecht

### Requirement: Moved references return when a merge is undone (REQ-CRM-002)

On a merge the system SHALL move every pipelinq reference from the merged-away
record to the surviving record and SHALL record which rows it moved. On a
reversal it SHALL return exactly those rows. A row changed by hand after the
merge SHALL NOT be moved back and SHALL be listed in the reversal result.

#### Scenario: An account manager undoes a wrong merge the next week

- GIVEN last week's merge of Bakker BV into Bakker Installatietechniek, which moved two tickets
- WHEN the account manager opens the Merges section of Bakker Installatietechniek and presses Undo
- THEN Bakker BV exists again as an active client
- AND its two tickets show Bakker BV as their client

#### Scenario: A reference edited after the merge stays put

- GIVEN a merge that moved a lead, after which a colleague moved that lead to a third client
- WHEN the merge is undone
- THEN the lead stays with the third client
- AND the reversal result lists it as not moved back

### Requirement: A merge older than the window is final (REQ-CRM-003)

After the reversal window the Merges section SHALL show the date the merge
became final and SHALL NOT offer Undo.

#### Scenario: A merge from two months ago

- GIVEN a merge executed sixty days ago
- WHEN an account manager opens the survivor's Merges section
- THEN the merge shows the date it became final and no Undo action
