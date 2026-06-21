<?php

/**
 * Pipelinq ApiAuthService.
 *
 * Service for managing REST API token authentication and OAuth 2.0 / MCP configuration.
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
 * @spec openspec/changes/admin-settings/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * Manages REST API tokens, OAuth 2.0 config, and MCP server configuration.
 *
 * Tokens are 256-bit random values stored only as SHA-256 hashes (one-time display).
 * Secrets are stored with sensitive=true in IAppConfig and never returned to the frontend.
 *
 * @spec openspec/changes/admin-settings/tasks.md#task-2
 */
class ApiAuthService
{

    private const TOKEN_KEY_PREFIX   = 'api_token_';
    private const SECRET_PLACEHOLDER = '••••••••';

    /**
     * Constructor.
     *
     * @param IAppConfig      $appConfig    The app config.
     * @param ISecureRandom   $secureRandom The secure random generator.
     * @param LoggerInterface $logger       The logger.
     */
    public function __construct(
        private IAppConfig $appConfig,
        private ISecureRandom $secureRandom,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Generate a new API token with the given label.
     *
     * The plaintext token is returned ONCE and never stored.
     * Only the SHA-256 hash is persisted in IAppConfig.
     *
     * @param string $label Human-readable label for this token.
     *
     * @return array{id: string, token: string, label: string, created: string}
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-2.1
     */
    public function generateToken(string $label): array
    {
        $id        = $this->generateUuid();
        $plaintext = $this->secureRandom->generate(length: 64, characters: ISecureRandom::CHAR_ALPHANUMERIC);
        $hash      = hash(algo: 'sha256', data: $plaintext);
        $created   = date(format: 'c');

        $tokenData = [
            'id'       => $id,
            'label'    => $label,
            'hash'     => $hash,
            'created'  => $created,
            'lastUsed' => null,
        ];

        $this->appConfig->setValueString(
            app: Application::APP_ID,
            key: self::TOKEN_KEY_PREFIX.$id,
            value: json_encode($tokenData),
            sensitive: true
        );

        $this->logger->info('ApiAuthService: token generated', ['id' => $id, 'label' => $label]);

        return [
            'id'      => $id,
            'token'   => $plaintext,
            'label'   => $label,
            'created' => $created,
        ];

    }//end generateToken()

    /**
     * List all API tokens (metadata only — no hashes returned).
     *
     * @return array<int, array{id: string, label: string, created: string, lastUsed: string|null}>
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-2.1
     */
    public function listTokens(): array
    {
        $tokens = [];
        $keys   = $this->appConfig->getKeys(Application::APP_ID);

        foreach ($keys as $key) {
            if (str_starts_with(haystack: $key, needle: self::TOKEN_KEY_PREFIX) === false) {
                continue;
            }

            $raw  = $this->appConfig->getValueString(Application::APP_ID, $key, '');
            $data = json_decode(json: $raw, associative: true);

            if (is_array($data) === false) {
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
     * Revoke (delete) a token by its ID.
     *
     * @param string $id The token UUID.
     *
     * @return void
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-2.1
     */
    public function revokeToken(string $id): void
    {
        $this->appConfig->deleteKey(
            app: Application::APP_ID,
            key: self::TOKEN_KEY_PREFIX.$id
        );

        $this->logger->info('ApiAuthService: token revoked', ['id' => $id]);

    }//end revokeToken()

    /**
     * Validate a plaintext token by comparing its SHA-256 hash against stored tokens.
     *
     * Also updates the lastUsed timestamp when a match is found.
     *
     * @param string $plaintext The plaintext token to validate.
     *
     * @return bool True if the token is valid.
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-2.1
     */
    public function validateToken(string $plaintext): bool
    {
        $hash = hash(algo: 'sha256', data: $plaintext);
        $keys = $this->appConfig->getKeys(Application::APP_ID);

        foreach ($keys as $key) {
            if (str_starts_with(haystack: $key, needle: self::TOKEN_KEY_PREFIX) === false) {
                continue;
            }

            $raw  = $this->appConfig->getValueString(Application::APP_ID, $key, '');
            $data = json_decode(json: $raw, associative: true);

            if (is_array($data) === false) {
                continue;
            }

            if (hash_equals(known_string: $data['hash'], user_string: $hash) === true) {
                $data['lastUsed'] = date(format: 'c');
                $this->appConfig->setValueString(
                    app: Application::APP_ID,
                    key: $key,
                    value: json_encode($data),
                    sensitive: true
                );

                return true;
            }
        }//end foreach

        return false;

    }//end validateToken()

    /**
     * Save OAuth 2.0 configuration to IAppConfig.
     *
     * The client secret is skipped when the submitted value equals the placeholder string
     * to prevent overwriting the stored secret with the masked UI display value.
     *
     * @param array $config OAuth config fields.
     *
     * @return void
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-2.1
     */
    public function saveOAuthConfig(array $config): void
    {
        $nonSensitiveFields = ['oauth_client_id', 'oauth_token_endpoint', 'oauth_auth_endpoint', 'oauth_scopes', 'oauth_id_token_forwarding'];
        foreach ($nonSensitiveFields as $field) {
            if (array_key_exists(key: $field, array: $config) === true) {
                $this->appConfig->setValueString(
                    app: Application::APP_ID,
                    key: $field,
                    value: (string) $config[$field]
                );
            }
        }

        if (array_key_exists(key: 'oauth_client_secret', array: $config) === true
            && $config['oauth_client_secret'] !== self::SECRET_PLACEHOLDER
            && $config['oauth_client_secret'] !== ''
        ) {
            $this->appConfig->setValueString(
                app: Application::APP_ID,
                key: 'oauth_client_secret',
                value: $config['oauth_client_secret'],
                sensitive: true
            );
        }

        $this->logger->info('ApiAuthService: OAuth config saved');

    }//end saveOAuthConfig()

    /**
     * Get OAuth 2.0 configuration (non-sensitive fields only — client secret excluded).
     *
     * @return array OAuth config without the client secret.
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-2.1
     */
    public function getOAuthConfig(): array
    {
        return [
            'oauth_client_id'           => $this->appConfig->getValueString(Application::APP_ID, 'oauth_client_id', ''),
            'oauth_token_endpoint'      => $this->appConfig->getValueString(Application::APP_ID, 'oauth_token_endpoint', ''),
            'oauth_auth_endpoint'       => $this->appConfig->getValueString(Application::APP_ID, 'oauth_auth_endpoint', ''),
            'oauth_scopes'              => $this->appConfig->getValueString(Application::APP_ID, 'oauth_scopes', ''),
            'oauth_id_token_forwarding' => $this->appConfig->getValueString(Application::APP_ID, 'oauth_id_token_forwarding', 'false'),
            'oauth_secret_configured'   => $this->appConfig->getValueString(Application::APP_ID, 'oauth_client_secret', '') !== '',
        ];

    }//end getOAuthConfig()

    /**
     * Generate a v4-style UUID using ISecureRandom.
     *
     * @return string UUID string in xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx format.
     */
    private function generateUuid(): string
    {
        $hex = $this->secureRandom->generate(length: 32, characters: ISecureRandom::CHAR_LOWER.'0123456789');

        return sprintf(
            '%s-%s-4%s-%s%s-%s',
            substr(string: $hex, offset: 0, length: 8),
            substr(string: $hex, offset: 8, length: 4),
            substr(string: $hex, offset: 13, length: 3),
            dechex(num: (hexdec(hex_string: substr(string: $hex, offset: 16, length: 1)) & 0x3) | 0x8),
            substr(string: $hex, offset: 17, length: 3),
            substr(string: $hex, offset: 20, length: 12)
        );

    }//end generateUuid()
}//end class
