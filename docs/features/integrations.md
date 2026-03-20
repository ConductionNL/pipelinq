# Integrations & Automation

Backend integrations, workflow automation, and external system connectivity that extend Pipelinq's CRM capabilities.

## Specs

- `openspec/specs/crm-workflow-automation/spec.md`
- `openspec/specs/email-calendar-sync/spec.md`
- `openspec/specs/contact-relationship-mapping/spec.md`
- `openspec/specs/prospect-discovery/spec.md`
- `openspec/specs/public-intake-forms/spec.md`
- `openspec/specs/register-i18n/spec.md`
- `openspec/specs/prometheus-metrics/spec.md`

## Features

### CRM Workflow Automation (Planned -- V1)

Expose n8n workflow automation within the Pipelinq UI:
- Visual workflow builder for CRM automation
- Trigger-action workflows on CRM events (lead created, stage changed, etc.)
- Conditional branching and scheduled actions
- Bridges n8n's powerful backend with Pipelinq's user-facing interface

### Email & Calendar Sync (Planned -- V1)

Bidirectional email and calendar synchronization:
- Emails auto-linked to contacts by matching sender/recipient addresses
- Calendar events for follow-ups synced with Nextcloud Calendar
- Leverages Nextcloud's built-in Mail and Calendar apps
- Domain-based company linking

### Contact Relationship Mapping (Planned -- V1)

Bidirectional typed relationships between contacts:
- Relationship types: parent/child, partner, colleague, employer/employee
- Auto-created inverse relationships
- Government use cases: family relationships for social domain, company structures for permits
- Organizational hierarchies for CRM relationship management

### Prospect Discovery (Planned -- V1)

Find new potential clients by searching public company registries:
- KVK Handelsregister and OpenCorporates integration
- Configurable Ideal Customer Profile (ICP) matching
- Fit scoring and existing client exclusion
- Dashboard widget showing top prospects with "Create Lead" action
- Configured via admin settings Prospect Discovery section

### Public Intake Forms (Planned -- V1)

Embeddable HTML forms for external websites:
- Create contacts and leads on submission
- Customizable styling
- Spam protection (CAPTCHA/honeypot)
- Embed via iframe or JavaScript snippet
- Government use: contact/request forms on municipality websites

### Register Content Internationalization (Planned -- V1)

Multi-language support for Pipelinq register objects:
- Language-tagged fields for CRM content
- Built on OpenRegister's register-i18n foundation
- Users view/manage content in preferred language

### Prometheus Metrics Endpoint (Planned -- V1)

Application metrics in Prometheus text exposition format:
- Exposed at `GET /api/metrics`
- Monitoring, alerting, and operational dashboards
- CRM-specific metrics (lead counts, pipeline values, request volumes)

### Activity Timeline (Planned -- V1)

Chronological activity feed per entity:
- Status changes, notes, emails, calls, document uploads, field changes
- Unified timeline on client, contact, lead, and request detail views
- Complete "klantbeeld" (customer view) history
- See also: `openspec/specs/activity-timeline/spec.md`
