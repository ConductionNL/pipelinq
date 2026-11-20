# Messaging saved replies: delta for specify-capability-rows

## Purpose

Say how a reply to a party is offered in the language that party asked to be written in.

## ADDED Requirements

### Requirement: Saved replies in the party's language come first

When an agent answers a party, the saved reply picker SHALL list the replies in that party's correspondence language before the others. The language comes from the contact first and the client second. A party without a language gets the list in its usual order.

#### Scenario: A client who asked for English
- GIVEN client Acme with correspondence language `en`
- AND saved replies "Opening hours" in Dutch and "Opening hours (EN)" in English, both for email
- WHEN an agent opens Reply to the customer on an Acme ticket
- THEN "Opening hours (EN)" is the first reply in the picker
- AND picking it puts the English text in the composer
- @e2e exclude unit test to add in task 30.1
