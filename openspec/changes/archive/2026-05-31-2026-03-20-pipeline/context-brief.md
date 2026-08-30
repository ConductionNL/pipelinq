# Proposal: pipeline search and stage validation

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