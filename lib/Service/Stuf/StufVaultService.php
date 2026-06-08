<?php

/**
 * Pipelinq StufVaultService.
 *
 * Resolves `vault://...` references to their plaintext secret. The current
 * implementation looks up the secret from IAppConfig under the
 * `pipelinq.stuf.vault.<sha256(reference)>` key — this keeps the actual
 * passwords/cert blobs out of git, while the JSON schemas only carry the
 * reference URL. A production install would plug a real vault driver via
 * the same interface (KMS, HashiCorp, NC encrypted credentials, etc.).
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
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-011
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Stuf;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Resolves vault references to plaintext secrets at send time.
 */
class StufVaultService
{
    /**
     * Constructor.
     *
     * @param IAppConfig      $appConfig The app config used as backing store.
     * @param LoggerInterface $logger    The logger.
     */
    public function __construct(
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Resolve a vault reference to its plaintext secret.
     *
     * Empty or missing references resolve to an empty string and emit an ERROR
     * log line. The empty case lets the envelope builder still produce a
     * well-formed XML document while the HTTP client refuses to send.
     *
     * @param string $reference The vault reference (vault://...).
     *
     * @return string The secret value (empty if unresolved).
     *
     * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-011
     */
    public function resolveSecret(string $reference): string
    {
        if ($reference === '') {
            $this->logger->error(message: 'StUF vault: empty reference, no credential available');
            return '';
        }

        $key   = 'stuf.vault.'.hash(algo: 'sha256', data: $reference);
        $value = $this->appConfig->getValueString(app: Application::APP_ID, key: $key, default: '');

        if ($value === '') {
            $this->logger->error(
                message: 'StUF vault: reference {ref} not present in app config',
                context: ['ref' => $this->maskReference(reference: $reference)]
            );
        }

        return $value;
    }//end resolveSecret()

    /**
     * Store a secret behind a vault reference (admin tooling / install seed).
     *
     * @param string $reference The vault reference (vault://...).
     * @param string $secret    The plaintext secret.
     *
     * @return void
     *
     * @throws RuntimeException When the reference is empty.
     *
     * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-011
     */
    public function storeSecret(string $reference, string $secret): void
    {
        if ($reference === '') {
            throw new RuntimeException(message: 'Vault reference cannot be empty');
        }

        $key = 'stuf.vault.'.hash(algo: 'sha256', data: $reference);
        $this->appConfig->setValueString(app: Application::APP_ID, key: $key, value: $secret, sensitive: true);
    }//end storeSecret()

    /**
     * Mask a vault reference for safe logging (keep scheme + first 12 chars).
     *
     * @param string $reference The reference to mask.
     *
     * @return string The masked reference.
     */
    private function maskReference(string $reference): string
    {
        if (strlen(string: $reference) <= 16) {
            return $reference;
        }

        return substr(string: $reference, offset: 0, length: 16).'…';
    }//end maskReference()
}//end class
