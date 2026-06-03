<?php

/**
 * Pipelinq ZgwSecretResolver.
 *
 * Resolves ZGW credential references (e.g. "vault://zgw/zoetermeer/client-secret")
 * to their plaintext secret. Secrets are NEVER stored in the OpenRegister
 * schema; only the reference is. The plaintext is held encrypted-at-rest in
 * Nextcloud app config via {@see ICrypto} (the same encryption primitive
 * OpenRegister itself uses for source credentials), so this reuses the
 * platform secret-storage abstraction rather than rolling a bespoke vault.
 *
 * A gemeente IT team provisions the secret once (via {@see self::store()} or the
 * admin settings flow); the bridge resolves it per request when minting JWTs or
 * verifying NRC callback bearer tokens.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#REQ-ZGW-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * Resolves and stores ZGW credential references encrypted-at-rest.
 *
 * @spec openspec/changes/zgw-api-bridge/tasks.md#2.1
 */
class ZgwSecretResolver
{
    /**
     * App-config key prefix under which encrypted ZGW secrets are stored.
     *
     * @var string
     */
    private const SECRET_KEY_PREFIX = 'zgw.secret.';

    /**
     * Constructor.
     *
     * @param IAppConfig      $appConfig The app config (encrypted-secret storage).
     * @param ICrypto         $crypto    The Nextcloud authenticated-encryption primitive.
     * @param LoggerInterface $logger    The logger.
     */
    public function __construct(
        private IAppConfig $appConfig,
        private ICrypto $crypto,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Derive the stable app-config key for a credential reference.
     *
     * @param string $reference The vault-style reference (e.g. vault://zgw/...).
     *
     * @return string The app-config key.
     */
    private function configKeyFor(string $reference): string
    {
        return self::SECRET_KEY_PREFIX.sha1($reference);
    }//end configKeyFor()

    /**
     * Store a secret for a reference, encrypted-at-rest.
     *
     * @param string $reference The vault-style reference.
     * @param string $secret    The plaintext secret to encrypt and persist.
     *
     * @return void
     *
     * @spec openspec/changes/zgw-api-bridge/tasks.md#9.2
     */
    public function store(string $reference, string $secret): void
    {
        if ($reference === '') {
            return;
        }

        $this->appConfig->setValueString(
            Application::APP_ID,
            $this->configKeyFor(reference: $reference),
            $this->crypto->encrypt($secret),
            false,
            true
        );
    }//end store()

    /**
     * Resolve a credential reference to its plaintext secret.
     *
     * Returns null when the reference is empty or no secret has been provisioned
     * for it, so callers can raise a clear configuration error rather than
     * sending an unauthenticated request.
     *
     * @param string $reference The vault-style reference (e.g. vault://zgw/...).
     *
     * @return string|null The plaintext secret, or null when unavailable.
     *
     * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#REQ-ZGW-001
     */
    public function resolve(string $reference): ?string
    {
        if ($reference === '') {
            return null;
        }

        $stored = $this->appConfig->getValueString(
            Application::APP_ID,
            $this->configKeyFor(reference: $reference),
            ''
        );

        if ($stored === '') {
            $this->logger->warning(
                'ZgwSecretResolver: no secret provisioned for reference',
                ['reference' => $reference]
            );
            return null;
        }

        try {
            return $this->crypto->decrypt($stored);
        } catch (\Throwable $e) {
            $this->logger->error(
                'ZgwSecretResolver: failed to decrypt secret',
                ['reference' => $reference, 'exception' => $e->getMessage()]
            );
            return null;
        }
    }//end resolve()
}//end class
