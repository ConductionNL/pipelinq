# Namespace the channel message

## Why

`message` was claimed by this app and by hermiq. A schema slug is global per
organisation and `SchemaMapper::find()` matches `LOWER(slug)`, so whichever row
the lookup reached first answered for both. hermiq is the messaging app and owns
the bare slug.

This completes the pair started by `channelConversation`: both halves of the
WhatsApp/SMS channel model now say which channel they belong to.

## Renamed apart

The two share `conversationId` alone, and that points at a thread rather than
identifying the message.

## The decoys, which are almost all of it

`message` is one of the most common words in the tree: **259 quoted
occurrences, 10 of them the slug.** The rest are exception messages, log lines,
i18n strings, notification bodies and a `message` property on the lead schema
(the free-text enquiry from an anonymous website intake).

Two sibling slugs also stay: `messageTemplate` and `messageSendBudget` are not
claimed by anyone else and do not move. A prefix match would have taken both.

Every site was changed by line anchor or by an unambiguous pattern
(`"schema": "message"`, `DEFAULT_MESSAGE_SCHEMA_SLUG`), never by a blanket
replace.
