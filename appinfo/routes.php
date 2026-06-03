<?php

/**
 * Pipelinq route definitions.
 *
 * Maps every controller action to an HTTP verb + URL. Specific (static) paths
 * are declared before any `{id}` wildcard so the router never lets a literal
 * segment fall into a wildcard, and the SPA catch-all is always last.
 *
 * @category Configuration
 * @package  OCA\Pipelinq
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

return [
    'routes' => [
        ['name' => 'settings#index', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'settings#create', 'url' => '/api/settings', 'verb' => 'POST'],
        ['name' => 'settings#reimport', 'url' => '/api/settings/reimport', 'verb' => 'POST'],

        // User settings.
        ['name' => 'settings#getUserSettings', 'url' => '/api/settings/user', 'verb' => 'GET'],
        ['name' => 'settings#updateUserSettings', 'url' => '/api/settings/user', 'verb' => 'PUT'],

        // Generic per-user preferences (used by shared nextcloud-vue widgets, e.g. CnSupportDialog).
        ['name' => 'preferences#getPreference', 'url' => '/api/preferences/{key}', 'verb' => 'GET'],
        ['name' => 'preferences#setPreference', 'url' => '/api/preferences/{key}', 'verb' => 'PUT'],

        // Lead sources (camelCase slug matches LeadSourceController class name).
        ['name' => 'leadSource#index', 'url' => '/api/lead-sources', 'verb' => 'GET'],
        ['name' => 'leadSource#create', 'url' => '/api/lead-sources', 'verb' => 'POST'],
        ['name' => 'leadSource#update', 'url' => '/api/lead-sources/{id}', 'verb' => 'PUT'],
        ['name' => 'leadSource#destroy', 'url' => '/api/lead-sources/{id}', 'verb' => 'DELETE'],

        // Contacts sync (camelCase slug matches ContactSyncController class name).
        ['name' => 'contactSync#search', 'url' => '/api/contacts-sync/search', 'verb' => 'GET'],
        ['name' => 'contactSync#import', 'url' => '/api/contacts-sync/import', 'verb' => 'POST'],
        ['name' => 'contactSync#writeBack', 'url' => '/api/contacts-sync/write-back', 'verb' => 'POST'],

        // Entity notes.
        ['name' => 'notes#list', 'url' => '/api/notes/{objectType}/{objectId}', 'verb' => 'GET'],
        ['name' => 'notes#create', 'url' => '/api/notes/{objectType}/{objectId}', 'verb' => 'POST'],
        ['name' => 'notes#deleteAll', 'url' => '/api/notes/{objectType}/{objectId}', 'verb' => 'DELETE'],
        ['name' => 'notes#deleteSingle', 'url' => '/api/notes/single/{noteId}', 'verb' => 'DELETE'],

        // Request channels (camelCase slug matches RequestChannelController class name).
        ['name' => 'requestChannel#index', 'url' => '/api/request-channels', 'verb' => 'GET'],
        ['name' => 'requestChannel#create', 'url' => '/api/request-channels', 'verb' => 'POST'],
        ['name' => 'requestChannel#update', 'url' => '/api/request-channels/{id}', 'verb' => 'PUT'],
        ['name' => 'requestChannel#destroy', 'url' => '/api/request-channels/{id}', 'verb' => 'DELETE'],

        // Prospect discovery.
        ['name' => 'prospect#index', 'url' => '/api/prospects', 'verb' => 'GET'],
        ['name' => 'prospect#createLead', 'url' => '/api/prospects/create-lead', 'verb' => 'POST'],

        // Prospect settings (admin only; camelCase slug matches ProspectSettingsController class name).
        ['name' => 'prospectSettings#index', 'url' => '/api/prospect-settings', 'verb' => 'GET'],
        ['name' => 'prospectSettings#update', 'url' => '/api/prospect-settings', 'verb' => 'PUT'],

        // Public intake forms (no auth; camelCase slug matches PublicFormController class name).
        ['name' => 'publicForm#show', 'url' => '/api/public/forms/{id}', 'verb' => 'GET'],
        ['name' => 'publicForm#submit', 'url' => '/api/public/forms/{id}/submit', 'verb' => 'POST'],

        // Intake form management (authenticated; camelCase slug matches IntakeFormController class name).
        ['name' => 'intakeForm#embed', 'url' => '/api/forms/{id}/embed', 'verb' => 'GET'],
        ['name' => 'intakeForm#export', 'url' => '/api/forms/{id}/submissions/export', 'verb' => 'GET'],
        // Rapportage / reporting.
        ['name' => 'reporting#getSla', 'url' => '/api/rapportage/sla', 'verb' => 'GET'],
        ['name' => 'reporting#updateSla', 'url' => '/api/rapportage/sla', 'verb' => 'PUT'],
        ['name' => 'reporting#exportCsv', 'url' => '/api/rapportage/export', 'verb' => 'GET'],
        // Public survey endpoints (unauthenticated; camelCase slug matches PublicSurveyController class name).
        ['name' => 'publicSurvey#show', 'url' => '/public/survey/{token}', 'verb' => 'GET'],
        ['name' => 'publicSurvey#submit', 'url' => '/public/survey/{token}/respond', 'verb' => 'POST'],

        // Contactmomenten (permission-checked delete).
        ['name' => 'contactmoment#destroy', 'url' => '/api/contactmomenten/{id}', 'verb' => 'DELETE'],

        // Callback management endpoints.
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

        // Activity timeline and worklog endpoints (camelCase slug matches ActivityTimelineController class name).
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

        // BSN validation + BRP lookup (camelCase slug matches BrpController class name).
        // Static paths only; the BSN never appears in a URL (REQ-BSN-009). The
        // mutation webhook is a PublicPage authenticated by an HMAC signature.
        ['name' => 'brp#validate', 'url' => '/api/brp/validate',  'verb' => 'POST'],
        ['name' => 'brp#lookup',   'url' => '/api/brp/lookup',    'verb' => 'POST'],
        ['name' => 'brp#mutation', 'url' => '/api/brp/mutations', 'verb' => 'POST'],

        // BRP admin configuration (admin-gated; secrets encrypted at rest).
        ['name' => 'brpConfig#show',   'url' => '/api/brp/config', 'verb' => 'GET'],
        ['name' => 'brpConfig#update', 'url' => '/api/brp/config', 'verb' => 'PUT'],
        // BRP service-health report (admin monitor tile; REQ-BSN-010).
        ['name' => 'brpConfig#report', 'url' => '/api/brp/report', 'verb' => 'GET'],

        // SPA catch-all — serves the Vue app for any frontend route (history mode).
        ['name' => 'dashboard#page', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.*'], 'defaults' => ['path' => '']],
    ],
];
