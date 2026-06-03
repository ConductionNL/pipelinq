<?php

/**
 * Pipelinq StufCredentialResolver.
 *
 * Resolves vault-referenced StUF secrets (WSSE password, mutual-TLS client cert)
 * at send time so credentials never live inline in the StufEndpoint object.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Stuf
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/stuf-zkn-bg-adapter/tasks.md#2.3
 * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#req-stuf-011
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Stuf;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Exception\StufTransportException;
use OCP\IAppConfig;
use OCP\Security\ICredentialsManager;
use Psr\Log\LoggerInterface;

/**
 * Resolves vault references (vault://path) to secret values at send time.
 *
 * Secret material is stored encrypted via Nextcloud's ICredentialsManager keyed
 * by the vault path. Endpoint objects only carry the *reference*; the plaintext
 * is fetched here and never persisted to the audit log or the StufEndpoint.
 * ADR-005: credentials are never hardcoded and never disabled.
 */
class StufCredentialResolver
{
    /**
     * Prefix marking a vault reference value.
     *
     * @var string
     */
    private const VAULT_PREFIX = 'vault://';

    /**
     * Constructor.
     *
     * @param ICredentialsManager $credentialsManager The encrypted credential store.
     * @param IAppConfig          $appConfig          The app config (fallback store).
     * @param LoggerInterface     $logger             The logger.
     */
    public function __construct(
        private ICredentialsManager $credentialsManager,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Resolve a vault reference to its secret value.
     *
     * Resolution order:
     *  1. The encrypted ICredentialsManager entry keyed by the vault path.
     *  2. An IAppConfig sensitive value (legacy/bootstrap), keyed by the path.
     *
     * @param string $reference The vault reference (vault://...) or empty string.
     *
     * @return string|null The resolved secret, or null when no value is stored.
     *
     * @throws StufTransportException If the reference is malformed.
     */
    public function resolve(string $reference): ?string
    {
        if ($reference === '') {
            return null;
        }

        if (str_starts_with($reference, self::VAULT_PREFIX) === false) {
            throw new StufTransportException(message: 'Invalid vault reference (expected vault:// prefix).');
        }

        $path = substr($reference, strlen(self::VAULT_PREFIX));
        if ($path === '') {
            throw new StufTransportException(message: 'Empty vault reference path.');
        }

        $stored = $this->credentialsManager->retrieve('', self::credentialKey(path: $path));
        if (is_string($stored) === true && $stored !== '') {
            return $stored;
        }

        $fallback = $this->appConfig->getValueString(
            Application::APP_ID,
            'stuf.secret.'.$path,
            ''
        );
        if ($fallback !== '') {
            return $fallback;
        }

        $this->logger->warning(
            'StUF credential not found in vault',
            ['app' => Application::APP_ID, 'reference' => $reference]
        );

        return null;
    }//end resolve()

    /**
     * Resolve a required vault reference, raising if no secret is available.
     *
     * @param string $reference The vault reference (vault://...).
     * @param string $purpose   Human-readable purpose for the error message.
     *
     * @return string The resolved secret.
     *
     * @throws StufTransportException If the reference resolves to no value.
     */
    public function resolveRequired(string $reference, string $purpose): string
    {
        $value = $this->resolve(reference: $reference);
        if ($value === null) {
            throw new StufTransportException(message: 'Unable to resolve required '.$purpose.' from vault.');
        }

        return $value;
    }//end resolveRequired()

    /**
     * Store a secret under a vault path (used by the admin config flow).
     *
     * @param string $reference The vault reference (vault://...).
     * @param string $secret    The plaintext secret to store encrypted.
     *
     * @return void
     *
     * @throws StufTransportException If the reference is malformed.
     */
    public function store(string $reference, string $secret): void
    {
        if (str_starts_with($reference, self::VAULT_PREFIX) === false) {
            throw new StufTransportException(message: 'Invalid vault reference (expected vault:// prefix).');
        }

        $path = substr($reference, strlen(self::VAULT_PREFIX));
        if ($path === '') {
            throw new StufTransportException(message: 'Empty vault reference path.');
        }

        $this->credentialsManager->store('', self::credentialKey(path: $path), $secret);
    }//end store()

    /**
     * Build the namespaced ICredentialsManager identifier for a vault path.
     *
     * @param string $path The vault path (reference without the prefix).
     *
     * @return string The credential identifier.
     */
    private static function credentialKey(string $path): string
    {
        return Application::APP_ID.'.stuf.'.$path;
    }//end credentialKey()
}//end class
