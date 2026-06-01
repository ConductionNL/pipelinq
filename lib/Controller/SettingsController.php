<?php

/**
 * Pipelinq Settings Controller
 *
 * Handles admin settings API endpoints for the Pipelinq CRM.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/admin-settings/tasks.md#task-3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ApiAuthService;
use OCA\Pipelinq\Service\ObjectenAccessService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Settings controller for Pipelinq admin configuration.
 *
 * @spec openspec/changes/admin-settings/tasks.md#task-3
 */
class SettingsController extends Controller
{

    public function __construct(
        string $appName,
        IRequest $request,
        private readonly IAppConfig $appConfig,
        private readonly IGroupManager $groupManager,
        private readonly IUserSession $userSession,
        private readonly ApiAuthService $apiAuthService,
        private readonly ObjectenAccessService $objectenAccessService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);

    }//end __construct()


    /**
     * Returns combined admin settings including objectenAccess, apiTokens, oauthConfig, and mcpConfig.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-3.5
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function index(): JSONResponse
    {
        return new JSONResponse([
            'openRegisters' => true,
            'isAdmin'       => true,
            'objectenAccess' => $this->objectenAccessService->getAccessMap(),
            'apiTokens'     => $this->apiAuthService->listTokens(),
            'oauthConfig'   => $this->apiAuthService->getOAuthConfig(),
            'mcpConfig'     => $this->apiAuthService->getMcpConfig(),
        ]);

    }//end index()


    /**
     * Saves per-schema group access restrictions.
     *
     * @param string $schemaSlug The schema slug to configure.
     * @param array  $groupIds   List of group IDs that may access this schema.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-3.1
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function saveObjectenAccess(string $schemaSlug, array $groupIds = []): JSONResponse
    {
        if (empty($schemaSlug) === true) {
            return new JSONResponse(['message' => 'Schema slug is required'], 400);
        }

        try {
            $this->objectenAccessService->setSchemaAccess(schemaSlug: $schemaSlug, groupIds: $groupIds);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to save objecten access', ['exception' => $e]);
            return new JSONResponse(['message' => 'Failed to save access configuration'], 500);
        }

        return new JSONResponse(['objectenAccess' => $this->objectenAccessService->getAccessMap()]);

    }//end saveObjectenAccess()


    /**
     * Lists API tokens (metadata only, no hashes).
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-3.2
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function listTokens(): JSONResponse
    {
        return new JSONResponse($this->apiAuthService->listTokens());

    }//end listTokens()


    /**
     * Generates a new API token and returns plaintext once.
     *
     * @param string $label Human-readable label for the token.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-3.2
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function generateToken(string $label = ''): JSONResponse
    {
        if (empty($label) === true) {
            return new JSONResponse(['message' => 'Token label is required'], 400);
        }

        try {
            $result = $this->apiAuthService->generateToken(label: $label);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to generate token', ['exception' => $e]);
            return new JSONResponse(['message' => 'Failed to generate token'], 500);
        }

        return new JSONResponse($result);

    }//end generateToken()


    /**
     * Revokes an API token by ID.
     *
     * @param string $id Token UUID to revoke.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-3.2
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function revokeToken(string $id): JSONResponse
    {
        try {
            $this->apiAuthService->revokeToken(id: $id);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to revoke token', ['exception' => $e]);
            return new JSONResponse(['message' => 'Failed to revoke token'], 500);
        }

        return new JSONResponse(['message' => 'Token revoked successfully']);

    }//end revokeToken()


    /**
     * Saves OAuth 2.0 configuration.
     *
     * @param string $clientId          OAuth client ID.
     * @param string $clientSecret      OAuth client secret (skipped if placeholder).
     * @param string $tokenEndpoint     Token endpoint URL.
     * @param string $authEndpoint      Authorization endpoint URL.
     * @param string $scopes            Space-separated scopes.
     * @param bool   $idTokenForwarding Whether to forward idToken with openid scope.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-3.3
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function saveOAuth(
        string $clientId = '',
        string $clientSecret = '',
        string $tokenEndpoint = '',
        string $authEndpoint = '',
        string $scopes = '',
        bool $idTokenForwarding = false,
    ): JSONResponse {
        try {
            $this->apiAuthService->saveOAuthConfig(config: [
                'clientId'          => $clientId,
                'clientSecret'      => $clientSecret,
                'tokenEndpoint'     => $tokenEndpoint,
                'authEndpoint'      => $authEndpoint,
                'scopes'            => $scopes,
                'idTokenForwarding' => $idTokenForwarding,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to save OAuth config', ['exception' => $e]);
            return new JSONResponse(['message' => 'Failed to save OAuth configuration'], 500);
        }

        return new JSONResponse(['oauthConfig' => $this->apiAuthService->getOAuthConfig()]);

    }//end saveOAuth()


    /**
     * Saves MCP server configuration.
     *
     * @param string $endpoint         MCP server base URL.
     * @param string $authMode         Auth mode: 'oauth2' or 'apikey'.
     * @param string $apiKey           API key (sensitive, skipped if placeholder).
     * @param string $oauthClientId    OAuth client ID for MCP.
     * @param string $oauthClientSecret OAuth client secret (sensitive, skipped if placeholder).
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-3.4
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function saveMcp(
        string $endpoint = '',
        string $authMode = 'apikey',
        string $apiKey = '',
        string $oauthClientId = '',
        string $oauthClientSecret = '',
    ): JSONResponse {
        try {
            $this->apiAuthService->saveMcpConfig(config: [
                'endpoint'          => $endpoint,
                'authMode'          => $authMode,
                'apiKey'            => $apiKey,
                'oauthClientId'     => $oauthClientId,
                'oauthClientSecret' => $oauthClientSecret,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to save MCP config', ['exception' => $e]);
            return new JSONResponse(['message' => 'Failed to save MCP configuration'], 500);
        }

        return new JSONResponse(['mcpConfig' => $this->apiAuthService->getMcpConfig()]);

    }//end saveMcp()


}//end class
