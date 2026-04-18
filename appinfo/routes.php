<?php

declare(strict_types=1);

return [
    'routes' => [
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
        // Automations
        ['name' => 'automation#metadata', 'url' => '/api/automations/metadata', 'verb' => 'GET'],
        ['name' => 'automation#test', 'url' => '/api/automations/test', 'verb' => 'POST'],
        // Kennisbank public API
        ['name' => 'kennisbank#publicIndex', 'url' => '/api/kennisbank/public', 'verb' => 'GET'],
        ['name' => 'kennisbank#publicShow', 'url' => '/api/kennisbank/public/{id}', 'verb' => 'GET'],
        ['name' => 'kennisbank#submitFeedback', 'url' => '/api/kennisbank/feedback', 'verb' => 'POST'],
        // Rapportage / reporting
        ['name' => 'reporting#getSla', 'url' => '/api/rapportage/sla', 'verb' => 'GET'],
        ['name' => 'reporting#updateSla', 'url' => '/api/rapportage/sla', 'verb' => 'PUT'],
        ['name' => 'reporting#exportCsv', 'url' => '/api/rapportage/export', 'verb' => 'GET'],

        // KPI dashboard
        ['name' => 'reporting#getKpiSummary', 'url' => '/api/rapportage/dashboard/kpi-summary', 'verb' => 'GET'],
        ['name' => 'reporting#getKpiTrend', 'url' => '/api/rapportage/dashboard/trends', 'verb' => 'GET'],

        // Channel analytics
        ['name' => 'reporting#getChannelDistribution', 'url' => '/api/rapportage/analytics/channel-distribution', 'verb' => 'GET'],
        ['name' => 'reporting#getChannelComparison', 'url' => '/api/rapportage/analytics/channel-comparison', 'verb' => 'GET'],

        // Queue monitoring
        ['name' => 'reporting#getQueueStats', 'url' => '/api/rapportage/queue/statistics', 'verb' => 'GET'],
        ['name' => 'reporting#getHistoricalWaitTimes', 'url' => '/api/rapportage/queue/historical', 'verb' => 'GET'],
        ['name' => 'reporting#getWaitTimeSlaAlert', 'url' => '/api/rapportage/queue/sla-alert', 'verb' => 'GET'],

        // Agent performance
        ['name' => 'reporting#getAgentStats', 'url' => '/api/rapportage/agents/{agentId}/statistics', 'verb' => 'GET'],
        ['name' => 'reporting#getTeamOverview', 'url' => '/api/rapportage/agents/team-overview', 'verb' => 'GET'],
        ['name' => 'reporting#getAgentWorkload', 'url' => '/api/rapportage/agents/workload-distribution', 'verb' => 'GET'],
        ['name' => 'reporting#getAgentTrend', 'url' => '/api/rapportage/agents/{agentId}/trends', 'verb' => 'GET'],

        // Trend reporting
        ['name' => 'reporting#getMonthlyTrend', 'url' => '/api/rapportage/trends/monthly', 'verb' => 'GET'],
        ['name' => 'reporting#getPeakHours', 'url' => '/api/rapportage/trends/peak-hours', 'verb' => 'GET'],
        ['name' => 'reporting#getSubjectTrends', 'url' => '/api/rapportage/trends/subjects', 'verb' => 'GET'],

        // WOO reporting
        ['name' => 'reporting#getWooReport', 'url' => '/api/rapportage/woo/report', 'verb' => 'GET'],
        ['name' => 'reporting#getAnnualStatistics', 'url' => '/api/rapportage/woo/annual-statistics', 'verb' => 'GET'],
        ['name' => 'reporting#getBenchmarkComparison', 'url' => '/api/rapportage/woo/benchmark-comparison', 'verb' => 'GET'],

        // BI data extraction
        ['name' => 'reporting#getContactmomentsData', 'url' => '/api/rapportage/data/contactmomenten', 'verb' => 'GET'],
        ['name' => 'reporting#getKpiAggregates', 'url' => '/api/rapportage/data/kpi-aggregates', 'verb' => 'GET'],

        // Subject analytics
        ['name' => 'reporting#getSubjectAnalytics', 'url' => '/api/rapportage/analytics/subjects', 'verb' => 'GET'],
        // Public survey endpoints (unauthenticated)
        ['name' => 'public_survey#show', 'url' => '/public/survey/{token}', 'verb' => 'GET'],
        ['name' => 'public_survey#submit', 'url' => '/public/survey/{token}/respond', 'verb' => 'POST'],

        // Public kennisbank API (unauthenticated)
        ['name' => 'public_kennisbank#index', 'url' => '/api/public/kennisbank/articles', 'verb' => 'GET'],
        ['name' => 'public_kennisbank#show', 'url' => '/api/public/kennisbank/articles/{id}', 'verb' => 'GET'],

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

        // SPA catch-all — serves the Vue app for any frontend route (history mode)
        ['name' => 'dashboard#page', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.*'], 'defaults' => ['path' => '']],
    ],
];
