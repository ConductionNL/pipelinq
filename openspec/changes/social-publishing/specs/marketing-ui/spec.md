## ADDED Requirements

### Requirement: The Marketing Menu Reaches Social Publishing

The Marketing group SHALL carry Social accounts, Social posts and Social performance, after the mailing entries and before Search queries, so the section reads in the order the work happens: write, send, post, measure. Every one of the three SHALL be a real page reached by its own path, never a hash route.

#### Scenario: The Marketing group reaches the three social pages

- **WHEN** a marketer opens the Marketing group in the navigation
- **THEN** Social accounts, Social posts and Social performance SHALL be listed
- **AND** opening each SHALL land on its own page without a hard error

#### Scenario: The social posts page lists the seeded posts

- **WHEN** a marketer opens the Social posts page
- **THEN** the seeded posts SHALL be listed with their status
- **AND** a post an agent drafted SHALL be marked as such

### Requirement: A Marketer Composes One Post for Several Networks

The composer SHALL take one body, media, a link, the accounts the post goes to and a moment to send it, and SHALL let a marketer write a variant per network without retyping the rest. It SHALL show, per network, how much of the body fits, and SHALL refuse to submit a variant that does not fit. Submitting SHALL put the post up for approval rather than schedule it, and the approval SHALL be a visible step rather than a checkbox.

#### Scenario: A marketer writes a variant for one network only

- **GIVEN** a post with a body and two accounts on different networks
- **WHEN** the marketer writes a variant for one of the two and saves
- **THEN** the stored post SHALL carry that one variant
- **AND** the other network SHALL still use the post's own body

#### Scenario: The composer says when a variant does not fit

- **WHEN** a marketer types a variant longer than its network accepts
- **THEN** the composer SHALL say so and SHALL NOT let the post be submitted for approval

#### Scenario: The calendar shows what goes out when

- **WHEN** a marketer opens the Social posts page
- **THEN** scheduled posts SHALL be listed by the moment they go out
- **AND** a failed post SHALL show its reason and offer a retry
