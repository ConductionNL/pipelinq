# web-chat-and-handover Specification (delta)

## Purpose

A website visitor chats with the team without an account. An assistant may
answer first from public content, and a colleague takes over through a ticket
in the Queue. From pipelinq matrix rows `chat-on-website` and `cm-chatbot`.

## ADDED Requirements

### Requirement: An administrator puts a chat on the website (REQ-WCH-001)

An administrator SHALL be able to add a web chat channel with its allowed
websites, a greeting and whether the assistant answers first, and SHALL be
shown the line to paste into the website. The chat window SHALL only load in a
frame on an allowed website. A new channel SHALL be off.

#### Scenario: A web editor adds the chat to the municipality site

- GIVEN an administrator who added the channel "Website" with allowed origin https://www.gemeente.nl and switched it on
- WHEN the web editor pastes the embed line on a page of www.gemeente.nl
- THEN visitors of that page see a chat button

#### Scenario: Another site cannot frame the chat

- GIVEN the channel "Website" allows only https://www.gemeente.nl
- WHEN a page on https://other.example frames the chat window
- THEN the browser refuses to show the window

### Requirement: A visitor chats without an account (REQ-WCH-002)

A visitor SHALL start a chat and exchange messages without an account and
without a cookie. The system SHALL store only a hash of the visitor's chat
secret and SHALL NOT store the visitor's IP address. A request with a wrong
secret SHALL be refused.

#### Scenario: A visitor asks a question

- GIVEN a visitor on an allowed page with the chat open
- WHEN they type "Can I pick up my passport on Saturday?"
- THEN the question appears in their window
- AND no cookie is set by the chat

### Requirement: The assistant answers first when switched on (REQ-WCH-003)

When a channel has the assistant on and hermiq provides its public chat entry
point, the system SHALL pass each visitor message to hermiq before a person is
involved, SHALL show each answer with its sources, and SHALL show hermiq's AI
disclosure above the first answer. Without hermiq or with the assistant off,
the first message SHALL go to a person.

#### Scenario: The assistant answers from a published page

- GIVEN a channel with the assistant on and a published page about passport pickup times
- WHEN a visitor asks when they can pick up a passport
- THEN the window shows an answer with a link to that page
- AND a line saying the answer comes from an AI assistant

#### Scenario: Without hermiq a person answers

- GIVEN a channel with the assistant on and hermiq not installed
- WHEN a visitor sends a first message
- THEN the chat is handed to a person and appears in the Queue

### Requirement: The chat goes to a colleague through the Queue (REQ-WCH-004)

The system SHALL hand the chat to a person when the visitor asks for one or
when the assistant answers twice in a row without a source. Handover SHALL
create one ticket of type request with channel chat, no assignee and the
transcript so far, and SHALL notify the team. A colleague SHALL take the chat
on the ticket and answer in the visitor's window.

#### Scenario: The assistant is stuck and a colleague takes over

- GIVEN a chat where the assistant answered twice without a source
- WHEN the second answer is stored
- THEN the Queue shows a chat ticket with the whole conversation
- AND the team receives a notification

#### Scenario: A colleague answers in the visitor's window

- GIVEN a chat ticket in the Queue
- WHEN a colleague opens it, presses Take this chat and sends "I will check that for you"
- THEN the ticket is assigned to that colleague and leaves the Queue
- AND the visitor's window shows the message from a colleague

### Requirement: Nobody available means a message, not a wait (REQ-WCH-005)

When no agent is available, the chat button SHALL read Leave a message and the
window SHALL ask for a question and an email address. When nobody takes a
handed-over chat within the channel's wait time, the window SHALL say so and
ask for an email address. The ticket SHALL stay in the Queue with the address.

#### Scenario: A visitor writes on Sunday night

- GIVEN no agent profile is available
- WHEN a visitor opens the chat
- THEN the window asks for a question and an email address
- AND after sending, a request ticket with that address waits in the Queue
