# marketing-lists Delta

## ADDED Requirements

### Requirement: The Subscribers table fits its card

The mailing list's Subscribers table SHALL fit the width of its card, with its heads drawn as labels and a small filter
button on the card's ground, and SHALL show the "Subscribers" heading once.

#### Scenario: Board PqMailinglijst

- **WHEN** a user opens a mailing list with six subscribers at a 1440px viewport
- **THEN** every column, Bevestigd included, sits inside the Subscribers card
- **AND** the head row is about 42px tall, with no boxed buttons
- **AND** "Subscribers" appears once, as the card's title
- **AND** the list details run in two columns
