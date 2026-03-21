# Admin Settings Specification

## Problem
The admin settings page provides a Nextcloud admin panel for configuring Pipelinq. Administrators can manage pipelines and their stages, set a default pipeline, configure lead source and request channel values, manage product categories, and configure prospect discovery (ICP) settings. Only Nextcloud admin users can access the admin settings page; regular users access per-user notification preferences via an in-app settings dialog. The design follows the wireframe in DESIGN-REFERENCES.md section 3.7.
**Feature tier**: MVP (admin page, version info, register mapping, pipeline CRUD, stage CRUD, default pipeline, re-import), V1 (lead source config, request channel config, product categories, prospect discovery ICP)
---

## Proposed Solution
Implement Admin Settings Specification following the detailed specification. Key requirements include:
- Requirement: REQ-AS-011: Version Information Display [MVP]
- Requirement: REQ-AS-012: Register Configuration Mapping [MVP]
- Requirement: REQ-AS-013: Re-import Configuration Action [MVP]
- Requirement: REQ-AS-020: Pipeline Management [MVP]
- Requirement: REQ-AS-030: Stage Management within Pipelines [MVP]

## Scope
This change covers all requirements defined in the admin-settings specification.

## Success Criteria
- Admin user accesses settings
- Non-admin user cannot access settings
- Settings page structure
- Non-admin user can read settings via API
- Version info card renders
