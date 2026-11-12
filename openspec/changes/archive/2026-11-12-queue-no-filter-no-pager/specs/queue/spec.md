# queue Delta: queue-no-filter-no-pager

## ADDED Requirements

### Requirement: The queue has no Filter and no pager on one page

In the simple profile the queue SHALL draw no Filter button, and while its tickets fit on one page the list card SHALL
end in its note alone, without a count and without page links.

#### Scenario: Board PqWachtrij

- **WHEN** the agent opens Wachtrij with nine waiting tickets
- **THEN** the lenses have no Filter button beside them
- **AND** the card ends in "Oudste eerst. Een ticket verlaat de wachtrij zodra iemand het in behandeling neemt."
