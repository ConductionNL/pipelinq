# Data Model — Pipelinq

**App:** Pipelinq — CRM and customer interaction
**Platform:** OpenRegister (register/schema/object pattern)
**Entities:** 26

OpenRegister built-in fields available on ALL entities (do NOT redefine):
id, uuid, uri, version, createdAt, updatedAt, owner, organization,
register, schema, relations, files, auditTrail, notes, tasks, tags, status, locked.

OpenRegister built-in capabilities (do NOT rebuild):
CRUD REST API, CSV/JSON/XML import+export, full-text search, filtering,
pagination, audit trails, file attachments, relation management, locking.

---

## agentProfile
**Purpose:** Links a Nextcloud user to their assigned skills and routing configuration. Used for skill-based routing suggestions and workload management.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| userId | string | Yes | Nextcloud user UID |
| skills | array | No | UUID references to assigned Skill objects |
| maxConcurrent | integer | No | Maximum number of concurrent open items for this agent |
| isAvailable | boolean | No | Whether this agent is available for routing suggestions |

---

## automation
**Purpose:** Represents a trigger-action automation for CRM events. When the trigger fires and conditions match, the configured actions execute in sequence. Optionally fires an n8n webhook.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Automation name |
| trigger | string | Yes | The CRM event that activates this automation |
| triggerConditions | object | No | Filter conditions for the trigger (e.g., stage, pipeline, value threshold) |
| actions | array | No | Ordered list of actions to execute when triggered |
| isActive | boolean | No | Whether the automation is enabled |
| lastRun | string | No | ISO timestamp of last execution |
| runCount | integer | No | Total number of times this automation has executed |
| webhookUrl | string | No | n8n webhook URL for external workflow execution |
| n8nWorkflowId | string | No | Reference to the n8n workflow ID |

---

## automationLog
**Purpose:** Records each execution of an automation including trigger details, actions executed, and outcome.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| automation | string | Yes | UUID reference to the automation that executed |
| triggeredAt | string | Yes | When the automation was triggered |
| triggerEntity | string | No | UUID of the entity that triggered the automation |
| actionsExecuted | array | No | List of actions executed and their results |
| status | string | Yes | Execution outcome |
| error | string | No | Error message if execution failed |

---

## calendarLink
**Purpose:** Stores metadata for calendar events synced with Nextcloud Calendar and linked to Pipelinq entities.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| eventUid | string | Yes | Calendar event UID |
| title | string | No | Event title |
| startDate | string | No | Event start date and time |
| endDate | string | No | Event end date and time |
| attendees | array | No | Attendee email addresses |
| linkedEntityType | string | Yes | Type of linked CRM entity |
| linkedEntityId | string | Yes | UUID of the linked CRM entity |
| status | string | No | Event status |
| createdFrom | string | No | Where the event was created |
| notes | string | No | Post-event notes |

---

## client
**Purpose:** Represents a client entity — either a natural person or an organization. Mapped to Schema.org Person/Organization and vCard (RFC 6350) field conventions. Clients are the primary relationship entity in Pipelinq.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Full name of the person or organization (schema:name / vCard FN) |
| type | string | Yes | Entity type — person or organization (maps to schema:Person or schema:Organization) |
| email | string | No | Primary email address (schema:email / vCard EMAIL) |
| phone | string | No | Primary phone number (schema:telephone / vCard TEL) |
| address | string | No | Postal address (schema:address) |
| website | string | No | Website URL (schema:url) |
| industry | string | No | Industry or sector (schema:industry) |
| notes | string | No | Free-text notes about the client (schema:description) |
| contactsUid | string | No | Nextcloud Contacts UID linking this client to a vCard in the user's addressbook |

---

## complaint
**Purpose:** Represents a customer complaint linked to a client and optionally a contact person. Tracks status lifecycle, priority, category, SLA deadline, and resolution. Mapped to Schema.org ComplainAction.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Complaint title or subject |
| description | string | No | Detailed description of the complaint |
| category | string | Yes | Complaint category for classification |
| priority | string | No | Complaint priority level |
| status | string | No | Current status in the complaint lifecycle |
| channel | string | No | Channel through which the complaint was received |
| client | string | No | UUID reference to the associated client |
| contact | string | No | UUID reference to the associated contact person |
| assignedTo | string | No | Nextcloud user UID of the assigned handler |
| slaDeadline | string | No | SLA deadline for complaint resolution, calculated from category config |
| resolvedAt | string | No | Date and time the complaint was resolved or rejected |
| resolution | string | No | Explanation of how the complaint was resolved or why it was rejected |

---

## contact
**Purpose:** Represents a contact person associated with a client organization. Properties align with vCard (RFC 6350) field conventions.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Full name of the contact person (vCard FN) |
| email | string | No | Email address (vCard EMAIL) |
| phone | string | No | Phone number (vCard TEL) |
| role | string | No | Job title or role within the organization (vCard ROLE) |
| client | string | No | UUID reference to the parent client object |
| contactsUid | string | No | Nextcloud Contacts UID linking this contact to a vCard in the user's addressbook |

---

## contactmoment
**Purpose:** Represents a single interaction with a client across any channel (phone, email, counter, chat, social media, letter). Mapped to Schema.org CommunicateAction and VNG Klantinteracties Contactmoment.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| subject | string | Yes | Subject of the contact moment (schema:about / Contactmoment.onderwerp) |
| summary | string | No | Summary or notes of the interaction (schema:description / Contactmoment.tekst) |
| channel | string | Yes | Communication channel used (schema:instrument / Contactmoment.kanaal) |
| outcome | string | No | Result of the interaction (schema:result / Contactmoment.resultaat) |
| client | string | No | UUID reference to the associated client (schema:recipient / KlantContactmoment) |
| request | string | No | UUID reference to the associated request (schema:object / ObjectContactmoment) |
| agent | string | No | Nextcloud user UID of the agent who handled the interaction (schema:agent / Contactmoment.medewerker) |
| contactedAt | string | No | Date and time of the interaction (schema:startTime / Contactmoment.registratiedatum) |
| duration | string | No | Duration of the interaction in ISO 8601 format (schema:duration / Contactmoment.gespreksduur) |
| channelMetadata | object | No | Channel-specific metadata (e.g., call direction, email thread ID, counter location) |
| notes | string | No | Additional internal notes (schema:text / Contactmoment.notitie) |

---

## emailLink
**Purpose:** Stores metadata for emails synced from Nextcloud Mail and linked to Pipelinq entities. Full email body is accessed on-demand from Nextcloud Mail.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| messageId | string | Yes | Email message ID from Nextcloud Mail |
| subject | string | No | Email subject line |
| sender | string | No | Sender email address |
| recipients | array | No | Recipient email addresses |
| date | string | No | Email date |
| threadId | string | No | Email thread ID for conversation grouping |
| linkedEntityType | string | Yes | Type of linked CRM entity |
| linkedEntityId | string | Yes | UUID of the linked CRM entity |
| direction | string | No | Email direction |
| syncSource | string | No | Nextcloud Mail account ID |
| excluded | boolean | No | Whether this email is excluded from future sync |
| deleted | boolean | No | Whether the source email has been deleted |

---

## intakeForm
**Purpose:** Defines a customizable web form that can be embedded on external websites. Submissions create contacts and leads in Pipelinq.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Form name |
| fields | array | No | Ordered list of form field definitions |
| fieldMappings | object | No | Maps form field names to contact/lead properties |
| targetPipeline | string | No | UUID of the pipeline where new leads are placed |
| targetStage | string | No | Initial pipeline stage for new leads |
| notifyUser | string | No | Nextcloud user ID to notify on new submissions |
| isActive | boolean | No | Whether the form accepts submissions |
| submitCount | integer | No | Total number of submissions received |
| successMessage | string | No | Message shown after successful submission |

---

## intakeSubmission
**Purpose:** Records each submission with submitted data, created entities, and processing status.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| form | string | Yes | UUID reference to the intake form |
| submittedAt | string | Yes | When the submission was received |
| data | object | No | Submitted form data (key-value pairs) |
| contactId | string | No | UUID of created or matched contact |
| leadId | string | No | UUID of created lead |
| ip | string | No | Submitter IP address (for rate limiting audit) |
| status | string | Yes | Processing status |

---

## kennisartikel
**Purpose:** Represents a knowledge base article with rich text content, categorization, versioning, and visibility controls. Mapped to Schema.org Article. Used by KCC agents for first-call resolution and optionally published for citizen self-service.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Article title (schema:headline) |
| body | string | Yes | Article content in Markdown format (schema:articleBody) |
| summary | string | No | Short summary for search result snippets (schema:abstract) |
| status | string | Yes | Article lifecycle status |
| visibility | string | Yes | Access level — intern (agents only) or openbaar (public) |
| categories | array | No | UUID references to kenniscategorie objects |
| tags | array | No | Searchable tags for article discovery |
| zaaktypeLinks | array | No | References to zaaktypen for context-aware suggestions |
| author | string | Yes | Nextcloud user UID of the article author |
| lastUpdatedBy | string | No | Nextcloud user UID of the last editor |
| version | integer | No | Article version number, incremented on each edit |
| publishedAt | string | No | Publication timestamp |
| archivedAt | string | No | Archive timestamp |
| usefulnessScore | number | No | Aggregate usefulness rating score (percentage of positive ratings) |

---

## kenniscategorie
**Purpose:** Represents a category in the knowledge base taxonomy. Supports up to 3 levels of hierarchy via parent references.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Category name |
| slug | string | No | URL-friendly name for the category |
| parent | string | No | UUID reference to parent category for hierarchy |
| description | string | No | Category description |
| order | integer | No | Display order within the same parent level |
| icon | string | No | Icon identifier for the category |

---

## kennisfeedback
**Purpose:** Represents an agent's rating and optional improvement suggestion for a knowledge article. Supports KCS methodology for continuous knowledge improvement.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| article | string | Yes | UUID reference to the rated kennisartikel |
| rating | string | Yes | Usefulness rating |
| comment | string | No | Improvement suggestion text |
| agent | string | Yes | Nextcloud user UID of the rating agent |
| status | string | No | Feedback processing status |
| createdAt | string | No | Date and time the feedback was submitted |

---

## lead
**Purpose:** Represents a sales lead — a potential deal or business opportunity linked to a client. Tracks value, probability, pipeline stage, and lifecycle status. Mapped to Schema.org Demand.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Lead title / opportunity name (schema:name) |
| client | string | No | UUID reference to the associated client |
| contact | string | No | UUID reference to the associated contact person |
| source | string | No | Origin of the lead (e.g., website, referral, cold-call, advertisement, event) |
| value | number | No | Estimated deal value in euros (schema:price) |
| probability | integer | No | Estimated win probability as percentage (0-100) |
| expectedCloseDate | string | No | Expected close date for the opportunity |
| assignee | string | No | Nextcloud user UID of the assigned sales representative |
| priority | string | No | Lead priority level |
| pipeline | string | No | UUID reference to the pipeline this lead is tracked in |
| stage | string | No | Current pipeline stage name |
| stageOrder | integer | No | Numeric position of the current stage in the pipeline |
| notes | string | No | Free-text notes about the lead |
| status | string | No | Lifecycle status of the lead |

---

## leadProduct
**Purpose:** Represents a product line item on a lead — an instance of a product with deal-specific quantity, pricing, and discount. The total is computed as quantity * unitPrice * (1 - discount/100). Mapped to Schema.org Offer.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| lead | string | Yes | UUID reference to the parent Lead |
| product | string | Yes | UUID reference to the Product |
| quantity | number | Yes | Number of units |
| unitPrice | number | Yes | Price per unit (pre-populated from Product.unitPrice, can be overridden) |
| discount | number | No | Discount percentage (0-100) |
| total | number | No | Computed total: quantity * unitPrice * (1 - discount/100) |
| notes | string | No | Line item notes (e.g., annual license, setup fee) |

---

## pipeline
**Purpose:** Represents a pipeline — an ordered list of stages through which entities progress. Backed by an OpenRegister View that defines which schemas appear on the board. Each schema can have its own property-to-stage mapping and totals configuration. Mapped to Schema.org ItemList.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Pipeline name (e.g., 'Sales Pipeline', 'Service Pipeline') |
| description | string | No | Description of the pipeline's purpose |
| viewId | string | No | UUID reference to the OpenRegister View defining which schemas this pipeline displays |
| propertyMappings | array | No | Per-schema configuration for column placement and totals aggregation |
| totalsLabel | string | No | Display label for column totals (e.g., 'EUR', 'Hours') |
| stages | array | Yes | Ordered list of pipeline stages (schema:ItemListElement) |
| isDefault | boolean | No | Whether this is the default pipeline for its entity type |

---

## product
**Purpose:** Represents a product or service in the CRM catalog. Linked to leads via LeadProduct line items for accurate pipeline valuation. Mapped to Schema.org Product.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Product or service name (schema:name) |
| description | string | No | Detailed product description (schema:description) |
| sku | string | No | Stock keeping unit or product code (schema:sku) |
| unitPrice | number | Yes | Default selling price per unit in EUR (schema:price) |
| cost | number | No | Cost to the organization per unit (for margin calculation) |
| category | string | No | UUID reference to a ProductCategory object |
| type | string | Yes | Whether this is a physical product or a service |
| status | string | No | Whether the product is available for sale |
| unit | string | No | Unit of measure (e.g., each, hour, license, month) |
| taxRate | number | No | Tax percentage (0-100). Default: 21 (Dutch BTW) |
| image | string | No | URL to product image |

---

## productCategory
**Purpose:** Represents a product category for hierarchical grouping. Categories can have parent categories for tree structures. Mapped to Schema.org DefinedTermSet.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Category name (schema:name) |
| description | string | No | Category description |
| parent | string | No | UUID reference to parent category (for hierarchy) |
| order | integer | No | Display order within the same parent level |

---

## queue
**Purpose:** Represents a named queue for organizing requests with priority-based ordering. Used for workload distribution and skill-based routing in KCC/service desk scenarios. Mapped to Schema.org ItemList.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Queue name (e.g., 'Algemene Zaken', 'Vergunningen') |
| description | string | No | Description of the queue's purpose |
| categories | array | No | Category tags for routing (matched against request categories and agent skills) |
| isActive | boolean | No | Whether the queue is active and accepting items |
| maxCapacity | integer | No | Maximum number of items allowed in the queue (null = unlimited) |
| sortOrder | integer | No | Display order of the queue in the list |
| assignedAgents | array | No | Nextcloud user UIDs of agents assigned to work this queue |

---

## relationship
**Purpose:** Represents a relationship between two entities (contacts and/or clients) with a typed, bidirectional link. Inverse relationships are auto-created. Mapped to Schema.org Person.knows.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| fromContact | string | Yes | UUID reference to the source contact or client |
| toContact | string | Yes | UUID reference to the target contact or client |
| fromType | string | No | Entity type of source: contact or client |
| toType | string | No | Entity type of target: contact or client |
| type | string | Yes | Relationship type identifier (e.g., partner, ouder, werkgever) |
| inverseType | string | Yes | The inverse relationship type identifier |
| category | string | No | Category grouping for the relationship type (Familie, Professioneel, Organisatie, CRM Rol) |
| notes | string | No | Optional free text context for this relationship |
| startDate | string | No | Date when the relationship started |
| endDate | string | No | Date when the relationship ended (null = active) |
| strength | string | No | Relationship strength: strong, medium, weak |

---

## request
**Purpose:** Represents a client service request that may be converted to a case in Procest. Tracks status lifecycle, priority, assignment, and optional pipeline placement.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Request title |
| description | string | No | Detailed description of the request |
| client | string | No | UUID reference to the associated client |
| contact | string | No | UUID reference to the associated contact person |
| status | string | No | Current status in the request lifecycle |
| priority | string | No | Request priority level |
| assignee | string | No | Nextcloud user UID of the assigned handler |
| requestedAt | string | No | Date and time the request was submitted |
| category | string | No | Request category for classification |
| pipeline | string | No | UUID reference to the pipeline this request is tracked in |
| stage | string | No | Current pipeline stage name |
| stageOrder | integer | No | Numeric position of the current stage in the pipeline |
| channel | string | No | Intake channel for the request (e.g., phone, email, website) |
| queue | string | No | UUID reference to the queue this request is assigned to |
| caseReference | string | No | UUID reference to the converted Procest case |

---

## skill
**Purpose:** Represents a defined skill or area of expertise that can be assigned to agents. Skills are matched against request categories for routing suggestions. Mapped to Schema.org DefinedTerm.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Skill name (e.g., 'Vergunningen', 'WMO / Zorg') |
| description | string | No | Description of the skill |
| categories | array | No | Category tags this skill covers (matched against request categories) |
| isActive | boolean | No | Whether this skill is active for routing |

---

## survey
**Purpose:** Represents a KTO (klanttevredenheidsonderzoek) survey with configurable questions, public access token, and entity linking. Mapped to Schema.org Survey.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Survey title (schema:name) |
| description | string | No | Survey description shown to respondents (schema:description) |
| questions | array | Yes | Ordered list of survey questions |
| status | string | No | Survey lifecycle status |
| token | string | No | Unique public access token (UUID) for the survey response URL |
| linkedEntityType | string | No | Entity type this survey is linked to |
| linkedEntityId | string | No | UUID of the specific entity this survey is linked to |
| activeFrom | string | No | Start date for accepting responses |
| activeUntil | string | No | End date for accepting responses |
| createdBy | string | No | Nextcloud user UID of the survey creator |
| createdAt | string | No | Date and time the survey was created |
| updatedAt | string | No | Date and time the survey was last updated |

---

## surveyResponse
**Purpose:** Represents a single completed survey response with answers to survey questions. Linked to the parent survey via surveyId. Mapped to Schema.org CompletedSurvey.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| surveyId | string | Yes | UUID reference to the parent survey |
| answers | array | Yes | List of question answers |
| respondentId | string | No | Optional respondent identifier for deduplication |
| entityType | string | No | Entity type that triggered this survey response |
| entityId | string | No | UUID of the entity that triggered this response |
| completedAt | string | No | Date and time the response was submitted |
| ipHash | string | No | SHA-256 hash of respondent IP address for abuse detection |

---

## task
**Purpose:** Represents an internal task — a callback request (terugbelverzoek), follow-up task (opvolgtaak), or information request (informatievraag) assigned to a user or department. Maps to VNG InterneTaak and Schema.org Action.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| type | string | Yes | Task type — terugbelverzoek (callback), opvolgtaak (follow-up), or informatievraag (information request) |
| subject | string | Yes | Task subject line (VNG gevraagdeHandeling / schema:name) |
| description | string | No | Detailed task description (VNG toelichting / schema:description) |
| status | string | No | Task lifecycle status (VNG status) |
| priority | string | No | Task priority level |
| deadline | string | No | Task deadline date and time |
| assigneeUserId | string | No | Nextcloud user UID of the assigned handler (VNG toegewezenAanMedewerker) |
| assigneeGroupId | string | No | Nextcloud group ID for team/department assignment |
| clientId | string | No | UUID reference to the associated client |
| requestId | string | No | UUID reference to the associated request |
| contactMomentSummary | string | No | Summary text from the originating contact moment |
| callbackPhoneNumber | string | No | Override phone number for callback (may differ from client's primary phone) |
| preferredTimeSlot | string | No | Citizen's preferred callback time window (e.g., 'Dinsdag 14:00 - 16:00') |
| createdBy | string | No | Nextcloud user UID of the agent who created this task |
| completedAt | string | No | Timestamp when the task was completed |
| resultText | string | No | Completion summary text |
| attempts | array | No | Callback attempt log — each entry has timestamp, result, and notes |

---
