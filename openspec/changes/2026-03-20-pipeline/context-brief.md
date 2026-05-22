# Proposal: pipeline search and stage validation

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Pipeline

**Rationale:** Search + stage validation enhancements to main Pipeline.  
_Source: /tmp/ia-pipelinq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Problem

REQ-PIPE-022: No search/filter on pipeline view.
REQ-PIPE-005: Stage probability range not validated.

## Proposed Change

- Add search bar to pipeline header filtering by title
- Add probability range validation (0-100)

## Impact
- **Files modified**: 2 Vue files
- **Risk**: Low



## Design

# Design: pipeline search and stage validation

Search filters in-memory items by title. Probability validated 0-100 in PipelineForm stage errors.



## Tasks

# Tasks: pipeline search and stage validation

## 1. Pipeline Search
- [ ] 1.1 Add search input to PipelineBoard header (REQ-PIPE-022)

## 2. Stage Validation
- [ ] 2.1 Add probability range validation to PipelineForm (REQ-PIPE-005)
