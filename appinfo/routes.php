<?php

declare(strict_types=1);

return [
    'routes' => [
        ['name' => 'dashboard#page', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'settings#index', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'settings#create', 'url' => '/api/settings', 'verb' => 'POST'],
        ['name' => 'settings#reimport', 'url' => '/api/settings/reimport', 'verb' => 'POST'],

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
    ],
];
