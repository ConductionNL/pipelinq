## ADDED Requirements

### Requirement: Social performance is summed per network for a period

The app SHALL answer one row per network that published in the last N days (default 30): the number of publications, the summed views, likes, comments and shares, the follower count of that network's accounts, and the interaction rate as likes, comments and shares over views in percent, rounded to one decimal. A network with no views SHALL show no rate. The read SHALL refuse a user without marketing access, like the per-publication ranking.

#### Scenario: Two publications on one network add up

- **GIVEN** two Mastodon publications in the last 30 days, one older publication, and a Mastodon account with 2,140 followers
- **WHEN** the per-network totals are read
- **THEN** the Mastodon row SHALL count 2 publications, sum their numbers, carry 2,140 followers and leave the older publication out

@e2e exclude a computation over stored rows. Asserted by tests/Unit/Service/SocialMetricsServiceTest.php (testNetworkTotalsAddUpThePeriodPerNetwork).

#### Scenario: A user without marketing access is refused

- **WHEN** a user without marketing access asks for the per-network totals
- **THEN** the app SHALL answer 403 and SHALL NOT read any publication

@e2e exclude access check. Asserted by tests/Unit/Controller/SocialPostControllerTest.php (testNetworkPerformanceRefusesWithoutMarketingAccess).
