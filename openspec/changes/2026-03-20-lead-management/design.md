# Design: lead-management enhancements

## Architecture Overview

Frontend-only changes using existing OpenRegister data.

## Key Design Decisions

### 1. Contact Person Picker
Same pattern as request-management: NcSelect filtered by selected client, disabled when no client selected, clears on client change.

### 2. Overdue Indicator
Computed property comparing `expectedCloseDate` with current date. Shows red "X days overdue" badge in the Core Info card when the lead is in a non-closed stage and past its expected close date.

### 3. Value Auto-Sync
The `syncLeadValue` handler already exists on LeadDetail and is emitted by LeadProducts. It updates the lead's value field to match line item totals.
