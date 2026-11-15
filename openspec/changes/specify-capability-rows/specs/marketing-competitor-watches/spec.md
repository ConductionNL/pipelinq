# Marketing competitor watches: delta for specify-capability-rows

## Purpose

Say how a user is told that a watched competitor page changed.

## ADDED Requirements

### Requirement: The owner of a watch is told when the watched page changes

When a scheduled page watch finds that its selected fragment changed, the system SHALL send the watch owner a Nextcloud notification. The notification SHALL name the competitor and open the Competitors page, which lists the change with its summary.

#### Scenario: A competitor raises its prices
- GIVEN Sanne owns a page watch on the pricing page of competitor Acme
- WHEN the scheduled run finds that the price block changed
- THEN Sanne gets a notification "Acme changed its pricing page"
- AND opening it shows the change on the Competitors page
- @e2e exclude runs from a background job; to be covered by PHPUnit with the build task
