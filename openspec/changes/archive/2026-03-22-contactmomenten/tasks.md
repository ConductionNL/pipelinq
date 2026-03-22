## 1. Register Schema [MVP]

- [x] 1.1 Add Contactmoment object definition to `lib/Settings/pipelinq_register.json` with all properties (subject, summary, channel, outcome, client, request, agent, contactedAt, duration, channelMetadata, notes), required fields (subject, channel), and `@type: schema:CommunicateAction`
- [x] 1.2 Verify the repair step (`ConfigurationService::importFromApp()`) imports the updated schema — run app update and confirm the contactmoment schema appears in OpenRegister

## 2. Pinia Store [MVP]

- [x] 2.1 Create `src/store/contactmomenten.js` Pinia store with state (contactmomenten list, current contactmoment, loading, error), getters (filtered by client, filtered by request), and actions (fetchAll, fetchOne, create, update, delete) calling OpenRegister API with `pipelinq` register and `contactmoment` schema
- [x] 2.2 Add pagination support (page, limit params) and filter support (channel, agent, dateFrom, dateTo, search) to the fetchAll action
- [x] 2.3 Add fetchByClient(clientId) and fetchByRequest(requestId) actions that query OpenRegister with reference filters

## 3. Routing and Navigation [MVP]

- [x] 3.1 Add route entries in `src/router/` for `/contactmomenten` (list) and `/contactmomenten/:id` (detail)
- [x] 3.2 Add "Contactmomenten" navigation item to the Pipelinq sidebar with phone/message icon, positioned between Requests and Products

## 4. Quick-Log Form Component [MVP]

- [x] 4.1 Create `src/components/ContactmomentQuickLog.vue` with props `clientId` (optional) and `requestId` (optional) for pre-filling; fields: subject (required), channel (required dropdown), client (optional search/select), request (optional search/select), summary, outcome (dropdown), duration, notes
- [x] 4.2 Wire form submission to the contactmomenten store `create` action, handle success (toast + emit event) and error (display validation messages)

## 5. Contactmomenten List View [MVP]

- [x] 5.1 Create `src/views/contactmomenten/ContactmomentenList.vue` with table displaying columns: subject, channel (with icon), client name, agent, contactedAt, outcome
- [x] 5.2 Add search bar (debounced 300ms), channel filter (multi-select), date range filter, and agent filter
- [x] 5.3 Add pagination controls (20 items per page) and sort by contactedAt descending default
- [x] 5.4 Add "Nieuw contactmoment" button that opens the QuickLog form with no pre-filled fields

## 6. Contactmoment Detail View [MVP]

- [x] 6.1 Create `src/views/contactmomenten/ContactmomentDetail.vue` displaying all fields: subject, summary, channel (with icon), outcome, agent (with avatar), contactedAt, duration, notes, channelMetadata
- [x] 6.2 Add linked client display (clickable link to client detail) and linked request display (clickable link to request detail)
- [x] 6.3 Add edit mode toggle with save/cancel functionality using the store update action
- [x] 6.4 Add delete button (visible only to creating agent or admin) with confirmation dialog

## 7. Client Detail Integration [V1]

- [x] 7.1 Add "Contactmomenten" to the client detail timeline filter options (alongside Leads, Requests, Contacts, Notes, Field changes)
- [x] 7.2 Query contactmomenten store for client-linked records and merge into the timeline feed sorted chronologically
- [x] 7.3 Display contactmoment timeline entries with subject, channel icon, and agent name
- [x] 7.4 Add "Log contactmoment" button on client detail that opens QuickLog with clientId pre-filled

## 8. Request Detail Integration [MVP]

- [x] 8.1 Add "Contactmomenten" section to the request detail view displaying linked contactmomenten (subject, channel icon, agent, contactedAt)
- [x] 8.2 Show empty state "Geen contactmomenten geregistreerd" when no linked contactmomenten exist
- [x] 8.3 Add "Log contactmoment" button that opens QuickLog with requestId and clientId pre-filled

## 9. Navigation Badge [MVP]

- [x] 9.1 Add count badge to the "Contactmomenten" navigation item showing the number of unresolved contactmomenten (no outcome) assigned to the current user today
