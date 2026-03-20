# Design: request-management contact linking

## Architecture Overview

Frontend-only changes. The `contact` field already exists in the request OpenRegister schema. The contact picker fetches contact objects filtered by the selected client UUID.

## Key Design Decisions

### 1. Contact Picker Filtered by Client

**Decision**: Add an NcSelect dropdown for contact persons that fetches contacts where `client` matches the selected client UUID.

**Behavior**:
- When no client is selected, the contact picker is disabled with placeholder "Select a client first"
- When a client is selected, the picker fetches contacts for that client
- When the client changes, the contact field is cleared and contacts are re-fetched

### 2. Contact Display on Detail View

**Decision**: Add a "Contact Person" section to RequestDetail.vue showing the linked contact's name, email, and phone with a clickable link to the contact detail view.
