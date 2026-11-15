# Marketing UI: delta for specify-capability-rows

## Purpose

Say how a marketer sees, per recipient, who opened a mailing and what they clicked.

## ADDED Requirements

### Requirement: The send monitor shows each recipient's opens and clicks

The send monitor of a mailing SHALL list its recipients with their latest status, so a marketer sees who opened it and who clicked. Each recipient's delivery record SHALL keep the links that recipient clicked.

#### Scenario: Two recipients, one click
- GIVEN a mailing sent to Anna and Bram
- AND Anna opened it and clicked the link to the pricing page, Bram opened it only
- WHEN the marketer opens the send monitor
- THEN Anna is listed as Clicked and Bram as Opened
- AND Anna's delivery record lists the pricing page link under clicked links
- @e2e exclude tracking events need a live mail provider; covered by PHPUnit `TrackingLinkServiceTest`
