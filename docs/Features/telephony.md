# Telephony

## Overview

Pipelinq takes calls through a CTI platform (see [CTI adapter setup](../Integrations/cti-adapter-setup.md)). When a call comes in, the screen pop matches the caller's number to a contact or a client. This page covers what happens when a number matches nobody, and how a phone number is found however it was typed.

## Finding earlier contacts by phone number

Every ticket and contact moment stores the phone number it is about in E.164 (`+31612345678`), whatever the writer was given:

| Written by | Number stored |
|------------|---------------|
| A CTI call | The caller on an inbound call, the callee on an outbound one |
| A web enquiry | The number the resident typed |
| A message from an unknown number | The sender |

The number is normalised with the organisation's default country, the same way the screen pop normalises a caller. Tickets stored before this existed get the number once, by the repair step *Store the phone number of every ticket in E.164 for the phone search*, which runs on upgrade and changes nothing the second time.

### An unknown caller

When an incoming call matches no contact or client, the intake dialog (*New contact from incoming call*) lists **Earlier contacts from this number**: the earlier contact moments and tickets you may read, newest first, each with its date, type and handler and a link to the ticket. A first-time caller shows *There are no earlier contacts from this number.*

### How a number is matched

A search is normalised first, so `06 1234 5678`, `06-12345678`, `+31 6 1234 5678` and `0031612345678` all find a ticket stored as `+31612345678`.

- A ticket whose stored number equals the search is an exact match.
- A ticket stored before the normalised number existed is still found by the last nine digits of its number. Because a number from another country can share those digits, it is labelled **possible match**.

At most 50 tickets are answered, and only tickets you may read.

## API

`GET /apps/pipelinq/api/tickets/by-phone?number=<number as typed>`

Answers `{ number, results, total }`: the search in E.164 (or `null` when it is not a phone number), the tickets with `_phoneMatch` set to `exact` or `possible`, and how many there are. A blank number answers 400; no session answers 401.

## Not covered

- Numbers written inside free text, such as a description or a note.
- Merging an unknown caller's earlier contacts into a contact once they are identified: linking a ticket to a contact stays a manual act.
