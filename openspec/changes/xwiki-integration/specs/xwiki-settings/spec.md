## ADDED Requirements

---

### Requirement: xWiki Integration Settings

The system MUST provide admin settings for configuring the xWiki integration within Pipelinq. These settings control how the proxy connects to xWiki and how widgets behave by default.

**Feature tier**: V1

#### Scenario: Configure default xWiki space

- GIVEN an admin navigates to Pipelinq settings
- WHEN they set the "Standaard xWiki ruimte" field to "Kennisbank"
- THEN all xWiki widgets and sidebar tabs without an explicit `space` prop MUST use "Kennisbank" as the default space filter

#### Scenario: Configure cache TTL

- GIVEN an admin navigates to Pipelinq settings
- WHEN they set the "Cache duur" field to 10 minutes
- THEN the xWiki proxy MUST cache responses for 10 minutes instead of the default 5

#### Scenario: Display xWiki connection status

- GIVEN an admin navigates to Pipelinq settings
- WHEN the xWiki settings section loads
- THEN it MUST show the current xWiki connection status (available/unavailable)
- AND display the xWiki version if available
- AND show a "Test verbinding" button to manually check connectivity

#### Scenario: xWiki app not installed warning

- GIVEN the `nextcloud/xwiki` app is NOT installed or NOT enabled
- WHEN an admin views the xWiki settings section
- THEN a warning MUST be displayed: "De xWiki Nextcloud app is niet geinstalleerd of niet ingeschakeld. Installeer de xWiki app voor volledige integratie." (translatable)
- AND the settings fields MUST still be editable (for pre-configuration)

---

### Requirement: xWiki Integration Fallback Configuration

The system MUST support direct xWiki REST API connection when the `nextcloud/xwiki` app is not available.

**Feature tier**: V1

#### Scenario: Configure direct xWiki URL

- GIVEN the `nextcloud/xwiki` app is not installed
- WHEN an admin sets the "xWiki URL" field to "http://conduction-xwiki:8080"
- THEN the xWiki proxy MUST use this URL directly for REST API calls
- AND authentication MUST fall back to unauthenticated access (public pages only)

#### Scenario: Prefer xWiki NC app when available

- GIVEN both the `nextcloud/xwiki` app is installed AND a direct URL is configured
- WHEN the proxy makes requests
- THEN it MUST prefer the xWiki NC app's instance configuration (which includes user tokens)
- AND ignore the direct URL setting
