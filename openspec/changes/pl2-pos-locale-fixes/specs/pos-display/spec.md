# pos-display Specification (delta)

## ADDED Requirements

### Requirement: A purchase total follows its currency and the user's locale

The purchase history SHALL show each purchase's total as money in the purchase's currency, else the reporting currency,
formatted in the user's locale, and its date in the user's locale.

#### Scenario: A US dollar purchase for an English user

- GIVEN a purchase of 1234.50 USD and a user with locale en_US
- WHEN the cashier opens the purchase history
- THEN the total reads "$1,234.50"
