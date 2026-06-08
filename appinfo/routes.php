<?php

declare(strict_types=1);

return [
    'routes' => [
        ['name' => 'settings#index', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'settings#create', 'url' => '/api/settings', 'verb' => 'POST'],
        ['name' => 'settings#reimport', 'url' => '/api/settings/reimport', 'verb' => 'POST'],

        // User settings
        ['name' => 'settings#getUserSettings', 'url' => '/api/settings/user', 'verb' => 'GET'],
        ['name' => 'settings#updateUserSettings', 'url' => '/api/settings/user', 'verb' => 'PUT'],

        // Admin — Objects API access control (per-schema group restrictions; ADR-005 / admin-settings spec).
        ['name' => 'settings#getObjectenAccess', 'url' => '/api/settings/objecten-access', 'verb' => 'GET'],
        ['name' => 'settings#saveObjectenAccess', 'url' => '/api/settings/objecten-access', 'verb' => 'POST'],

        // Admin — REST API token management.
        ['name' => 'settings#listTokens', 'url' => '/api/settings/api-tokens', 'verb' => 'GET'],
        ['name' => 'settings#generateToken', 'url' => '/api/settings/api-tokens', 'verb' => 'POST'],
        ['name' => 'settings#revokeToken', 'url' => '/api/settings/api-tokens/{id}', 'verb' => 'DELETE'],

        // Admin — OAuth 2.0 and MCP server configuration.
        ['name' => 'settings#saveOAuth', 'url' => '/api/settings/oauth', 'verb' => 'POST'],
        ['name' => 'settings#saveMcp', 'url' => '/api/settings/mcp', 'verb' => 'POST'],

        // Admin — Shillinq project ledger manual re-dispatch (project-to-shillinq-ledger).
        ['name' => 'ledger#retry', 'url' => '/api/ledger/retry/{projectId}', 'verb' => 'POST'],

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
        ['name' => 'prospect#createLead', 'url' => '/api/prospects/create-lead', 'verb' => 'POST'],

        // Prospect settings (admin only; camelCase slug matches ProspectSettingsController class name)
        ['name' => 'prospectSettings#index', 'url' => '/api/prospect-settings', 'verb' => 'GET'],
        ['name' => 'prospectSettings#update', 'url' => '/api/prospect-settings', 'verb' => 'PUT'],

        // Public intake forms (no auth; camelCase slug matches PublicFormController class name)
        ['name' => 'publicForm#show', 'url' => '/api/public/forms/{id}', 'verb' => 'GET'],
        ['name' => 'publicForm#submit', 'url' => '/api/public/forms/{id}/submit', 'verb' => 'POST'],

        // Intake form management (authenticated; camelCase slug matches IntakeFormController class name)
        ['name' => 'intakeForm#embed', 'url' => '/api/forms/{id}/embed', 'verb' => 'GET'],
        ['name' => 'intakeForm#export', 'url' => '/api/forms/{id}/submissions/export', 'verb' => 'GET'],
        // Rapportage / reporting — specific routes before wildcard catch-all.
        // Klantbeeld 360 — cross-module analytics summary (must precede any wildcard `{slug}` routes).
        ['name' => 'analytics#summary', 'url' => '/api/analytics/summary', 'verb' => 'GET'],
        ['name' => 'reporting#getKpis',     'url' => '/api/rapportage/kpis',     'verb' => 'GET'],
        ['name' => 'reporting#getChannels', 'url' => '/api/rapportage/channels', 'verb' => 'GET'],
        ['name' => 'reporting#getAgents',   'url' => '/api/rapportage/agents',   'verb' => 'GET'],
        ['name' => 'reporting#getSla',      'url' => '/api/rapportage/sla',      'verb' => 'GET'],
        ['name' => 'reporting#updateSla',   'url' => '/api/rapportage/sla',      'verb' => 'PUT'],
        ['name' => 'reporting#exportCsv',   'url' => '/api/rapportage/export',   'verb' => 'GET'],
        // Lead-management analytics endpoint (REQ-LM-006). Non-admin accessible.
        ['name' => 'rapportage#getPipelineStats', 'url' => '/api/rapportage/pipeline-stats', 'verb' => 'GET'],
        // Public survey endpoints (unauthenticated; camelCase slug matches PublicSurveyController class name)
        ['name' => 'publicSurvey#show', 'url' => '/public/survey/{token}', 'verb' => 'GET'],
        ['name' => 'publicSurvey#submit', 'url' => '/public/survey/{token}/respond', 'verb' => 'POST'],

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

        // Loyalty program (loyalty-program — REQ-LOY-001..010).
        ['name' => 'loyalty#getAccount',         'url' => '/api/loyalty/accounts/{accountId}',          'verb' => 'GET'],
        ['name' => 'loyalty#getAccountHistory',  'url' => '/api/loyalty/accounts/{accountId}/history',  'verb' => 'GET'],
        ['name' => 'loyalty#getRedemptionOptions',    'url' => '/api/loyalty/redemption/options/{programmeId}/{accountId}', 'verb' => 'GET'],
        ['name' => 'loyalty#initiateRedemption',      'url' => '/api/loyalty/redemption/initiate/{accountId}/{optionId}',   'verb' => 'POST'],
        ['name' => 'loyalty#lookupRedemptionCode',    'url' => '/api/loyalty/redemption/{code}/validate',                   'verb' => 'POST'],
        ['name' => 'loyalty#useRedemptionCode',       'url' => '/api/loyalty/redemption/{code}/use',                        'verb' => 'POST'],
        ['name' => 'loyalty#lookupGiftCard',    'url' => '/api/loyalty/gift-card/validate',              'verb' => 'POST'],
        ['name' => 'loyalty#redeemGiftCard',    'url' => '/api/loyalty/gift-card/redeem',                'verb' => 'POST'],
        ['name' => 'loyalty#activateGiftCard',  'url' => '/api/loyalty/gift-card/activate/{giftCardId}', 'verb' => 'POST'],
        ['name' => 'loyalty#activateProgramme', 'url' => '/api/loyalty/programme/{programmeId}/activate', 'verb' => 'POST'],
        ['name' => 'loyaltyReporting#kpis',               'url' => '/api/loyalty/reporting/{programmeId}/kpis',          'verb' => 'GET'],
        ['name' => 'loyaltyReporting#liability',          'url' => '/api/loyalty/reporting/{programmeId}/liability',     'verb' => 'GET'],
        ['name' => 'loyaltyReporting#tierDistribution',   'url' => '/api/loyalty/reporting/{programmeId}/tiers',         'verb' => 'GET'],
        ['name' => 'loyaltyReporting#expiryForecast',     'url' => '/api/loyalty/reporting/{programmeId}/expiry-forecast', 'verb' => 'GET'],
        ['name' => 'loyaltyGdpr#export',                  'url' => '/api/loyalty/gdpr/{klantId}/export',                 'verb' => 'GET'],
        ['name' => 'loyaltyGdpr#delete',                  'url' => '/api/loyalty/gdpr/{klantId}',                        'verb' => 'DELETE'],

        // CRM workflow automation has been migrated to the OpenRegister
        // flow leaf (NC Flow / n8n) per migrate-automation-to-flow-leaf;
        // no automation / webhook / dmn endpoints remain in pipelinq.

        // Marketing blast provider webhooks (signature-verified, PublicPage)
        // marketing-segmentation-and-blast-05-jobs-and-webhooks.
        // camelCase slug matches BlastWebhookController class name.
        ['name' => 'blastWebhook#sendgrid', 'url' => '/api/blast-webhooks/sendgrid', 'verb' => 'POST'],
        ['name' => 'blastWebhook#ses',      'url' => '/api/blast-webhooks/ses',      'verb' => 'POST'],
        ['name' => 'blastWebhook#twilio',   'url' => '/api/blast-webhooks/twilio',   'verb' => 'POST'],

        // Appointment booking — deposit payment webhook (signature-verified, PublicPage)
        // appointment-booking-08-deposit-payment / REQ-APT-010.
        // openconnector hits this URL with the payment outcome.
        ['name' => 'appointmentPaymentWebhook#callback', 'url' => '/api/appointment-payment-webhook', 'verb' => 'POST'],

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
        ['name' => 'segment#refreshSize',   'url' => '/api/segments/{id}/size',        'verb' => 'POST'],
        ['name' => 'segment#members',       'url' => '/api/segments/{id}/members',     'verb' => 'GET'],
        ['name' => 'segment#show',          'url' => '/api/segments/{id}',             'verb' => 'GET'],

        // Marketing — CampaignTemplates (marketing-segmentation-and-blast chain member 06).
        // Specific routes precede any wildcard {slug} routes (ADR-016).
        ['name' => 'template#index',  'url' => '/api/templates',      'verb' => 'GET'],
        ['name' => 'template#create', 'url' => '/api/templates',      'verb' => 'POST'],
        ['name' => 'template#show',   'url' => '/api/templates/{id}', 'verb' => 'GET'],
        ['name' => 'template#update', 'url' => '/api/templates/{id}', 'verb' => 'PATCH'],

        // Marketing — Blasts (marketing-segmentation-and-blast chain member 06).
        // Specific routes precede any wildcard {slug} routes (ADR-016).
        ['name' => 'blast#index',      'url' => '/api/blasts',                     'verb' => 'GET'],
        ['name' => 'blast#create',     'url' => '/api/blasts',                     'verb' => 'POST'],
        ['name' => 'blast#send',       'url' => '/api/blasts/{id}/send',           'verb' => 'POST'],
        ['name' => 'blast#cancel',     'url' => '/api/blasts/{id}/cancel',         'verb' => 'POST'],
        ['name' => 'blast#deliveries', 'url' => '/api/blasts/{id}/deliveries',     'verb' => 'GET'],
        ['name' => 'blast#attribution', 'url' => '/api/blasts/{id}/attribution',   'verb' => 'GET'],
        ['name' => 'blast#show',       'url' => '/api/blasts/{id}',                'verb' => 'GET'],
        ['name' => 'blast#update',     'url' => '/api/blasts/{id}',                'verb' => 'PATCH'],

        // SPA catch-all — serves the Vue app for any frontend route (history mode)
        ['name' => 'dashboard#page', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.*'], 'defaults' => ['path' => '']],
    ],
];
