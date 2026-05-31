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
        ['name' => 'posTransaction#confirm', 'url' => '/api/pos-transactions/{id}/confirm', 'verb' => 'POST'],
        ['name' => 'posTransaction#settle',  'url' => '/api/pos-transactions/{id}/settle',  'verb' => 'POST'],
        ['name' => 'posTransaction#refund',  'url' => '/api/pos-transactions/{id}/refund',  'verb' => 'POST'],
        ['name' => 'posTransaction#park',    'url' => '/api/pos-transactions/{id}/park',    'verb' => 'POST'],
        ['name' => 'posTransaction#resume',  'url' => '/api/pos-transactions/{id}/resume',  'verb' => 'POST'],

        // POS product catalogue resolution (barcode lookup + server-authoritative price).
        ['name' => 'productCatalog#lookupBarcode', 'url' => '/api/products/barcode-lookup', 'verb' => 'POST'],
        ['name' => 'productCatalog#resolvePrice',  'url' => '/api/products/resolve-price',  'verb' => 'POST'],

        // SPA catch-all — serves the Vue app for any frontend route (history mode)
        ['name' => 'dashboard#page', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.*'], 'defaults' => ['path' => '']],
    ],
];
