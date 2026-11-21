## ADDED Requirements

### Requirement: Events of the same moment keep their order in the send monitor

The send monitor's Recent events MUST list the newest event first, and events with the same timestamp MUST keep the order in which the deliveries are returned.

#### Scenario: Eight deliveries in the same minute
- GIVEN eight deliveries of "Subsidieronde voor buurtinitiatieven" all sent at 10:02, returned a***@ziggo first and r***@sportcentrum last
- WHEN Pieter opens the send monitor
- THEN Recent events lists them in that order
