<?php

declare(strict_types=1);

return [
    'routes' => [
        ['name' => 'settings#index', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'settings#create', 'url' => '/api/settings', 'verb' => 'POST'],
        // Canonical ADR-066 write verb (OpenRegister AppHost dialect). POST above
        // stays as the legacy alias for the existing frontend callers. Declared
        // here in the settings block, BEFORE the SPA/wildcard catch-alls (ADR-016).
        ['name' => 'settings#update', 'url' => '/api/settings', 'verb' => 'PUT'],
        ['name' => 'settings#reimport', 'url' => '/api/settings/reimport', 'verb' => 'POST'],
        // First-time setup wizard (ADR-042)
        ['name' => 'setup#status',     'url' => '/api/setup/status',            'verb' => 'GET'],
        ['name' => 'setup#saveConfig', 'url' => '/api/setup/config',            'verb' => 'POST'],
        ['name' => 'setup#runAction',  'url' => '/api/setup/action/{actionId}', 'verb' => 'POST'],

        // User settings
        ['name' => 'settings#getUserSettings', 'url' => '/api/settings/user', 'verb' => 'GET'],
        ['name' => 'settings#updateUserSettings', 'url' => '/api/settings/user', 'verb' => 'PUT'],

        // Admin — Shillinq WIP manual re-dispatch (pipelinq-time-to-shillinq-wip / REQ-WIP-003).
        ['name' => 'timeEntryWip#retry', 'url' => '/api/time-entries/{uuid}/wip-retry', 'verb' => 'POST'],

        // Admin — Shillinq AP voucher manual re-dispatch (pipelinq-expense-to-shillinq-ap / REQ-AP-003 Scenario 11).
        ['name' => 'shillinqAp#retry', 'url' => '/api/expenses/{id}/shillinq-ap/retry', 'verb' => 'POST'],

        // Generic per-user preferences (used by shared nextcloud-vue widgets, e.g. CnSupportDialog)
        ['name' => 'preferences#getPreference', 'url' => '/api/preferences/{key}', 'verb' => 'GET'],
        ['name' => 'preferences#setPreference', 'url' => '/api/preferences/{key}', 'verb' => 'PUT'],

        // Lead sources (camelCase slug matches LeadSourceController class name)
        ['name' => 'leadSource#index', 'url' => '/api/settings/lead-sources', 'verb' => 'GET'],
        ['name' => 'leadSource#create', 'url' => '/api/settings/lead-sources', 'verb' => 'POST'],
        ['name' => 'leadSource#update', 'url' => '/api/settings/lead-sources/{id}', 'verb' => 'PUT'],
        ['name' => 'leadSource#destroy', 'url' => '/api/settings/lead-sources/{id}', 'verb' => 'DELETE'],

        // Contacts sync (camelCase slug matches ContactSyncController class name)
        ['name' => 'contactSync#search', 'url' => '/api/contacts-sync/search', 'verb' => 'GET'],
        ['name' => 'contactSync#import', 'url' => '/api/contacts-sync/import', 'verb' => 'POST'],
        ['name' => 'contactSync#writeBack', 'url' => '/api/contacts-sync/write-back', 'verb' => 'POST'],
        // Contact-FIRST create — provisions the NC contact and saves the object
        // with the required contactsUid (client-contact unification).
        ['name' => 'contactSync#create', 'url' => '/api/contacts-sync/create', 'verb' => 'POST'],

        // Email matching (leaf-first email-calendar-sync) — per-user settings + trigger + status.
        // The matching job links Nextcloud Mail messages to CRM entities via the OR `email` leaf.
        ['name' => 'emailSync#getSettings', 'url' => '/api/sync/email/settings', 'verb' => 'GET'],
        ['name' => 'emailSync#saveSettings', 'url' => '/api/sync/email/settings', 'verb' => 'POST'],
        ['name' => 'emailSync#trigger', 'url' => '/api/sync/email/trigger', 'verb' => 'POST'],
        ['name' => 'emailSync#getStatus', 'url' => '/api/sync/email/status', 'verb' => 'GET'],

        // Entity notes
        ['name' => 'notes#list', 'url' => '/api/notes/{objectType}/{objectId}', 'verb' => 'GET'],
        ['name' => 'notes#create', 'url' => '/api/notes/{objectType}/{objectId}', 'verb' => 'POST'],
        ['name' => 'notes#deleteAll', 'url' => '/api/notes/{objectType}/{objectId}', 'verb' => 'DELETE'],
        ['name' => 'notes#deleteSingle', 'url' => '/api/notes/single/{noteId}', 'verb' => 'DELETE'],

        // Entity activity feed — per-entity contactmoment + note REST aggregation
        // (entity-notes change). camelCase slug matches EntityActivityController
        // class name; placed BEFORE any wildcard {slug} routes (ADR-003-backend).
        ['name' => 'entityActivity#index', 'url' => '/api/activity/{entityType}/{entityId}', 'verb' => 'GET'],

        // Request channels (camelCase slug matches RequestChannelController class name)
        ['name' => 'requestChannel#index', 'url' => '/api/settings/request-channels', 'verb' => 'GET'],
        ['name' => 'requestChannel#create', 'url' => '/api/settings/request-channels', 'verb' => 'POST'],
        ['name' => 'requestChannel#update', 'url' => '/api/settings/request-channels/{id}', 'verb' => 'PUT'],
        ['name' => 'requestChannel#destroy', 'url' => '/api/settings/request-channels/{id}', 'verb' => 'DELETE'],

        // Prospect discovery
        ['name' => 'prospect#index', 'url' => '/api/prospects', 'verb' => 'GET'],

        // Prospect settings (admin only; camelCase slug matches ProspectSettingsController class name)
        ['name' => 'prospectSettings#index', 'url' => '/api/prospect-settings', 'verb' => 'GET'],
        ['name' => 'prospectSettings#update', 'url' => '/api/prospect-settings', 'verb' => 'PUT'],

        // Intake forms migrated to the OpenRegister forms leaf (NC Forms app).
        // See openspec/changes/migrate-forms-to-forms-leaf — public submission
        // uses the Forms app's own public links; in-app authoring/management
        // routes are retired.
        // Rapportage / reporting — specific routes before wildcard catch-all.
        // Dashboard analytics & Navi (openspec/changes/dashboard).
        ['name' => 'analytics#overview', 'url' => '/api/analytics/overview', 'verb' => 'GET'],
        ['name' => 'analytics#trends',   'url' => '/api/analytics/trends',   'verb' => 'GET'],
        ['name' => 'analytics#funnels',  'url' => '/api/analytics/funnels',  'verb' => 'GET'],
        // Commercial dashboard KPI overview (openspec/changes/commercial-dashboard).
        ['name' => 'analytics#commercial', 'url' => '/api/analytics/commercial', 'verb' => 'GET'],
        // My-work worklist — canonical server-side union of the current user's
        // leads + requests (replaces the MyWorkWidget/MyWork client-side union).
        ['name' => 'worklist#mine', 'url' => '/api/worklist/mine', 'verb' => 'GET'],
        ['name' => 'navi#query',         'url' => '/api/navi/query',         'verb' => 'POST'],
        // SLA engine — attainment dashboard endpoint (sla-engine-and-escalation / REQ-006).
        ['name' => 'slaAttainment#attainment', 'url' => '/api/sla/attainment', 'verb' => 'GET'],
        // SLA engine — admin-gated policy CRUD with justification enforcement (REQ-009).
        ['name' => 'slaPolicy#create', 'url' => '/api/sla/policies',      'verb' => 'POST'],
        ['name' => 'slaPolicy#update', 'url' => '/api/sla/policies/{id}', 'verb' => 'PUT'],
        ['name' => 'reporting#getKpis',     'url' => '/api/rapportage/kpis',     'verb' => 'GET'],
        ['name' => 'reporting#getChannels', 'url' => '/api/rapportage/channels', 'verb' => 'GET'],
        ['name' => 'reporting#getAgents',   'url' => '/api/rapportage/agents',   'verb' => 'GET'],
        ['name' => 'reporting#getSla',      'url' => '/api/rapportage/sla',      'verb' => 'GET'],
        ['name' => 'reporting#updateSla',   'url' => '/api/rapportage/sla',      'verb' => 'PUT'],
        ['name' => 'reporting#exportCsv',   'url' => '/api/rapportage/export',   'verb' => 'GET'],
        // Lead-management analytics endpoint (REQ-LM-006). Non-admin accessible.
        ['name' => 'rapportage#getPipelineStats', 'url' => '/api/rapportage/pipeline-stats', 'verb' => 'GET'],
        // Customer 360 consolidated summary (klantbeeld-360-activation) — cross-ticketType/status
        // aggregation the declarative layer can't express; per-object read guard on the client in the body.
        ['name' => 'customer360#summary', 'url' => '/api/customer-360/summary', 'verb' => 'GET'],
        // Surveys migrated to the OpenRegister forms leaf (NC Forms app) —
        // see openspec/changes/migrate-forms-to-forms-leaf.

        // Contactmomenten (permission-checked delete)
        ['name' => 'contactmoment#destroy', 'url' => '/api/contactmomenten/{id}', 'verb' => 'DELETE'],

        // CTI screen-pop / click-to-dial adapter endpoints (cti-screenpop-adapter).
        // Routes are listed BEFORE the SPA / wildcard catch-alls (ADR-016).
        ['name' => 'cti#webhook',          'url' => '/api/cti/webhook/{platform}',         'verb' => 'POST'],
        ['name' => 'cti#screenPop',        'url' => '/api/cti/screen-pop',                 'verb' => 'POST'],
        ['name' => 'cti#clickToDial',      'url' => '/api/cti/click-to-dial',              'verb' => 'POST'],
        ['name' => 'cti#disposition',      'url' => '/api/cti/contactmoment/{id}/disposition', 'verb' => 'POST'],
        ['name' => 'cti#attachRecording',  'url' => '/api/cti/contactmoment/{id}/recording',   'verb' => 'POST'],
        ['name' => 'cti#getConfig',        'url' => '/api/cti/config',                     'verb' => 'GET'],
        ['name' => 'cti#updateConfig',     'url' => '/api/cti/config',                     'verb' => 'PUT'],
        ['name' => 'cti#testConnection',   'url' => '/api/cti/test-connection',            'verb' => 'GET'],
        ['name' => 'cti#eventLog',         'url' => '/api/cti/event-log',                  'verb' => 'GET'],

        // Callback management endpoints
        ['name' => 'callback#attempt', 'url' => '/api/callbacks/{id}/attempts', 'verb' => 'POST'],
        ['name' => 'callback#claim', 'url' => '/api/callbacks/{id}/claim', 'verb' => 'POST'],
        ['name' => 'callback#complete', 'url' => '/api/callbacks/{id}/complete', 'verb' => 'POST'],
        ['name' => 'callback#reassign', 'url' => '/api/callbacks/{id}/reassign', 'verb' => 'POST'],

        // Prometheus metrics endpoint.
        ['name' => 'metrics#index', 'url' => '/api/metrics', 'verb' => 'GET'],
        // Health check endpoint.
        ['name' => 'health#index', 'url' => '/api/health', 'verb' => 'GET'],

        // Schedules API — pending MUST appear before {id} so the slug does not catch "pending".
        ['name' => 'schedules#index',   'url' => '/api/schedules',         'verb' => 'GET'],
        ['name' => 'schedules#create',  'url' => '/api/schedules',         'verb' => 'POST'],
        ['name' => 'schedules#pending', 'url' => '/api/schedules/pending', 'verb' => 'GET'],
        ['name' => 'schedules#show',    'url' => '/api/schedules/{id}',    'verb' => 'GET'],
        ['name' => 'schedules#update',  'url' => '/api/schedules/{id}',    'verb' => 'PUT'],
        ['name' => 'schedules#destroy', 'url' => '/api/schedules/{id}',    'verb' => 'DELETE'],

        // Activity timeline and worklog endpoints (camelCase slug matches ActivityTimelineController class name)
        ['name' => 'activityTimeline#getTimeline',  'url' => '/api/timeline', 'verb' => 'GET'],
        ['name' => 'activityTimeline#getWorklog',   'url' => '/api/worklog',  'verb' => 'GET'],
        ['name' => 'activityTimeline#createWorklog','url' => '/api/worklog',  'verb' => 'POST'],

        // Skill-based routing suggestions — must precede SPA catch-all.
        ['name' => 'routing#getSuggestions', 'url' => '/api/routing/suggestions', 'verb' => 'GET'],

        // POS transaction lifecycle (camelCase slug matches PosTransactionController class name).
        // CRUD is handled by OpenRegister's generic object API; these are the lifecycle actions.
        // The per-rate BTW compliance report must precede the {id} wildcard routes.
        ['name' => 'posTransaction#taxReport', 'url' => '/api/pos-transactions/tax-report', 'verb' => 'GET'],
        ['name' => 'posTransaction#confirm', 'url' => '/api/pos-transactions/{id}/confirm', 'verb' => 'POST'],
        ['name' => 'posTransaction#settle',  'url' => '/api/pos-transactions/{id}/settle',  'verb' => 'POST'],
        ['name' => 'posTransaction#refund',  'url' => '/api/pos-transactions/{id}/refund',  'verb' => 'POST'],
        ['name' => 'posTransaction#park',    'url' => '/api/pos-transactions/{id}/park',    'verb' => 'POST'],
        ['name' => 'posTransaction#resume',  'url' => '/api/pos-transactions/{id}/resume',  'verb' => 'POST'],

        // POS customer-link surface (search, attach, detach, history) — pos-customer-link.
        // Static /search route precedes the wildcard /{id} routes per Symfony ordering.
        ['name' => 'posCustomer#search',  'url' => '/api/pos-customers/search',          'verb' => 'GET'],
        ['name' => 'posCustomer#history', 'url' => '/api/pos-customers/{id}/history',    'verb' => 'GET'],
        ['name' => 'posCustomer#attach',  'url' => '/api/pos-transactions/{id}/customer', 'verb' => 'POST'],
        ['name' => 'posCustomer#detach',  'url' => '/api/pos-transactions/{id}/customer', 'verb' => 'DELETE'],

        // POS customer-link admin settings (admin-only via #[AuthorizedAdminSetting]).
        ['name' => 'posCustomerSettings#index',  'url' => '/api/admin/pos-customer-settings', 'verb' => 'GET'],
        ['name' => 'posCustomerSettings#update', 'url' => '/api/admin/pos-customer-settings', 'verb' => 'POST'],

        // POS product catalogue resolution (barcode lookup + server-authoritative price).
        ['name' => 'productCatalog#lookupBarcode', 'url' => '/api/products/barcode-lookup', 'verb' => 'POST'],
        ['name' => 'productCatalog#resolvePrice',  'url' => '/api/products/resolve-price',  'verb' => 'POST'],

        // POS receipt operations (camelCase slug matches PosReceiptController class name).
        // receiptTemplate / receiptPrintLog CRUD is handled by OpenRegister's generic
        // object API; these are the render/email/thermal-print actions on a transaction.
        ['name' => 'posReceipt#preview', 'url' => '/api/pos-transactions/{id}/receipt/preview', 'verb' => 'GET'],
        ['name' => 'posReceipt#email',   'url' => '/api/pos-transactions/{id}/receipt/email',   'verb' => 'POST'],
        ['name' => 'posReceipt#print',   'url' => '/api/pos-transactions/{id}/receipt/print',   'verb' => 'POST'],

        // POS refund lifecycle (camelCase slug matches PosRefundController class name).
        // posRefund / posRefundLine / refundReason CRUD is handled by OpenRegister's generic
        // object API; these are the manager-gated confirm/reject lifecycle actions.
        ['name' => 'posRefund#confirm', 'url' => '/api/pos-refunds/{id}/confirm', 'verb' => 'POST'],
        ['name' => 'posRefund#reject',  'url' => '/api/pos-refunds/{id}/reject',  'verb' => 'POST'],

        // POS cash-drawer lifecycle (camelCase slug matches CashShiftController class name).
        // cashShift / cashDrop / cashCount / cashDiff CRUD reads are handled by OpenRegister's
        // generic object API; these are the server-authoritative lifecycle actions. The static
        // open route precedes every {id} wildcard route below it (ADR-016).
        ['name' => 'cashShift#open',    'url' => '/api/pos-shifts',                   'verb' => 'POST'],
        ['name' => 'cashShift#drop',    'url' => '/api/pos-shifts/{id}/drop',         'verb' => 'POST'],
        ['name' => 'cashShift#count',   'url' => '/api/pos-shifts/{id}/count',        'verb' => 'POST'],
        ['name' => 'cashShift#approve', 'url' => '/api/pos-shifts/{id}/diff/approve', 'verb' => 'POST'],
        ['name' => 'cashShift#reject',  'url' => '/api/pos-shifts/{id}/diff/reject',  'verb' => 'POST'],

        // POS staff + role permissions (pos-staff-pin-permissions). Static auth
        // route precedes the {id} wildcard routes for the same resource.
        ['name' => 'posStaff#authenticate', 'url' => '/api/pos/staff/auth', 'verb' => 'POST'],

        ['name' => 'posRole#index',   'url' => '/api/pos/roles',         'verb' => 'GET'],
        ['name' => 'posRole#create',  'url' => '/api/pos/roles',         'verb' => 'POST'],
        ['name' => 'posRole#show',    'url' => '/api/pos/roles/{id}',    'verb' => 'GET'],
        ['name' => 'posRole#update',  'url' => '/api/pos/roles/{id}',    'verb' => 'PUT'],
        ['name' => 'posRole#destroy', 'url' => '/api/pos/roles/{id}',    'verb' => 'DELETE'],

        ['name' => 'posStaff#index',   'url' => '/api/pos/staff',        'verb' => 'GET'],
        ['name' => 'posStaff#create',  'url' => '/api/pos/staff',        'verb' => 'POST'],
        ['name' => 'posStaff#show',    'url' => '/api/pos/staff/{id}',   'verb' => 'GET'],
        ['name' => 'posStaff#update',  'url' => '/api/pos/staff/{id}',   'verb' => 'PUT'],
        ['name' => 'posStaff#destroy', 'url' => '/api/pos/staff/{id}',   'verb' => 'DELETE'],

        // Per-staff sales report (pos-staff-pin-permissions REQ-PSP-008).
        ['name' => 'posStaffReport#staffSales', 'url' => '/api/pos/reports/staff-sales', 'verb' => 'GET'],

        // POS payment provider adapter (pos-payment-provider-adapter).
        // - /api/pos-payments/{id}/* are the per-transaction payment actions (cashier-facing).
        // - /api/payment-providers* are the admin-only credential + connection management endpoints.
        // - /api/pos-payment-webhook/{provider} is the public, signature-validated webhook
        //   inbound from Mollie / CCV / Adyen / Stripe (REQ-PAY-006).
        // Specific routes precede any wildcard {path} catch-all (ADR-016).
        ['name' => 'posPayment#initiate', 'url' => '/api/pos-payments/{id}/initiate', 'verb' => 'POST'],
        ['name' => 'posPayment#capture',  'url' => '/api/pos-payments/{id}/capture',  'verb' => 'POST'],
        ['name' => 'posPayment#refund',   'url' => '/api/pos-payments/{id}/refund',   'verb' => 'POST'],
        ['name' => 'posPayment#index',    'url' => '/api/payment-providers',          'verb' => 'GET'],
        ['name' => 'posPayment#show',     'url' => '/api/payment-providers/{name}',   'verb' => 'GET'],
        ['name' => 'posPayment#update',   'url' => '/api/payment-providers/{name}',   'verb' => 'PUT'],
        ['name' => 'posPayment#test',     'url' => '/api/payment-providers/{name}/test', 'verb' => 'POST'],
        ['name' => 'posPayment#webhook',  'url' => '/api/pos-payment-webhook/{provider}', 'verb' => 'POST'],

        // POS end-of-day journal raise (pipelinq-bookkeeping-to-shillinq / REQ-PBTS-001).
        // Manager-gated raise / re-raise of a posZReport's journal entry in shillinq
        // through the ADR-019 integration registry. The GL chart + journal are owned
        // by shillinq; pipelinq only sends the Z-report business facts. The retired
        // /api/admin/pos-bookkeeping/config GL-mapping admin endpoint is removed.
        ['name' => 'posBookkeeping#post',         'url' => '/api/pos-bookkeeping/post',         'verb' => 'POST'],

        // POS split-tender (pos-split-tender). Admin tender-type CRUD +
        // cashier-facing per-transaction tender CRUD + validate-only helper
        // for the settle preflight. Specific routes precede the {id}/{tenderId}
        // wildcards (ADR-016). Tender-type CRUD is admin-only via
        // #[AuthorizedAdminSetting]; transaction-scoped routes are #[NoAdminRequired].
        ['name' => 'posTender#indexTypes',  'url' => '/api/pos/tender-types',        'verb' => 'GET'],
        ['name' => 'posTender#createType',  'url' => '/api/pos/tender-types',        'verb' => 'POST'],
        ['name' => 'posTender#showType',    'url' => '/api/pos/tender-types/{id}',   'verb' => 'GET'],
        ['name' => 'posTender#updateType',  'url' => '/api/pos/tender-types/{id}',   'verb' => 'PUT'],
        ['name' => 'posTender#destroyType', 'url' => '/api/pos/tender-types/{id}',   'verb' => 'DELETE'],
        ['name' => 'posTender#summary',     'url' => '/api/pos-transactions/{transactionId}/tenders/summary', 'verb' => 'GET'],
        ['name' => 'posTender#indexTenders','url' => '/api/pos-transactions/{transactionId}/tenders',         'verb' => 'GET'],
        ['name' => 'posTender#addTender',   'url' => '/api/pos-transactions/{transactionId}/tenders',         'verb' => 'POST'],
        ['name' => 'posTender#removeTender','url' => '/api/pos-transactions/{transactionId}/tenders/{tenderId}', 'verb' => 'DELETE'],

        // POS Kassakoppeling-compliant Audit Log (pos-kassakoppeling-audit).
        // Append-only signed audit entries with per-register hash chain + admin-gated
        // Belastingdienst export. The static `/export` route precedes the `{id}`
        // wildcard so the Symfony router never mistakes "export" for an id (ADR-016).
        // camelCase slug matches KassakoppelingAuditController class name.
        ['name' => 'kassakoppelingAudit#index',  'url' => '/api/kassakoppeling/audit',           'verb' => 'GET'],
        ['name' => 'kassakoppelingAudit#create', 'url' => '/api/kassakoppeling/audit',           'verb' => 'POST'],
        ['name' => 'kassakoppelingAudit#export', 'url' => '/api/kassakoppeling/audit/export',    'verb' => 'GET'],
        ['name' => 'kassakoppelingAudit#verify', 'url' => '/api/kassakoppeling/audit/{id}/verify', 'verb' => 'POST'],
        ['name' => 'kassakoppelingAudit#show',   'url' => '/api/kassakoppeling/audit/{id}',      'verb' => 'GET'],

        // ---------------------------------------------------------------------
        // Customer portal (separate auth domain — ADR-005). All /portal/api/*
        // endpoints are #[PublicPage]; the portal session bearer token (not a
        // Nextcloud user) authenticates each request inside the controller via
        // PortalRequestGuard. Static routes precede {id} wildcards (ADR-016).
        // ---------------------------------------------------------------------

        // Public (pre-login) tenant branding.
        ['name' => 'portalTenant#config', 'url' => '/portal/api/tenant-config', 'verb' => 'GET'],

        // Auth (unauthenticated entry / token-authenticated session actions).
        ['name' => 'portalAuth#login',                'url' => '/portal/api/auth/login',                  'verb' => 'POST'],
        ['name' => 'portalAuth#logout',               'url' => '/portal/api/auth/logout',                 'verb' => 'POST'],
        ['name' => 'portalAuth#extendSession',         'url' => '/portal/api/auth/extend-session',         'verb' => 'POST'],
        ['name' => 'portalAuth#passwordResetRequest',  'url' => '/portal/api/auth/password-reset-request', 'verb' => 'POST'],
        ['name' => 'portalAuth#passwordReset',         'url' => '/portal/api/auth/password-reset',         'verb' => 'POST'],
        ['name' => 'portalAuth#mfaEnroll',             'url' => '/portal/api/auth/mfa-enroll',             'verb' => 'POST'],
        ['name' => 'portalAuth#mfaVerify',             'url' => '/portal/api/auth/mfa-verify',             'verb' => 'POST'],

        // Profile + account lifecycle.
        ['name' => 'portalAccount#profile',        'url' => '/portal/api/accounts/profile',       'verb' => 'GET'],
        ['name' => 'portalAccount#updateProfile',  'url' => '/portal/api/accounts/profile',       'verb' => 'PUT'],
        ['name' => 'portalAccount#verifyEmail',    'url' => '/portal/api/accounts/verify-email',  'verb' => 'POST'],
        ['name' => 'portalAccount#requestExport',  'url' => '/portal/api/exports',                'verb' => 'POST'],
        ['name' => 'portalAccount#requestClose',   'url' => '/portal/api/accounts/close',         'verb' => 'POST'],
        ['name' => 'portalAccount#confirmClose',   'url' => '/portal/api/accounts/close/confirm', 'verb' => 'POST'],

        // Own audit trail.
        ['name' => 'portalAudit#index', 'url' => '/portal/api/audit-events', 'verb' => 'GET'],

        // Delegations (B2B). Static before {id}.
        ['name' => 'portalDelegation#index',   'url' => '/portal/api/delegations',      'verb' => 'GET'],
        ['name' => 'portalDelegation#create',  'url' => '/portal/api/delegations',      'verb' => 'POST'],
        ['name' => 'portalDelegation#destroy', 'url' => '/portal/api/delegations/{id}', 'verb' => 'DELETE'],

        // Documents (signed-URL proxy). Static sign route before the token route.
        ['name' => 'portalDocument#sign',     'url' => '/portal/api/documents/sign',              'verb' => 'POST'],
        ['name' => 'portalDocument#download', 'url' => '/portal/api/documents/{token}/download',  'verb' => 'GET'],

        // Requests. Static create/list before {id} detail/reply.
        ['name' => 'portalRequest#index',  'url' => '/portal/api/requests',           'verb' => 'GET'],
        ['name' => 'portalRequest#create', 'url' => '/portal/api/requests',           'verb' => 'POST'],
        ['name' => 'portalRequest#show',   'url' => '/portal/api/requests/{id}',       'verb' => 'GET'],
        ['name' => 'portalRequest#reply',  'url' => '/portal/api/requests/{id}/reply', 'verb' => 'POST'],

        // Invoices / contracts / orders. Static list before {id} detail.
        ['name' => 'portalData#invoices',  'url' => '/portal/api/invoices',       'verb' => 'GET'],
        ['name' => 'portalData#invoice',   'url' => '/portal/api/invoices/{id}',  'verb' => 'GET'],
        ['name' => 'portalData#contracts', 'url' => '/portal/api/contracts',      'verb' => 'GET'],
        ['name' => 'portalData#contract',  'url' => '/portal/api/contracts/{id}', 'verb' => 'GET'],
        ['name' => 'portalData#orders',    'url' => '/portal/api/orders',         'verb' => 'GET'],
        ['name' => 'portalData#order',     'url' => '/portal/api/orders/{id}',    'verb' => 'GET'],

        // Forecast roll-up API (snapshot export + manager overrides). Static
        // paths precede the {id} wildcard (ADR-016); all are #[NoAdminRequired]
        // with per-action scope enforced in ForecastAccessPolicy (ADR-005).
        ['name' => 'forecast#snapshots',      'url' => '/api/forecast/snapshots',     'verb' => 'GET'],
        ['name' => 'forecast#createOverride', 'url' => '/api/forecast/overrides',     'verb' => 'POST'],
        ['name' => 'forecast#deleteOverride', 'url' => '/api/forecast/overrides/{id}', 'verb' => 'DELETE'],

        // Forecast admin configuration (Nextcloud admin only; #[AuthorizedAdminSetting]).
        ['name' => 'forecastSettings#index',  'url' => '/api/settings/forecast', 'verb' => 'GET'],
        ['name' => 'forecastSettings#update', 'url' => '/api/settings/forecast', 'verb' => 'PUT'],

        // Admin / DPO (Nextcloud admin only; no #[PublicPage] — admin-default).
        ['name' => 'portalAdmin#saveConfig',   'url' => '/portal/api/admin/tenant-config', 'verb' => 'POST'],
        ['name' => 'portalAdmin#accounts',     'url' => '/portal/api/admin/accounts',      'verb' => 'GET'],
        ['name' => 'portalAdmin#auditEvents',  'url' => '/portal/api/admin/audit-events',  'verb' => 'GET'],

        // Appointment booking portal (anonymous customer self-booking; ADR-005 /
        // ADR-016). Lives under /portal/api/booking/* so the portalPage SPA
        // catch-all (excludes /portal/api/*) does not eat the GETs. All endpoints
        // carry @PublicPage in PortalController.php — reschedule/cancel require
        // an HMAC-SHA256 signed token (member 05 of the appointment-booking chain).
        ['name' => 'portal#services',     'url' => '/portal/api/booking/services',            'verb' => 'GET'],
        ['name' => 'portal#availability', 'url' => '/portal/api/booking/availability',        'verb' => 'GET'],
        ['name' => 'portal#book',         'url' => '/portal/api/booking/book',                'verb' => 'POST'],
        ['name' => 'portal#reschedule',   'url' => '/portal/api/booking/reschedule',          'verb' => 'POST'],
        ['name' => 'portal#cancel',       'url' => '/portal/api/booking/cancel',              'verb' => 'POST'],
        ['name' => 'portal#getBooking',   'url' => '/portal/api/booking/{bookingId}',         'verb' => 'GET'],

        // Public portal SPA shell. Registered AFTER all /portal/api/* routes so
        // the API wins, and BEFORE the main-app catch-all so /portal serves the
        // isolated portal bundle rather than the Nextcloud-authenticated app.
        ['name' => 'portalPage#index', 'url' => '/portal', 'verb' => 'GET'],
        ['name' => 'portalPage#subpath', 'url' => '/portal/{path}', 'verb' => 'GET', 'requirements' => ['path' => '^(?!api/).*'], 'defaults' => ['path' => '']],

        // BI export + data-warehouse sink (camelCase slugs match the controller class names).
        // exportDestination / exportJob / exportRun / exportSchemaSnapshot object CRUD is handled
        // by OpenRegister's generic object API; these are the validation, test, scheduling,
        // history-filter and retry actions the generic API cannot express (REQ-BIE-001/002/003/011).
        // Static collection + action routes are declared before the {id} member routes so the
        // router never mistakes "test-run" or "destinations" for an {id} (ADR-016).
        ['name' => 'exportJob#listDestinations',  'url' => '/api/export/destinations',          'verb' => 'GET'],
        ['name' => 'exportJob#createDestination', 'url' => '/api/export/destinations',          'verb' => 'POST'],
        ['name' => 'exportJob#updateDestination', 'url' => '/api/export/destinations/{id}',      'verb' => 'PUT'],
        ['name' => 'exportJob#deleteDestination', 'url' => '/api/export/destinations/{id}',      'verb' => 'DELETE'],
        ['name' => 'exportJob#testDestination',   'url' => '/api/export/destinations/{id}/test', 'verb' => 'POST'],

        ['name' => 'exportJob#listJobs',   'url' => '/api/export/jobs',              'verb' => 'GET'],
        ['name' => 'exportJob#createJob',  'url' => '/api/export/jobs',              'verb' => 'POST'],
        ['name' => 'exportJob#showJob',    'url' => '/api/export/jobs/{id}',         'verb' => 'GET'],
        ['name' => 'exportJob#updateJob',  'url' => '/api/export/jobs/{id}',         'verb' => 'PUT'],
        ['name' => 'exportJob#deleteJob',  'url' => '/api/export/jobs/{id}',         'verb' => 'DELETE'],
        ['name' => 'exportJob#testRun',    'url' => '/api/export/jobs/{id}/test-run', 'verb' => 'POST'],
        ['name' => 'exportJob#enableJob',  'url' => '/api/export/jobs/{id}/enable',   'verb' => 'POST'],
        ['name' => 'exportJob#disableJob', 'url' => '/api/export/jobs/{id}/disable',  'verb' => 'POST'],

        ['name' => 'exportRun#listRuns', 'url' => '/api/export/runs',           'verb' => 'GET'],
        ['name' => 'exportRun#showRun',  'url' => '/api/export/runs/{id}',       'verb' => 'GET'],
        ['name' => 'exportRun#retryRun', 'url' => '/api/export/runs/{id}/retry', 'verb' => 'POST'],

        // Appointment booking — admin lifecycle actions (member 11 of 12).
        // All endpoints require an authenticated user (#[NoAdminRequired]);
        // BookingService runs the per-status transition + IDOR guards.
        ['name' => 'bookingAdmin#reschedule',     'url' => '/api/bookings/{id}/reschedule',     'verb' => 'POST'],
        ['name' => 'bookingAdmin#cancel',         'url' => '/api/bookings/{id}/cancel',         'verb' => 'POST'],
        ['name' => 'bookingAdmin#markCompleted',  'url' => '/api/bookings/{id}/complete',       'verb' => 'POST'],
        ['name' => 'bookingAdmin#markNoShow',     'url' => '/api/bookings/{id}/no-show',        'verb' => 'POST'],
        ['name' => 'bookingAdmin#sendReminder',   'url' => '/api/bookings/{id}/send-reminder',  'verb' => 'POST'],
        ['name' => 'bookingAdmin#confirmDeposit', 'url' => '/api/bookings/{id}/confirm-deposit', 'verb' => 'POST'],

        // Loyalty program (loyalty-program — REQ-LOY-001..010).
        ['name' => 'loyalty#getAccount',         'url' => '/api/loyalty/accounts/{accountId}',          'verb' => 'GET'],
        ['name' => 'loyalty#getAccountHistory',  'url' => '/api/loyalty/accounts/{accountId}/history',  'verb' => 'GET'],
        ['name' => 'loyalty#getRedemptionOptions',    'url' => '/api/loyalty/redemption/options/{programmeId}/{accountId}', 'verb' => 'GET'],
        ['name' => 'loyalty#initiateRedemption',      'url' => '/api/loyalty/redemption/initiate/{accountId}/{optionId}',   'verb' => 'POST'],
        ['name' => 'loyalty#lookupRedemptionCode',    'url' => '/api/loyalty/redemption/{code}/validate',                   'verb' => 'POST'],
        ['name' => 'loyalty#useRedemptionCode',       'url' => '/api/loyalty/redemption/{code}/use',                        'verb' => 'POST'],
        ['name' => 'loyalty#issueGiftCard',     'url' => '/api/loyalty/gift-card/issue',                 'verb' => 'POST'],
        ['name' => 'loyalty#lookupGiftCard',    'url' => '/api/loyalty/gift-card/validate',              'verb' => 'POST'],
        ['name' => 'loyalty#redeemGiftCard',    'url' => '/api/loyalty/gift-card/redeem',                'verb' => 'POST'],
        ['name' => 'loyalty#activateGiftCard',  'url' => '/api/loyalty/gift-card/activate/{giftCardId}', 'verb' => 'POST'],
        ['name' => 'loyalty#activateProgramme', 'url' => '/api/loyalty/programme/{programmeId}/activate', 'verb' => 'POST'],
        ['name' => 'loyaltyReporting#kpis',               'url' => '/api/loyalty/reporting/{programmeId}/kpis',          'verb' => 'GET'],
        ['name' => 'loyaltyReporting#liability',          'url' => '/api/loyalty/reporting/{programmeId}/liability',     'verb' => 'GET'],
        ['name' => 'loyaltyReporting#tierDistribution',   'url' => '/api/loyalty/reporting/{programmeId}/tiers',         'verb' => 'GET'],
        ['name' => 'loyaltyReporting#expiryForecast',     'url' => '/api/loyalty/reporting/{programmeId}/expiry-forecast', 'verb' => 'GET'],
        // loyaltyGdpr#* routes retired (ADR-047 Phase-3): loyalty GDPR export/erase
        // is subsumed by OpenRegister's cross-register DSAR erase; LoyaltyGdprController removed.

        // CRM workflow automation has been migrated to the OpenRegister
        // flow leaf (NC Flow / n8n) per migrate-automation-to-flow-leaf;
        // no automation / webhook / dmn endpoints remain in pipelinq.

        // Marketing blast provider webhooks (signature-verified, PublicPage)
        // marketing-segmentation-and-blast-05-jobs-and-webhooks.
        // camelCase slug matches BlastWebhookController class name.
        ['name' => 'blastWebhook#sendgrid', 'url' => '/api/blast-webhooks/sendgrid', 'verb' => 'POST'],
        ['name' => 'blastWebhook#ses',      'url' => '/api/blast-webhooks/ses',      'verb' => 'POST'],
        ['name' => 'blastWebhook#twilio',   'url' => '/api/blast-webhooks/twilio',   'verb' => 'POST'],
        // marketing-mail-transports: four more bulk-provider webhooks,
        // same signature-verified/PublicPage shape as the three above.
        ['name' => 'blastWebhook#brevo',    'url' => '/api/blast-webhooks/brevo',    'verb' => 'POST'],
        ['name' => 'blastWebhook#mailjet',  'url' => '/api/blast-webhooks/mailjet',  'verb' => 'POST'],
        ['name' => 'blastWebhook#mailgun',  'url' => '/api/blast-webhooks/mailgun',  'verb' => 'POST'],
        ['name' => 'blastWebhook#postmark', 'url' => '/api/blast-webhooks/postmark', 'verb' => 'POST'],

        // marketing-mail-transports: deliverability panel's SPF/DKIM/DMARC
        // check (AuthorizedAdminSetting; not CRUD, so it is not on
        // useObjectStore). camelCase slug matches MailTransportController.
        ['name' => 'mailTransport#checkDeliverability', 'url' => '/api/mail-transports/{id}/check-deliverability', 'verb' => 'POST'],

        // First-party marketing-email open/click tracking (HMAC-signed
        // tokens, PublicPage, fail-closed) — marketing-email-open-click-tracking.
        // camelCase slug matches BlastTrackingController class name.
        ['name' => 'blastTracking#open',  'url' => '/api/blast/track/open/{token}',  'verb' => 'GET'],
        ['name' => 'blastTracking#click', 'url' => '/api/blast/track/click/{token}', 'verb' => 'GET'],

        // Mailing lists — the subscriber's four doors (marketing-lists-and-
        // double-opt-in). PublicPage + signed token + throttled per ADR-082;
        // they stay on pipelinq rather than moving to portaliq because an
        // RFC 8058 List-Unsubscribe header names the URL (ADR-108).
        // camelCase slug matches ListPublicController class name.
        // The literal-prefixed paths come FIRST: `/api/lists/confirm/{token}`
        // and `/api/lists/{id}/subscribe` are different shapes, but ordering
        // them by specificity keeps that true if either ever gains a segment.
        ['name' => 'listPublic#confirm',          'url' => '/api/lists/confirm/{token}',     'verb' => 'GET'],
        ['name' => 'listPublic#unsubscribePage',  'url' => '/api/lists/unsubscribe/{token}', 'verb' => 'GET'],
        ['name' => 'listPublic#unsubscribe',      'url' => '/api/lists/unsubscribe/{token}', 'verb' => 'POST'],
        ['name' => 'listPublic#preferences',      'url' => '/api/lists/preferences/{token}', 'verb' => 'GET'],
        ['name' => 'listPublic#savePreferences',  'url' => '/api/lists/preferences/{token}', 'verb' => 'POST'],
        ['name' => 'listPublic#subscribe',        'url' => '/api/lists/{id}/subscribe',      'verb' => 'POST'],

        // Appointment booking — deposit payment webhook (signature-verified, PublicPage)
        // appointment-booking-08-deposit-payment / REQ-APT-010.
        // openconnector hits this URL with the payment outcome.
        ['name' => 'appointmentPaymentWebhook#callback', 'url' => '/api/appointment-payment-webhook', 'verb' => 'POST'],

        // ZGW API bridge — NRC notification inbox (bearer-authenticated, PublicPage)
        // zgw-api-bridge / REQ-ZGW-007. Open Notificaties posts here on every
        // zaak/status/besluit/catalogi event for a subscribed gemeente.
        ['name' => 'zgwNotification#inbox', 'url' => '/api/zgw/notificaties/inbox', 'verb' => 'POST'],

        // WhatsApp / SMS messaging webhooks (signature-verified, PublicPage)
        // whatsapp-sms-channel-adapter / REQ-003. Specific routes precede
        // any wildcard catch-alls (ADR-016).
        ['name' => 'messagingWebhook#whatsapp', 'url' => '/api/messaging-webhooks/whatsapp/{providerId}', 'verb' => 'POST'],
        ['name' => 'messagingWebhook#sms',      'url' => '/api/messaging-webhooks/sms/{providerId}',      'verb' => 'POST'],
        // Outbound agent messaging (outbound-messaging-provider-wiring):
        // server-side send + composer preflight + consent recording +
        // admin-gated zero-cost provider connectivity test.
        ['name' => 'messaging#send',         'url' => '/api/messaging/send',                    'verb' => 'POST'],
        ['name' => 'messaging#preflight',    'url' => '/api/messaging/preflight/{contactId}',   'verb' => 'GET'],
        ['name' => 'messaging#consent',      'url' => '/api/messaging/consent',                 'verb' => 'POST'],
        ['name' => 'messaging#testProvider', 'url' => '/api/messaging/providers/{id}/test',     'verb' => 'POST'],
        // Semantic object handoff emit (ADR-051 / semantic-handoff-emit):
        // request -> ns#Case, active contract -> ns#Invoice. Kind-addressed via
        // OpenRegister's handoff engine; actions hide when no app implements the kind.
        ['name' => 'semanticHandoff#requestAvailability',    'url' => '/api/handoff/request/{id}/availability',       'verb' => 'GET'],
        ['name' => 'semanticHandoff#convertRequestToCase',   'url' => '/api/handoff/request/{id}/convert-to-case',    'verb' => 'POST'],
        ['name' => 'semanticHandoff#contractAvailability',   'url' => '/api/handoff/contract/{id}/availability',      'verb' => 'GET'],
        ['name' => 'semanticHandoff#sendContractToInvoicing','url' => '/api/handoff/contract/{id}/send-to-invoicing', 'verb' => 'POST'],

        // Shillinq time-intake billing handoff — real emit side of the
        // time-approval-workflow delegation (time-billing-handoff-emit).
        // Manager-gated; the deep-link (shillinq_app_url) stays the fallback
        // when unavailable.
        ['name' => 'billingHandoff#availability', 'url' => '/api/billing/handoff/{clientId}/availability', 'verb' => 'GET'],
        ['name' => 'billingHandoff#trigger',      'url' => '/api/billing/handoff/{clientId}',              'verb' => 'POST'],
        // Berichtenbox bridge (burgerportaal-mijnoverheid-bridge).
        // Logius webhooks for read-receipt + inbound replies — HMAC-SHA256
        // signature-verified (REQ-RECEIPT-005 / REQ-INBOUND-006).
        ['name' => 'berichtenboxWebhook#readReceipt',  'url' => '/api/webhook/berichtenbox/read',  'verb' => 'POST'],
        ['name' => 'berichtenboxWebhook#inboundReply', 'url' => '/api/webhook/berichtenbox/reply', 'verb' => 'POST'],
        // Admin ops — retry a failed message + read aggregate stats.
        ['name' => 'berichtenboxAdmin#retry', 'url' => '/api/admin/berichtenbox/message/{id}/retry', 'verb' => 'POST'],
        ['name' => 'berichtenboxAdmin#stats', 'url' => '/api/admin/berichtenbox/stats',              'verb' => 'GET'],
        // Marketing — Segments (marketing-segmentation-and-blast chain member 06).
        // Specific routes precede any wildcard {slug} routes (ADR-016).
        ['name' => 'segment#index',         'url' => '/api/segments',                  'verb' => 'GET'],
        ['name' => 'segment#create',        'url' => '/api/segments',                  'verb' => 'POST'],
        ['name' => 'segment#preview',       'url' => '/api/segments/preview',          'verb' => 'POST'],
        ['name' => 'segment#refreshSize',   'url' => '/api/segments/{id}/size',        'verb' => 'POST'],
        ['name' => 'segment#members',       'url' => '/api/segments/{id}/members',     'verb' => 'GET'],
        ['name' => 'segment#show',          'url' => '/api/segments/{id}',             'verb' => 'GET'],
        ['name' => 'segment#update',        'url' => '/api/segments/{id}',             'verb' => 'PATCH'],

        // Marketing — Mailing lists and subscriptions (marketing-lists-and-
        // double-opt-in). The marketer's side; the subscriber's side is the
        // PublicPage block above. Specific routes precede any wildcard {id}
        // routes (ADR-016).
        ['name' => 'mailingList#index',         'url' => '/api/mailing-lists',                    'verb' => 'GET'],
        ['name' => 'mailingList#create',        'url' => '/api/mailing-lists',                    'verb' => 'POST'],
        ['name' => 'mailingList#subscriptions', 'url' => '/api/mailing-lists/{id}/subscriptions', 'verb' => 'GET'],
        ['name' => 'mailingList#show',          'url' => '/api/mailing-lists/{id}',               'verb' => 'GET'],
        ['name' => 'mailingList#update',        'url' => '/api/mailing-lists/{id}',               'verb' => 'PATCH'],
        ['name' => 'subscription#softOptIn',    'url' => '/api/subscriptions/soft-opt-in',        'verb' => 'POST'],
        ['name' => 'subscription#unsubscribe',  'url' => '/api/subscriptions/unsubscribe',        'verb' => 'POST'],
        ['name' => 'subscription#forContact',      'url' => '/api/contacts/{contactId}/subscriptions',  'verb' => 'GET'],
        ['name' => 'subscription#preferenceLink',  'url' => '/api/contacts/{contactId}/preference-link', 'verb' => 'GET'],

        // Marketing — CampaignTemplates (marketing-segmentation-and-blast chain member 06).
        // Specific routes precede any wildcard {slug} routes (ADR-016).
        ['name' => 'template#index',   'url' => '/api/templates',          'verb' => 'GET'],
        ['name' => 'template#create',  'url' => '/api/templates',          'verb' => 'POST'],
        ['name' => 'template#preview', 'url' => '/api/templates/{id}/preview', 'verb' => 'GET'],
        ['name' => 'template#show',    'url' => '/api/templates/{id}',     'verb' => 'GET'],
        ['name' => 'template#update',  'url' => '/api/templates/{id}',     'verb' => 'PATCH'],

        // Marketing — Articles (marketing-article-hub). Articles are the
        // content hub for both mailings and posts. Literal-suffixed routes
        // (publish, archive, transition, usages) precede the bare {id}
        // routes (ADR-016), matching the Blast block below.
        ['name' => 'article#index',      'url' => '/api/articles',                'verb' => 'GET'],
        ['name' => 'article#create',     'url' => '/api/articles',                'verb' => 'POST'],
        ['name' => 'article#publish',    'url' => '/api/articles/{id}/publish',   'verb' => 'POST'],
        ['name' => 'article#archive',    'url' => '/api/articles/{id}/archive',   'verb' => 'POST'],
        ['name' => 'article#transition', 'url' => '/api/articles/{id}/transition', 'verb' => 'POST'],
        ['name' => 'article#usages',     'url' => '/api/articles/{id}/usages',    'verb' => 'GET'],
        ['name' => 'article#show',       'url' => '/api/articles/{id}',           'verb' => 'GET'],
        ['name' => 'article#update',     'url' => '/api/articles/{id}',           'verb' => 'PATCH'],

        // Marketing — Social publishing (social-publishing, phase 3). Three
        // objects, three controllers. Literal-suffixed routes (connect,
        // attach, revoke, sync, submit, approve, reject, publications, retry,
        // share, confirm-share) precede the bare {id} routes (ADR-016), and
        // the static /api/social-performance is declared before anything that
        // could take it for an id.
        ['name' => 'socialAccount#index',   'url' => '/api/social-accounts',              'verb' => 'GET'],
        ['name' => 'socialAccount#create',  'url' => '/api/social-accounts',              'verb' => 'POST'],
        ['name' => 'socialAccount#connect', 'url' => '/api/social-accounts/{id}/connect', 'verb' => 'POST'],
        ['name' => 'socialAccount#attach',  'url' => '/api/social-accounts/{id}/attach',  'verb' => 'POST'],
        ['name' => 'socialAccount#revoke',  'url' => '/api/social-accounts/{id}/revoke',  'verb' => 'POST'],
        ['name' => 'socialAccount#sync',    'url' => '/api/social-accounts/{id}/sync',    'verb' => 'POST'],
        ['name' => 'socialAccount#show',    'url' => '/api/social-accounts/{id}',         'verb' => 'GET'],

        ['name' => 'socialPost#performance',  'url' => '/api/social-performance',              'verb' => 'GET'],
        ['name' => 'socialPost#index',        'url' => '/api/social-posts',                    'verb' => 'GET'],
        ['name' => 'socialPost#create',       'url' => '/api/social-posts',                    'verb' => 'POST'],
        ['name' => 'socialPost#submit',       'url' => '/api/social-posts/{id}/submit',        'verb' => 'POST'],
        ['name' => 'socialPost#approve',      'url' => '/api/social-posts/{id}/approve',       'verb' => 'POST'],
        ['name' => 'socialPost#reject',       'url' => '/api/social-posts/{id}/reject',        'verb' => 'POST'],
        ['name' => 'socialPost#publications', 'url' => '/api/social-posts/{id}/publications',  'verb' => 'GET'],
        ['name' => 'socialPost#show',         'url' => '/api/social-posts/{id}',               'verb' => 'GET'],
        ['name' => 'socialPost#update',       'url' => '/api/social-posts/{id}',               'verb' => 'PATCH'],

        ['name' => 'socialPost#retry',              'url' => '/api/social-publications/{id}/retry',         'verb' => 'POST'],
        ['name' => 'socialAdvocacy#share',          'url' => '/api/social-publications/{id}/share',         'verb' => 'GET'],
        ['name' => 'socialAdvocacy#confirmShare',   'url' => '/api/social-publications/{id}/confirm-share', 'verb' => 'POST'],

        // Marketing — Search Console (marketing-campaign-attribution): top queries
        // over a window plus the connection status. Static path, no wildcard.
        ['name' => 'searchConsole#index', 'url' => '/api/marketing/search-queries', 'verb' => 'GET'],

        // Marketing — Blasts (marketing-segmentation-and-blast chain member 06).
        // Specific routes precede any wildcard {slug} routes (ADR-016).
        ['name' => 'blast#index',      'url' => '/api/blasts',                     'verb' => 'GET'],
        ['name' => 'blast#create',     'url' => '/api/blasts',                     'verb' => 'POST'],
        ['name' => 'blast#send',       'url' => '/api/blasts/{id}/send',           'verb' => 'POST'],
        ['name' => 'blast#cancel',     'url' => '/api/blasts/{id}/cancel',         'verb' => 'POST'],
        ['name' => 'blast#deliveries', 'url' => '/api/blasts/{id}/deliveries',     'verb' => 'GET'],
        ['name' => 'blast#attribution', 'url' => '/api/blasts/{id}/attribution',   'verb' => 'GET'],
        // Phase 2 of the fleet traffic programme (marketing-campaign-attribution):
        // mailbox numbers plus the site sessions Portaliq attributed to the campaign.
        ['name' => 'blast#performance', 'url' => '/api/blasts/{id}/performance',   'verb' => 'GET'],
        ['name' => 'blast#show',       'url' => '/api/blasts/{id}',                'verb' => 'GET'],
        ['name' => 'blast#update',     'url' => '/api/blasts/{id}',                'verb' => 'PATCH'],

        // BRP / BSN — Haalcentraal Personen integration (bsn-validatie-en-brp-lookup).
        // Specific routes precede any wildcard {slug} routes (ADR-016).
        ['name' => 'brp#lookup',           'url' => '/api/brp/lookup',                    'verb' => 'POST'],
        ['name' => 'brp#revealAddress',    'url' => '/api/brp/contact/{id}/reveal-address', 'verb' => 'POST'],
        ['name' => 'brp#optOutCreate',     'url' => '/api/brp/opt-out',                   'verb' => 'POST'],
        ['name' => 'brp#mutationWebhook',  'url' => '/api/brp/mutations',                 'verb' => 'POST'],
        ['name' => 'brp#monitor',          'url' => '/api/brp/monitor',                   'verb' => 'GET'],
        ['name' => 'brpAdmin#get',                 'url' => '/api/brp/settings',                  'verb' => 'GET'],
        ['name' => 'brpAdmin#save',                'url' => '/api/brp/settings',                  'verb' => 'POST'],
        ['name' => 'brpAdmin#rotateWebhookSecret', 'url' => '/api/brp/settings/webhook-secret',   'verb' => 'POST'],
        // KCC Werkplek — unified agent workspace (kcc-werkplek).
        // Specific routes precede any wildcard {path} catch-all (ADR-016).
        ['name' => 'kccWerkplek#stateAction',           'url' => '/api/kcc-werkplek/state',        'verb' => 'GET'],
        ['name' => 'kccWerkplek#setAvailabilityAction', 'url' => '/api/kcc-werkplek/availability', 'verb' => 'PUT'],

        // xWiki integration proxy (xwiki-integration). Thin GET proxy to the
        // xWiki REST API; all endpoints are #[NoAdminRequired] + #[NoCSRFRequired]
        // and admin-tunable via xwiki_* settings.
        ['name' => 'xWiki#search', 'url' => '/api/xwiki/search',                    'verb' => 'GET'],
        ['name' => 'xWiki#pages',  'url' => '/api/xwiki/pages',                     'verb' => 'GET'],
        ['name' => 'xWiki#page',   'url' => '/api/xwiki/page/{wiki}/{page}',        'verb' => 'GET', 'requirements' => ['page' => '.+']],
        ['name' => 'xWiki#status', 'url' => '/api/xwiki/status',                    'verb' => 'GET'],

        // AVG / DSAR (GDPR data-subject request) — the entire app-side workflow
        // (avgVerzoek#*, avgEvidence#*, avgRedaction#*, avgDenial#*, avgBundle#*)
        // and the MDM right-of-deletion workflow (mdmAvgWorkflow#*) were removed
        // by consume-or-dsar (ADR-047 Phase 3). DSAR is owned by OpenRegister's
        // case engine (/apps/openregister/avg + /api/gdpr/*); pipelinq registers
        // as an evidence source (PipelinqEvidenceSourceProvider) and deep-links
        // handlers into OR's AVG surface. Existing avgVerzoek objects are
        // migrated to OR dataSubjectRequest cases by MigrateAvgVerzoekenToOrDsar.

        // Master Data Management — the app-side read-API (`/api/mdm/master*`,
        // MdmApiController) was removed by retire-mdm-sync-queue (ADR-022 /
        // ADR-045 #D): downstream apps read master entities directly from
        // OpenRegister's `/api/objects` surface, and MDM steward views, merge
        // tooling, trust-config CRUD and the sync queue are all hosted by
        // OpenRegister.

        // Contract & renewal tracking (contract-renewal-tracking) — app-logic only
        // (numbering, guarded transitions, recurring-revenue metrics). Plain CRUD
        // reads go through OpenRegister directly via useObjectStore (ADR-022).
        ['name' => 'contract#create',         'url' => '/api/contracts', 'verb' => 'POST'],
        ['name' => 'contract#transition',     'url' => '/api/contracts/{id}/transition', 'verb' => 'POST'],
        ['name' => 'contract#summary',        'url' => '/api/contracts/metrics/summary', 'verb' => 'GET'],
        ['name' => 'contract#renewalMetrics', 'url' => '/api/contracts/metrics/renewal', 'verb' => 'GET'],

        // Commercial-configuration store (ADR-080). Consume-only: pipelinq browses a
        // remote registry through OpenRegister AppHost's GenericStoreService and
        // installs CONFIGURATION only (pipelines, queues, skills, catalogues,
        // POS and loyalty setup). The record schemas are refused by
        // StoreController::INSTALLABLE_SLUGS. These sit ABOVE the SPA catch-all
        // because that route matches `.*` and would otherwise answer them with HTML.
        ['name' => 'store#search',       'url' => '/api/store/items',                 'verb' => 'GET'],
        ['name' => 'store#install',      'url' => '/api/store/items/{slug}/install',  'verb' => 'POST', 'requirements' => ['slug' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],
        ['name' => 'store#getSettings',  'url' => '/api/store/settings',              'verb' => 'GET'],
        ['name' => 'store#saveSettings', 'url' => '/api/store/settings',              'verb' => 'PUT'],

        // SPA catch-all — serves the Vue app for any frontend route (history mode)
        ['name' => 'dashboard#page', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.*'], 'defaults' => ['path' => '']],
    ],
];
