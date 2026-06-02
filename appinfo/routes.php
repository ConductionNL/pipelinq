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
        ['name' => 'leadSource#index', 'url' => '/api/lead-sources', 'verb' => 'GET'],
        ['name' => 'leadSource#create', 'url' => '/api/lead-sources', 'verb' => 'POST'],
        ['name' => 'leadSource#update', 'url' => '/api/lead-sources/{id}', 'verb' => 'PUT'],
        ['name' => 'leadSource#destroy', 'url' => '/api/lead-sources/{id}', 'verb' => 'DELETE'],

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
        ['name' => 'requestChannel#index', 'url' => '/api/request-channels', 'verb' => 'GET'],
        ['name' => 'requestChannel#create', 'url' => '/api/request-channels', 'verb' => 'POST'],
        ['name' => 'requestChannel#update', 'url' => '/api/request-channels/{id}', 'verb' => 'PUT'],
        ['name' => 'requestChannel#destroy', 'url' => '/api/request-channels/{id}', 'verb' => 'DELETE'],

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

        // CRM workflow automations (camelCase slug matches AutomationController class name).
        // Specific routes precede the {id} wildcards (ADR-016).
        ['name' => 'automationVariable#runtime',   'url' => '/api/automations/runtime', 'verb' => 'GET'],
        ['name' => 'automation#index',             'url' => '/api/automations', 'verb' => 'GET'],
        ['name' => 'automation#create',            'url' => '/api/automations', 'verb' => 'POST'],
        ['name' => 'automation#history',           'url' => '/api/automations/{id}/history', 'verb' => 'GET'],
        ['name' => 'automationVariable#variables', 'url' => '/api/automations/{id}/variables', 'verb' => 'GET'],
        ['name' => 'automation#activate',          'url' => '/api/automations/{id}/activate', 'verb' => 'PUT'],
        ['name' => 'automation#deactivate',        'url' => '/api/automations/{id}/deactivate', 'verb' => 'PUT'],
        ['name' => 'automation#show',              'url' => '/api/automations/{id}', 'verb' => 'GET'],
        ['name' => 'automation#update',            'url' => '/api/automations/{id}', 'verb' => 'PUT'],
        ['name' => 'automation#destroy',           'url' => '/api/automations/{id}', 'verb' => 'DELETE'],

        // Webhook subscriptions (delegates to OpenRegister's webhook engine).
        ['name' => 'webhook#events',  'url' => '/api/webhooks/events', 'verb' => 'GET'],
        ['name' => 'webhook#index',   'url' => '/api/webhooks', 'verb' => 'GET'],
        ['name' => 'webhook#create',  'url' => '/api/webhooks', 'verb' => 'POST'],
        ['name' => 'webhook#test',    'url' => '/api/webhooks/{id}/test', 'verb' => 'POST'],
        ['name' => 'webhook#update',  'url' => '/api/webhooks/{id}', 'verb' => 'PUT'],
        ['name' => 'webhook#destroy', 'url' => '/api/webhooks/{id}', 'verb' => 'DELETE'],

        // DMN decision evaluation (admin only).
        ['name' => 'dmn#tables',   'url' => '/api/dmn/tables', 'verb' => 'GET'],
        ['name' => 'dmn#evaluate', 'url' => '/api/dmn/evaluate', 'verb' => 'POST'],

        // SPA catch-all — serves the Vue app for any frontend route (history mode)
        ['name' => 'dashboard#page', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.*'], 'defaults' => ['path' => '']],
    ],
];
