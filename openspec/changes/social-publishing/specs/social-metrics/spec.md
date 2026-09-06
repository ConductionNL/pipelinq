## Purpose

What a published post did. This capability owns the daily pull per publication, the normalisation to five numbers every network can answer, the follower count per account, and the page that ranks posts by engagement rate per network.

Meta and LinkedIn withdrew reach and impressions in June 2026. A report built on those numbers would simply have gone blank, so the stored shape is normalised and the provider's own payload is kept alongside it.

## ADDED Requirements

### Requirement: Every Publication's Numbers Are Pulled Daily and Normalised

A daily job SHALL read the numbers for every published publication and store them as views, likes, comments, shares and clicks, whatever the network called them. The provider's own payload SHALL be kept untouched alongside, so a later normalisation can be recomputed without a second pull. A number the network does not report SHALL stay zero rather than be guessed. The moment of the pull SHALL be recorded on the publication. A pull that fails for one publication SHALL NOT stop the rest.

#### Scenario: Five numbers are stored whatever the network reported

- **GIVEN** publications on Mastodon, LinkedIn and X
- **WHEN** the daily metrics job runs
- **THEN** each SHALL carry views, likes, comments, shares and clicks
- **AND** a number its network does not report SHALL be zero
- **AND** each SHALL carry the moment it was read

@e2e exclude the pull is an outbound read against networks the CI instance is not connected to. Asserted by tests/Unit/Service/SocialMetricsServiceTest.php (testEachNetworkPayloadNormalisesToTheSameFiveNumbers).

#### Scenario: The provider's own payload is kept alongside

- **WHEN** a network's payload carries fields the normalisation does not use
- **THEN** the untouched payload SHALL be stored on the publication
- **AND** the normalised numbers SHALL be stored beside it, not instead of it

@e2e exclude same outbound read. Asserted by tests/Unit/Service/SocialMetricsServiceTest.php (testTheRawPayloadIsKeptBesideTheNormalisedNumbers).

#### Scenario: One failing pull does not stop the rest

- **GIVEN** three published publications, one of whose accounts needs a reconnect
- **WHEN** the daily metrics job runs
- **THEN** the other two SHALL have fresh numbers
- **AND** the third SHALL keep its previous numbers and its account SHALL be marked as needing a reconnect

@e2e exclude same outbound read. Asserted by tests/Unit/Service/SocialMetricsServiceTest.php (testOneFailingPullDoesNotStopTheRest).

#### Scenario: Follower counts are refreshed per account

- **WHEN** the daily metrics job runs
- **THEN** each connected account SHALL carry its follower count and the moment it was read

@e2e exclude same outbound read. Asserted by tests/Unit/Service/SocialMetricsServiceTest.php (testFollowerCountsAreRefreshedPerAccount).

### Requirement: Posts Are Ranked by Engagement Rate Per Network

The social performance page SHALL rank publications by engagement rate within each network, where engagement is likes, comments and shares together and the rate is that total against the account's follower count at the time of the pull. Comparing a company page with 900 followers against a spokesperson with 4,000 on raw counts answers the wrong question. An account with no followers recorded SHALL show no rate rather than an error, and a publication with no numbers yet SHALL sort last rather than be dropped. The page SHALL render its rows before any per-publication lookup, never after one.

#### Scenario: The ranking divides engagement by followers

- **GIVEN** two publications, one with 30 engagements on an account with 1,000 followers and one with 40 on an account with 4,000
- **WHEN** the ranking is computed
- **THEN** the first SHALL rank above the second

@e2e exclude the ranking is a pure computation over stored rows. Asserted by tests/vitest/socialRanking.spec.js and tests/Unit/Service/SocialMetricsServiceTest.php (testTheRankingUsesEngagementRateNotRawCounts).

#### Scenario: An account with no followers is not divided by zero

- **GIVEN** a publication whose account has no follower count recorded
- **WHEN** the ranking is computed
- **THEN** it SHALL show no rate and SHALL NOT produce an error or an infinite value

@e2e exclude same pure computation. Asserted by tests/vitest/socialRanking.spec.js (an account with no followers yields no rate).

#### Scenario: The performance page renders before its numbers

- **WHEN** a marketer opens the Social performance page
- **THEN** the page SHALL render its heading and its ranking table without waiting on a per-publication lookup
