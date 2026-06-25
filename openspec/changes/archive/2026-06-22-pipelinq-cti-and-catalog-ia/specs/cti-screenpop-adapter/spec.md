# CTI Screen-Pop Adapter — merge admin to one Settings page delta

**Spec ref**: `cti-screenpop-adapter`

This delta is information-architecture placement only — no feature contract,
data model, or API change. The CTI config and event-log views are reused
verbatim, only re-hosted on one page and re-placed under Settings.

## ADDED Requirements

### Requirement: CTI administration on one Settings page

The system MUST present the CTI (telephony) integration configuration and the
CTI webhook event log as a single page in the left-nav Settings section, titled
"CTI (telephony)", rather than as two separate entries in the Administration
group. The page MUST show both the integration configuration and the webhook
event log. The legacy standalone routes MUST remain reachable for deep links.

#### Scenario: Single CTI entry under Settings shows config and log

- GIVEN the user opens the Pipelinq app
- WHEN they read the left-nav Settings section
- THEN exactly one "CTI (telephony)" entry MUST be present in Settings
- AND no separate "CTI integration" or "CTI event log" entry MUST appear in the Administration group
- AND opening it MUST render both the integration configuration (platform, API base URL, auth method, screen-pop / click-to-dial toggles, save + test connection) and the webhook event-log table

#### Scenario: Legacy CTI deep links still resolve

- GIVEN a deep link to the legacy CTI event-log route `/settings/cti/event-log`
- WHEN the link is followed
- THEN the CTI event-log view MUST render
