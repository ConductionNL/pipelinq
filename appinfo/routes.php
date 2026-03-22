<?php

declare(strict_types=1);

return [
    'routes' => [
        ['name' => 'dashboard#page', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'settings#index', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'settings#create', 'url' => '/api/settings', 'verb' => 'POST'],
        ['name' => 'settings#reimport', 'url' => '/api/settings/reimport', 'verb' => 'POST'],

        // User settings
        ['name' => 'settings#getUserSettings', 'url' => '/api/user/settings', 'verb' => 'GET'],
        ['name' => 'settings#updateUserSettings', 'url' => '/api/user/settings', 'verb' => 'PUT'],

        // Lead sources
        ['name' => 'lead_source#index', 'url' => '/api/settings/lead-sources', 'verb' => 'GET'],
        ['name' => 'lead_source#create', 'url' => '/api/settings/lead-sources', 'verb' => 'POST'],
        ['name' => 'lead_source#update', 'url' => '/api/settings/lead-sources/{id}', 'verb' => 'PUT'],
        ['name' => 'lead_source#destroy', 'url' => '/api/settings/lead-sources/{id}', 'verb' => 'DELETE'],

        // Contacts sync
        ['name' => 'contact_sync#search', 'url' => '/api/contacts-sync/search', 'verb' => 'GET'],
        ['name' => 'contact_sync#import', 'url' => '/api/contacts-sync/import', 'verb' => 'POST'],
        ['name' => 'contact_sync#writeBack', 'url' => '/api/contacts-sync/write-back', 'verb' => 'POST'],

        // Entity notes
        ['name' => 'notes#list', 'url' => '/api/notes/{objectType}/{objectId}', 'verb' => 'GET'],
        ['name' => 'notes#create', 'url' => '/api/notes/{objectType}/{objectId}', 'verb' => 'POST'],
        ['name' => 'notes#deleteAll', 'url' => '/api/notes/{objectType}/{objectId}', 'verb' => 'DELETE'],
        ['name' => 'notes#deleteSingle', 'url' => '/api/notes/single/{noteId}', 'verb' => 'DELETE'],

        // Request channels
        ['name' => 'request_channel#index', 'url' => '/api/settings/request-channels', 'verb' => 'GET'],
        ['name' => 'request_channel#create', 'url' => '/api/settings/request-channels', 'verb' => 'POST'],
        ['name' => 'request_channel#update', 'url' => '/api/settings/request-channels/{id}', 'verb' => 'PUT'],
        ['name' => 'request_channel#destroy', 'url' => '/api/settings/request-channels/{id}', 'verb' => 'DELETE'],

        // Prospect discovery
        ['name' => 'prospect#index', 'url' => '/api/prospects', 'verb' => 'GET'],
        ['name' => 'prospect#createLead', 'url' => '/api/prospects/create-lead', 'verb' => 'POST'],

        // Prospect settings (admin only)
        ['name' => 'prospect_settings#index', 'url' => '/api/prospects/settings', 'verb' => 'GET'],
        ['name' => 'prospect_settings#update', 'url' => '/api/prospects/settings', 'verb' => 'PUT'],

        // Public intake forms (no auth)
        ['name' => 'public_form#show', 'url' => '/api/public/forms/{id}', 'verb' => 'GET'],
        ['name' => 'public_form#submit', 'url' => '/api/public/forms/{id}/submit', 'verb' => 'POST'],

        // Intake form management (authenticated)
        ['name' => 'intake_form#embed', 'url' => '/api/forms/{id}/embed', 'verb' => 'GET'],
        ['name' => 'intake_form#export', 'url' => '/api/forms/{id}/submissions/export', 'verb' => 'GET'],
        // Public survey endpoints (unauthenticated)
        ['name' => 'public_survey#show', 'url' => '/public/survey/{token}', 'verb' => 'GET'],
        ['name' => 'public_survey#submit', 'url' => '/public/survey/{token}/respond', 'verb' => 'POST'],

        // Public kennisbank API (unauthenticated)
        ['name' => 'public_kennisbank#index', 'url' => '/api/public/kennisbank/articles', 'verb' => 'GET'],
        ['name' => 'public_kennisbank#show', 'url' => '/api/public/kennisbank/articles/{id}', 'verb' => 'GET'],

        // Prometheus metrics endpoint.
        ['name' => 'metrics#index', 'url' => '/api/metrics', 'verb' => 'GET'],
        // Health check endpoint.
        ['name' => 'health#index', 'url' => '/api/health', 'verb' => 'GET'],

        // SPA catch-all — serves the Vue app for any frontend route (history mode)
        ['name' => 'dashboard#page', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+'], 'defaults' => ['path' => '']],
    ],
];
