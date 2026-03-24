# Action Widgets — Technical Design

## File List

### PHP Widget Classes (lib/Dashboard/)

| File | Widget ID | Order | Replaces |
|------|-----------|-------|----------|
| `StartRequestWidget.php` | `pipelinq_start_request_widget` | 14 | — |
| `CreateLeadWidget.php` | `pipelinq_create_lead_widget` | 15 | — |
| `FindClientWidget.php` | `pipelinq_find_client_widget` | 13 | `ClientSearchWidget.php` |

Each follows the exact IWidget pattern from `MyLeadsWidget.php`: constructor with IL10N, getId(), getTitle(), getOrder(), getIconClass(), getUrl(), load().

### Vue Components

| File | Purpose |
|------|---------|
| `src/components/widgets/ClientAutocomplete.vue` | Shared client type-ahead autocomplete |
| `src/views/widgets/StartRequestWidget.vue` | Inline request creation form |
| `src/views/widgets/CreateLeadWidget.vue` | Inline lead creation form |
| `src/views/widgets/FindClientWidget.vue` | Enhanced search + actions (replaces ClientSearchWidget.vue) |

### JS Entry Points

| File | Registers Widget ID |
|------|-------------------|
| `src/startRequestWidget.js` | `pipelinq_start_request_widget` |
| `src/createLeadWidget.js` | `pipelinq_create_lead_widget` |
| `src/findClientWidget.js` | `pipelinq_find_client_widget` |

### Modified Files

| File | Change |
|------|--------|
| `lib/AppInfo/Application.php` | Remove ClientSearchWidget, add 3 new widget registrations |
| `webpack.config.js` | Add 3 new entry points, remove clientSearchWidget entry |

---

## Shared ClientAutocomplete Component

```
Props:
  - value: String (selected client UUID)
  - placeholder: String

Emits:
  - input(clientObject) — full client object with id, name, email, phone, type

Behavior:
  1. On mount: fetch objectTypeRegistry via initializeStores()
  2. On input (debounced 300ms): GET clients with _search=query&_limit=10
  3. Show dropdown with name + email for each match
  4. On select: emit client object, close dropdown
  5. On clear: emit null
```

---

## Data Flow Per Widget

### StartRequestWidget

```
1. Mount → initializeStores() → get objectTypeRegistry
2. User fills form (title, client via ClientAutocomplete, category, priority, channel)
3. Submit → POST /apps/openregister/api/objects/{register}/{requestSchema}
   Body: { title, client: uuid, category, priority, channel, status: "new", requestedAt: ISO8601 }
4. On success → show success message + link to /index.php/apps/pipelinq/requests/{id}
5. Also: fetch 3 recent requests on mount for display below form
```

### CreateLeadWidget

```
1. Mount → initializeStores() → get objectTypeRegistry
2. Mount → fetch pipelines via GET /apps/openregister/api/objects/{register}/{pipelineSchema}
3. Pre-select first pipeline, extract first stage name + order
4. User fills form (title, client via ClientAutocomplete, pipeline, value, source)
5. Submit → POST /apps/openregister/api/objects/{register}/{leadSchema}
   Body: { title, client: uuid, pipeline: uuid, value, source, status: "open", stage: firstStageName, stageOrder: 1 }
6. On success → show success message + link to /index.php/apps/pipelinq/leads/{id}
7. Quick-add: Enter key in title field triggers submit with minimal fields
```

### FindClientWidget

```
1. Mount → initializeStores() → get objectTypeRegistry
2. Mount → fetch all clients (_limit: 200)
3. User types search → filter reactively (name, email, phone)
4. Per result: show name, type icon, email/phone
5. Action buttons:
   - View → navigate to /index.php/apps/pipelinq/clients/{id}
   - Create request → open inline request form pre-filled with client
   - Create lead → open inline lead form pre-filled with client
   - Copy email → navigator.clipboard.writeText(email)
6. "New client" button → show inline form (name, type dropdown, email)
   - Submit → POST /apps/openregister/api/objects/{register}/{clientSchema}
   - On success → add to list, clear form
```

---

## Widget Replacement Strategy

`FindClientWidget` replaces `ClientSearchWidget`:

1. **Same order (13)** — appears in same dashboard position
2. **New ID** (`pipelinq_find_client_widget`) — users who had ClientSearchWidget may need to re-add it
3. Application.php: remove `ClientSearchWidget::class`, add `FindClientWidget::class`
4. webpack.config.js: remove `clientSearchWidget` entry, add `findClientWidget` entry
5. Old files (`ClientSearchWidget.php`, `ClientSearchWidget.vue`, `clientSearchWidget.js`) are NOT deleted — they remain for reference but are unregistered

---

## Webpack Config Changes

Remove:
```js
clientSearchWidget: {
    import: path.join(__dirname, 'src', 'clientSearchWidget.js'),
    filename: appId + '-clientSearchWidget.js',
},
```

Add:
```js
startRequestWidget: {
    import: path.join(__dirname, 'src', 'startRequestWidget.js'),
    filename: appId + '-startRequestWidget.js',
},
createLeadWidget: {
    import: path.join(__dirname, 'src', 'createLeadWidget.js'),
    filename: appId + '-createLeadWidget.js',
},
findClientWidget: {
    import: path.join(__dirname, 'src', 'findClientWidget.js'),
    filename: appId + '-findClientWidget.js',
},
```
