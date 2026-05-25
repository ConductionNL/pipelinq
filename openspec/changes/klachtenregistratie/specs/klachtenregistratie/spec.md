<!-- ⚠️ EXTENSION NOTICE (auto-inserted by fix_extension_artifacts.py)
     Parent capability: omnichannel-registratie (Omnichannel Registratie)
     This spec extends the existing `omnichannel-registratie` capability. Do NOT define new entities or build new CRUD — reuse what `omnichannel-registratie` already provides. Your job is to add configuration, seed data, or workflow templates on top of that capability.
-->

# Klachtenregistratie (Complaint Registration) — Delta Spec

## Purpose
Add complaint registration and tracking to Pipelinq, enabling KCC agents and CRM users to register, categorize, follow up on, and resolve customer complaints. Complaints are linked to contacts and organizations with SLA-based deadline tracking and full audit trail.

**Main spec ref**: `openspec/specs/klachtenregistratie/spec.md`
**Feature tier**: V1
**Schema.org type**: `schema:ComplainAction`
**VNG mapping**: Klacht (no formal ZGW standard yet; modeled after Verzoek pattern)

---

## Requirements

### REQ-KL-001: Complaint Schema in Register
The system MUST define a `complaint` schema in the Pipelinq register configuration with all required fields.

### REQ-KL-002: Complaint Registration Form
The system MUST provide a form for creating and editing complaints with validation.

### REQ-KL-003: Complaint List View
The complaint list MUST support search, filtering, sorting, and pagination.

### REQ-KL-004: Complaint Detail View
The detail view MUST show all information, linked entities, status timeline, and resolution fields.

### REQ-KL-005: Complaint Audit Trail
The system MUST maintain a full audit trail of all status changes.

### REQ-KL-006: Complaint Dashboard Widget
The dashboard MUST include a complaints widget showing key metrics.

### REQ-KL-007: Complaints on Client Detail
Complaints linked to a client MUST be visible on the client detail view.

### REQ-KL-008: SLA Configuration
Admin settings MUST allow configuring SLA response times per complaint category.

### REQ-KL-009: Backend SLA Deadline Service
A PHP service MUST calculate SLA deadlines and provide SLA configuration helpers.

### REQ-KL-010: Background Job for SLA Monitoring
A background job MUST periodically check for overdue complaints and log warnings.
