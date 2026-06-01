<?php

/**
 * Pipelinq API Authentication Service
 *
 * Manages REST API tokens, OAuth 2.0 configuration, and MCP server configuration.
 * All secrets are stored in IAppConfig with sensitive: true.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/admin-settings/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;

/**
 * Service for REST API token generation, OAuth 2.0, and MCP server configuration.
 *
 * @spec openspec/changes/admin-settings/tasks.md#task-2.1
 */
class ApiAuthService
{

    private const TOKEN_PREFIX        = 'api_token_';
    private const OAUTH_CLIENT_ID     = 'oauth_client_id';
    private const OAUTH_CLIENT_SECRET = 'oauth_client_secret';
    private const OAUTH_TOKEN_EP      = 'oauth_token_endpoint';
    private const OAUTH_AUTH_EP       = 'oauth_auth_endpoint';
    private const OAUTH_SCOPES        = 'oauth_scopes';
    private const OAUTH_ID_TOKEN      = 'oauth_id_token_forwarding';
    private const MCP_ENDPOINT        = 'mcp_endpoint';
    private const MCP_AUTH_MODE       = 'mcp_auth_mode';
    private const MCP_API_KEY         = 'mcp_api_key';
    private const MCP_OAUTH_CLIENT_ID = 'mcp_oauth_client_id';
    private const MCP_OAUTH_SECRET    = 'mcp_oauth_client_secret';

    private const PLACEHOLDER = '••••••••';

    private const TOKEN_BYTES = 32;


    public function __construct(
        private readonly IAppConfig $appConfig,
        private readonly ISecureRandom $secureRandom,
    ) {

    }//end __construct()


    /**
     * Generates a new 256-bit API token, stores its SHA-256 hash, returns plaintext once.
     *
     * @param string $label Human-readable label for the token.
     *
     * @return array{id: string, token: string, label: string, created: string}
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-2.1
     */
    public function generateToken(string $label): array
    {
        $id        = $this->secureRandom->generate(32, ISecureRandom::CHAR_ALPHANUMERIC);
        $plaintext = $this->secureRandom->generate(self::TOKEN_BYTES * 2, ISecureRandom::CHAR_ALPHANUMERIC);
        $hash      = hash(algo: 'sha256', data: $plaintext);
        $created   = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        $meta = json_encode([
            'id'       => $id,
            'label'    => $label,
            'hash'     => $hash,
            'created'  => $created,
            'lastUsed' => null,
        ]);

        $this->appConfig->setValueString(
            app: Application::APP_ID,
            key: self::TOKEN_PREFIX . $id,
            value: $meta,
        );

        return [
            'id'      => $id,
            'token'   => $plaintext,
            'label'   => $label,
            'created' => $created,
        ];

    }//end generateToken()


    /**
     * Returns token metadata array without hashes.
     *
     * @return array<int, array{id: string, label: string, created: string, lastUsed: string|null}>
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-2.1
     */
    public function listTokens(): array
    {
        $keys   = $this->appConfig->getKeys(app: Application::APP_ID);
        $tokens = [];

        foreach ($keys as $key) {
            if (str_starts_with(haystack: $key, needle: self::TOKEN_PREFIX) === false) {
                continue;
            }

            $encoded = $this->appConfig->getValueString(app: Application::APP_ID, key: $key, default: '');
            $data    = json_decode(json: $encoded, associative: true);

            if (is_array(value: $data) === false) {
                continue;
            }

            $tokens[] = [
                'id'       => $data['id'],
                'label'    => $data['label'],
                'created'  => $data['created'],
                'lastUsed' => $data['lastUsed'],
            ];
        }

        return $tokens;

    }//end listTokens()


    /**
     * Deletes an API token from IAppConfig.
     *
     * @param string $id Token UUID to revoke.
     *
     * @return void
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-2.1
     */
    public function revokeToken(string $id): void
    {
        $this->appConfig->deleteKey(
            app: Application::APP_ID,
            key: self::TOKEN_PREFIX . $id,
        );

    }//end revokeToken()


    /**
     * Validates a plaintext bearer token by comparing its SHA-256 hash against stored tokens.
     * Updates lastUsed on match.
     *
     * @param string $plaintext The bearer token to validate.
     *
     * @return bool True if a matching token exists.
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-2.1
     */
    public function validateToken(string $plaintext): bool
    {
        $hash = hash(algo: 'sha256', data: $plaintext);
        $keys = $this->appConfig->getKeys(app: Application::APP_ID);

        foreach ($keys as $key) {
            if (str_starts_with(haystack: $key, needle: self::TOKEN_PREFIX) === false) {
                continue;
            }

            $encoded = $this->appConfig->getValueString(app: Application::APP_ID, key: $key, default: '');
            $data    = json_decode(json: $encoded, associative: true);

            if (is_array(value: $data) === false || $data['hash'] !== $hash) {
                continue;
            }

            $data['lastUsed'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
            $this->appConfig->setValueString(
                app: Application::APP_ID,
                key: $key,
                value: json_encode(value: $data),
            );

            return true;
        }

        return false;

    }//end validateToken()


    /**
     * Saves OAuth 2.0 configuration. Skips client secret if value equals placeholder.
     *
     * @param array $config Configuration array with keys: clientId, clientSecret, tokenEndpoint, authEndpoint, scopes, idTokenForwarding.
     *
     * @return void
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-2.1
     */
    public function saveOAuthConfig(array $config): void
    {
        if (isset($config['clientId']) === true) {
            $this->appConfig->setValueString(app: Application::APP_ID, key: self::OAUTH_CLIENT_ID, value: (string) $config['clientId']);
        }

        if (isset($config['clientSecret']) === true && $config['clientSecret'] !== self::PLACEHOLDER && $config['clientSecret'] !== '') {
            $this->appConfig->setValueString(
                app: Application::APP_ID,
                key: self::OAUTH_CLIENT_SECRET,
                value: (string) $config['clientSecret'],
                sensitive: true,
            );
        }

        if (isset($config['tokenEndpoint']) === true) {
            $this->appConfig->setValueString(app: Application::APP_ID, key: self::OAUTH_TOKEN_EP, value: (string) $config['tokenEndpoint']);
        }

        if (isset($config['authEndpoint']) === true) {
            $this->appConfig->setValueString(app: Application::APP_ID, key: self::OAUTH_AUTH_EP, value: (string) $config['authEndpoint']);
        }

        if (isset($config['scopes']) === true) {
            $this->appConfig->setValueString(app: Application::APP_ID, key: self::OAUTH_SCOPES, value: (string) $config['scopes']);
        }

        if (isset($config['idTokenForwarding']) === true) {
            $this->appConfig->setValueString(app: Application::APP_ID, key: self::OAUTH_ID_TOKEN, value: $config['idTokenForwarding'] === true ? '1' : '0');
        }

    }//end saveOAuthConfig()


    /**
     * Returns non-sensitive OAuth 2.0 configuration fields (no client_secret).
     *
     * @return array{clientId: string, tokenEndpoint: string, authEndpoint: string, scopes: string, idTokenForwarding: bool, hasSecret: bool}
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-2.1
     */
    public function getOAuthConfig(): array
    {
        $hasSecret = $this->appConfig->getValueString(app: Application::APP_ID, key: self::OAUTH_CLIENT_SECRET, default: '') !== '';

        return [
            'clientId'          => $this->appConfig->getValueString(app: Application::APP_ID, key: self::OAUTH_CLIENT_ID, default: ''),
            'tokenEndpoint'     => $this->appConfig->getValueString(app: Application::APP_ID, key: self::OAUTH_TOKEN_EP, default: ''),
            'authEndpoint'      => $this->appConfig->getValueString(app: Application::APP_ID, key: self::OAUTH_AUTH_EP, default: ''),
            'scopes'            => $this->appConfig->getValueString(app: Application::APP_ID, key: self::OAUTH_SCOPES, default: ''),
            'idTokenForwarding' => $this->appConfig->getValueString(app: Application::APP_ID, key: self::OAUTH_ID_TOKEN, default: '0') === '1',
            'hasSecret'         => $hasSecret,
        ];

    }//end getOAuthConfig()


    /**
     * Saves MCP server configuration. Skips secrets if value equals placeholder.
     *
     * @param array $config Configuration array with keys: endpoint, authMode, apiKey, oauthClientId, oauthClientSecret.
     *
     * @return void
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-2.1
     */
    public function saveMcpConfig(array $config): void
    {
        if (isset($config['endpoint']) === true) {
            $this->appConfig->setValueString(app: Application::APP_ID, key: self::MCP_ENDPOINT, value: (string) $config['endpoint']);
        }

        if (isset($config['authMode']) === true) {
            $authMode = in_array(needle: $config['authMode'], haystack: ['oauth2', 'apikey'], strict: true)
                ? $config['authMode']
                : 'apikey';
            $this->appConfig->setValueString(app: Application::APP_ID, key: self::MCP_AUTH_MODE, value: $authMode);

            if ($authMode === 'apikey') {
                $this->appConfig->deleteKey(app: Application::APP_ID, key: self::MCP_OAUTH_CLIENT_ID);
                $this->appConfig->deleteKey(app: Application::APP_ID, key: self::MCP_OAUTH_SECRET);
            } else {
                $this->appConfig->deleteKey(app: Application::APP_ID, key: self::MCP_API_KEY);
            }
        }

        if (isset($config['apiKey']) === true && $config['apiKey'] !== self::PLACEHOLDER && $config['apiKey'] !== '') {
            $this->appConfig->setValueString(
                app: Application::APP_ID,
                key: self::MCP_API_KEY,
                value: (string) $config['apiKey'],
                sensitive: true,
            );
        }

        if (isset($config['oauthClientId']) === true) {
            $this->appConfig->setValueString(app: Application::APP_ID, key: self::MCP_OAUTH_CLIENT_ID, value: (string) $config['oauthClientId']);
        }

        if (isset($config['oauthClientSecret']) === true && $config['oauthClientSecret'] !== self::PLACEHOLDER && $config['oauthClientSecret'] !== '') {
            $this->appConfig->setValueString(
                app: Application::APP_ID,
                key: self::MCP_OAUTH_SECRET,
                value: (string) $config['oauthClientSecret'],
                sensitive: true,
            );
        }

    }//end saveMcpConfig()


    /**
     * Returns non-sensitive MCP configuration fields (no secrets).
     *
     * @return array{endpoint: string, authMode: string, oauthClientId: string, hasApiKey: bool, hasOAuthSecret: bool}
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-2.1
     */
    public function getMcpConfig(): array
    {
        $hasApiKey      = $this->appConfig->getValueString(app: Application::APP_ID, key: self::MCP_API_KEY, default: '') !== '';
        $hasOAuthSecret = $this->appConfig->getValueString(app: Application::APP_ID, key: self::MCP_OAUTH_SECRET, default: '') !== '';

        return [
            'endpoint'       => $this->appConfig->getValueString(app: Application::APP_ID, key: self::MCP_ENDPOINT, default: ''),
            'authMode'       => $this->appConfig->getValueString(app: Application::APP_ID, key: self::MCP_AUTH_MODE, default: 'apikey'),
            'oauthClientId'  => $this->appConfig->getValueString(app: Application::APP_ID, key: self::MCP_OAUTH_CLIENT_ID, default: ''),
            'hasApiKey'      => $hasApiKey,
            'hasOAuthSecret' => $hasOAuthSecret,
        ];

    }//end getMcpConfig()


}//end class
