# Klachtenregistratie — Design

**Status**: pr-created

## Architecture

### Data Layer
- **Schema**: `complaint` in `pipelinq_register.json` (already defined)
- **Storage**: OpenRegister object storage (no own DB tables)
- **API**: Frontend calls OpenRegister API directly via objectStore

### Backend Services

#### ComplaintSlaService
- **Location**: `lib/Service/ComplaintSlaService.php`
- **Purpose**: Calculate SLA deadlines, retrieve SLA config per category
- **Dependencies**: `IAppConfig`, `LoggerInterface`
- **Methods**:
  - `calculateDeadline(string $category, ?DateTimeInterface $from=null): ?DateTimeImmutable` — returns deadline based on category SLA config (defaults to current time if $from is null)
  - `getSlaHoursForCategory(string $category): int` — reads `complaint_sla_{category}` from app config
  - `isOverdue(array $complaint, ?DateTimeInterface $now=null): bool` — checks if complaint is past SLA deadline (defaults to current time if $now is null)

#### ComplaintSlaJob
- **Location**: `lib/BackgroundJob/ComplaintSlaJob.php`
- **Purpose**: Periodic check for overdue complaints, logs warnings
- **Type**: `TimedJob` (runs every 15 minutes)
- **Dependencies**: `ComplaintSlaService`, `IAppConfig`, `LoggerInterface`, `ContainerInterface`
- **Behavior**: Queries open complaints by status (new, in_progress), checks each against SLA deadline, logs overdue ones

### Frontend (Already Implemented)
- `src/views/complaints/ComplaintList.vue` — List with filters, SLA indicators
- `src/views/complaints/ComplaintDetail.vue` — Detail with status transitions, audit trail
- `src/views/complaints/ComplaintForm.vue` — Create/edit form with validation
- `src/views/complaints/ComplaintCreateDialog.vue` — Dialog wrapper for quick creation
- `src/services/complaintStatus.js` — Status lifecycle, labels, SLA indicator logic
- `src/views/widgets/ComplaintsOverviewWidget.vue` — Dashboard widget

### Settings
- SLA hours per category stored as `complaint_sla_{category}` in app config
- Settings store getter: `getComplaintSlaHours(category)`

## Seed Data

### Complaint Seed Objects
```json
[
  {
    "title": "Onjuiste factuur ontvangen",
    "description": "Klant meldt dat het factuurbedrag niet overeenkomt met de offerte. Verschil van EUR 150.",
    "category": "billing",
    "priority": "high",
    "status": "new",
    "channel": "phone"
  },
  {
    "title": "Levertijd te lang",
    "description": "Bestelling is 2 weken te laat geleverd zonder communicatie over vertraging.",
    "category": "service",
    "priority": "normal",
    "status": "in_progress",
    "channel": "email"
  },
  {
    "title": "Onvriendelijke medewerker",
    "description": "Klant geeft aan dat de medewerker aan de balie onvriendelijk en ongeduldig was.",
    "category": "communication",
    "priority": "low",
    "status": "resolved",
    "channel": "counter",
    "resolution": "Medewerker aangesproken en excuses aangeboden aan klant.",
    "resolvedAt": "2026-03-20T14:30:00+01:00"
  },
  {
    "title": "Product defect bij levering",
    "description": "Geleverd product had zichtbare schade aan de verpakking en het product zelf.",
    "category": "product",
    "priority": "urgent",
    "status": "new",
    "channel": "web"
  },
  {
    "title": "Geen reactie op e-mail",
    "description": "Klant heeft 3 weken geleden een e-mail gestuurd maar nog geen reactie ontvangen.",
    "category": "communication",
    "priority": "high",
    "status": "in_progress",
    "channel": "email"
  }
]
```
