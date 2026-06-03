<?php

/**
 * Pipelinq MailboxResolver.
 *
 * Caching layer for BSN to MijnOverheid-mailbox-availability lookups. Results
 * are cached as mailboxResolution objects keyed by the keyed BSN hash (never
 * plaintext BSN) with a 24-hour TTL per the Logius SLA. On a cache miss the
 * resolver calls the LogiusConnector mailbox-check endpoint and stores the
 * result. The opt-out flag is honoured separately from mailbox availability.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#REQ-MAILBOX-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Resolves and caches BSN to mailbox-availability lookups.
 *
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#REQ-MAILBOX-002
 */
class MailboxResolver
{
    /**
     * Cache TTL in seconds (24 hours).
     *
     * @var int
     */
    public const TTL_SECONDS = 86400;

    /**
     * Constructor.
     *
     * @param ContainerInterface $container         The DI container.
     * @param IAppConfig         $appConfig         The app config.
     * @param EncryptionService  $encryptionService The BSN crypto service.
     * @param LogiusConnector    $logiusConnector   The Logius connector.
     * @param LoggerInterface    $logger            The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private EncryptionService $encryptionService,
        private LogiusConnector $logiusConnector,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Resolve mailbox availability for a BSN, using the cache when fresh.
     *
     * @param string $bsn The plaintext BSN (never logged).
     *
     * @return array{mailboxAvailable: bool, optedOut: bool, cached: bool} The resolution.
     */
    public function resolve(string $bsn): array
    {
        $bsnHash = $this->encryptionService->hashBsn($bsn);

        $cached = $this->findFreshCache(bsnHash: $bsnHash);
        if ($cached !== null) {
            return [
                'mailboxAvailable' => (bool) ($cached['mailboxAvailable'] ?? false),
                'optedOut'         => (bool) ($cached['optedOut'] ?? false),
                'cached'           => true,
            ];
        }

        $available = false;
        try {
            $available = $this->logiusConnector->checkMailboxExists($bsn);
        } catch (Throwable $e) {
            // On a lookup error, treat as no mailbox so dispatch falls back to email.
            $this->logger->warning(
                'Berichtenbox: mailbox lookup failed, treating as unavailable',
                ['exception' => $e->getMessage()]
            );
        }

        $this->storeCache(bsn: $bsn, bsnHash: $bsnHash, available: $available);

        return [
            'mailboxAvailable' => $available,
            'optedOut'         => false,
            'cached'           => false,
        ];
    }//end resolve()

    /**
     * Find a non-expired cache row for a BSN hash.
     *
     * @param string $bsnHash The keyed BSN hash.
     *
     * @return array<string, mixed>|null The cached row, or null when absent/expired.
     */
    private function findFreshCache(string $bsnHash): ?array
    {
        try {
            [$register, $schema] = $this->config();
            $results = $this->getObjectService()->findAll(
                config: [
                    'filters' => [
                        'register' => $register,
                        'schema'   => $schema,
                        'bsnHash'  => $bsnHash,
                    ],
                ]
            );
        } catch (Throwable $e) {
            $this->logger->warning('Berichtenbox: mailbox cache read failed', ['exception' => $e->getMessage()]);
            return null;
        }

        $now = new DateTimeImmutable();
        foreach (($results ?? []) as $result) {
            $row       = $this->toArray(object: $result);
            $expiresAt = (string) ($row['expiresAt'] ?? '');
            if ($expiresAt === '') {
                continue;
            }

            try {
                if (new DateTimeImmutable($expiresAt) > $now) {
                    return $row;
                }
            } catch (Throwable $e) {
                continue;
            }
        }

        return null;
    }//end findFreshCache()

    /**
     * Store a resolution result in the cache.
     *
     * @param string $bsn       The plaintext BSN (encrypted before storage).
     * @param string $bsnHash   The keyed BSN hash.
     * @param bool   $available Whether a mailbox exists.
     *
     * @return void
     */
    private function storeCache(string $bsn, string $bsnHash, bool $available): void
    {
        $now = new DateTimeImmutable();

        $row = [
            'bsn'              => $this->encryptionService->encrypt($bsn),
            'bsnHash'          => $bsnHash,
            'mailboxAvailable' => $available,
            'resolvedAt'       => $now->format(DateTimeInterface::ATOM),
            'expiresAt'        => $now->modify('+'.self::TTL_SECONDS.' seconds')->format(DateTimeInterface::ATOM),
            'optedOut'         => false,
        ];

        try {
            [$register, $schema] = $this->config();
            $this->getObjectService()->saveObject(object: $row, extend: [], register: $register, schema: $schema, uuid: null);
        } catch (Throwable $e) {
            $this->logger->warning('Berichtenbox: mailbox cache write failed', ['exception' => $e->getMessage()]);
        }
    }//end storeCache()

    /**
     * Resolve the register + mailboxResolution schema IDs.
     *
     * @return array{0: string, 1: string} The [register, schema] tuple.
     *
     * @throws RuntimeException When not configured.
     */
    private function config(): array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'mailboxResolution_schema', '');
        if ($register === '' || $schema === '') {
            throw new RuntimeException('Berichtenbox mailbox resolution register or schema not configured.');
        }

        return [$register, $schema];
    }//end config()

    /**
     * Get the OpenRegister ObjectService.
     *
     * @return object The ObjectService.
     *
     * @throws RuntimeException When OpenRegister is unavailable.
     */
    private function getObjectService(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (Throwable $e) {
            throw new RuntimeException('OpenRegister service is not available.');
        }
    }//end getObjectService()

    /**
     * Normalise an OR object into a plain array.
     *
     * @param mixed $object The OR object.
     *
     * @return array<string, mixed> The object as an array.
     */
    private function toArray(mixed $object): array
    {
        if (is_array($object) === true) {
            return $object;
        }

        if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
            $serialized = $object->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }
        }

        return (array) $object;
    }//end toArray()
}//end class
