# Admin Settings — Delta Spec

## Purpose
Define the Nextcloud admin settings page for Pipelinq where administrators can view register status, see configured schemas, and trigger re-import of the register configuration.

**Main spec ref**: [admin-settings/spec.md](../../../../specs/admin-settings/spec.md)
**Feature tier**: MVP

---

## Requirements

### REQ-AS-001: Admin Settings Registration

The admin settings page MUST be registered with Nextcloud and accessible only to administrators.

#### Scenario: Admin settings page is accessible

- GIVEN an admin user navigates to Nextcloud Settings → Administration → Pipelinq
- THEN the Pipelinq admin settings page MUST be displayed
- AND it MUST show the register configuration section

#### Scenario: Non-admin cannot access admin settings

- GIVEN a regular user navigates to the Pipelinq admin settings URL
- THEN Nextcloud MUST prevent access
- AND the user MUST NOT see the Pipelinq admin settings section

---

### REQ-AS-002: Register Status Display

The admin settings page MUST display the current register configuration status.

#### Scenario: Register is configured

- GIVEN the repair step has run and the register exists
- WHEN the admin opens the settings page
- THEN it MUST show:
  - Register name: "pipelinq"
  - Register ID (from IAppConfig)
  - Status indicator: "Connected" (green)
  - List of 5 schemas with their names and IDs

#### Scenario: Register is not configured

- GIVEN OpenRegister is not installed or the repair step hasn't run
- WHEN the admin opens the settings page
- THEN it MUST show:
  - Status indicator: "Not configured" (orange/warning)
  - Message: "OpenRegister is required. Install and enable it, then click Re-import."

---

### REQ-AS-003: Re-import Action

The admin settings page MUST provide a button to re-run the register configuration import.

#### Scenario: Re-import succeeds

- GIVEN the admin clicks "Re-import configuration"
- WHEN the backend processes the request
- THEN it MUST call `ConfigurationService::importFromApp('pipelinq')`
- AND the page MUST refresh to show updated schema list
- AND a success notification MUST be displayed

#### Scenario: Re-import fails

- GIVEN OpenRegister is not available
- WHEN the admin clicks "Re-import configuration"
- THEN an error notification MUST be displayed
- AND the error message MUST indicate what went wrong
