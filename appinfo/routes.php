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
        // Rapportage / reporting
        ['name' => 'reporting#getSla', 'url' => '/api/rapportage/sla', 'verb' => 'GET'],
        ['name' => 'reporting#updateSla', 'url' => '/api/rapportage/sla', 'verb' => 'PUT'],
        ['name' => 'reporting#exportCsv', 'url' => '/api/rapportage/export', 'verb' => 'GET'],
        // Public survey endpoints (unauthenticated; camelCase slug matches PublicSurveyController class name)
        ['name' => 'publicSurvey#show', 'url' => '/public/survey/{token}', 'verb' => 'GET'],
        ['name' => 'publicSurvey#submit', 'url' => '/public/survey/{token}/respond', 'verb' => 'POST'],

        // Contactmomenten (permission-checked delete)
        ['name' => 'contactmoment#destroy', 'url' => '/api/contactmomenten/{id}', 'verb' => 'DELETE'],

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

        // Admin / DPO (Nextcloud admin only; no #[PublicPage] — admin-default).
        ['name' => 'portalAdmin#saveConfig',   'url' => '/portal/api/admin/tenant-config', 'verb' => 'POST'],
        ['name' => 'portalAdmin#accounts',     'url' => '/portal/api/admin/accounts',      'verb' => 'GET'],
        ['name' => 'portalAdmin#auditEvents',  'url' => '/portal/api/admin/audit-events',  'verb' => 'GET'],

        // Public portal SPA shell. Registered AFTER all /portal/api/* routes so
        // the API wins, and BEFORE the main-app catch-all so /portal serves the
        // isolated portal bundle rather than the Nextcloud-authenticated app.
        ['name' => 'portalPage#index', 'url' => '/portal', 'verb' => 'GET'],
        ['name' => 'portalPage#subpath', 'url' => '/portal/{path}', 'verb' => 'GET', 'requirements' => ['path' => '^(?!api/).*'], 'defaults' => ['path' => '']],

        // SPA catch-all — serves the Vue app for any frontend route (history mode)
        ['name' => 'dashboard#page', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.*'], 'defaults' => ['path' => '']],
    ],
];
