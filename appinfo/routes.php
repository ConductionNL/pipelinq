<?php

/**
 * Pipelinq Routes
 *
 * @spec openspec/changes/admin-settings/tasks.md#task-4.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

return [
    'routes' => [
        // Settings — general.
        ['name' => 'settings#index', 'url' => '/api/settings', 'verb' => 'GET'],

        // Settings — Objects API access control.
        // @spec openspec/changes/admin-settings/tasks.md#task-4.1
        ['name' => 'settings#saveObjectenAccess', 'url' => '/api/settings/objecten-access', 'verb' => 'POST'],

        // Settings — API token management.
        // @spec openspec/changes/admin-settings/tasks.md#task-4.1
        ['name' => 'settings#listTokens', 'url' => '/api/settings/api-tokens', 'verb' => 'GET'],
        ['name' => 'settings#generateToken', 'url' => '/api/settings/api-tokens', 'verb' => 'POST'],
        ['name' => 'settings#revokeToken', 'url' => '/api/settings/api-tokens/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+']],

        // Settings — OAuth 2.0 configuration.
        // @spec openspec/changes/admin-settings/tasks.md#task-4.1
        ['name' => 'settings#saveOAuth', 'url' => '/api/settings/oauth', 'verb' => 'POST'],

        // Settings — MCP server configuration.
        // @spec openspec/changes/admin-settings/tasks.md#task-4.1
        ['name' => 'settings#saveMcp', 'url' => '/api/settings/mcp', 'verb' => 'POST'],
    ],
];
