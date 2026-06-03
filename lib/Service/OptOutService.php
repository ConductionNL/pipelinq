<?php

/**
 * Pipelinq OptOutService.
 *
 * Manages the per-BSN secrecy / opt-out flags (Wet BRP art. 2.57). Flags are
 * matched by the MASKED BSN only — no plain-text BSN is stored or queried
 * (ADR-005). A municipal secrecy indication from a BRP response is mirrored into
 * an OptOutVlag so downstream contact views and export guards can honour it.
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
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.5
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use OCA\Pipelinq\Service\Bsn\BrpPersoon;
use OCA\Pipelinq\Service\Bsn\BsnMasker;
use OCA\Pipelinq\Service\Bsn\BsnObjectStoreTrait;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for BRP/local opt-out flags (REQ-BSN-006).
 *
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.5
 */
class OptOutService
{
    use BsnObjectStoreTrait;

    /**
     * Schema config key for the opt-out flag.
     *
     * @var string
     */
    private const SCHEMA_KEY = 'optOutVlag_schema';

    /**
     * Constructor.
     *
     * @param ContainerInterface $container The DI container (OR ObjectService).
     * @param IAppConfig         $appConfig The app config.
     * @param LoggerInterface    $logger    The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the active opt-out flag for a BSN, if any.
     *
     * A flag is active when its ingangsdatum has passed and its einddatum (when
     * set) has not. Matching is by masked BSN.
     *
     * @param string $bsn The raw BSN (masked internally; never logged).
     *
     * @return array<string, mixed>|null The active flag, or null.
     *
     * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.5
     */
    public function getOptOut(string $bsn): ?array
    {
        $masked = BsnMasker::mask($bsn);
        $now    = (new DateTimeImmutable())->format('Y-m-d');

        foreach ($this->findAllBy(schemaKey: self::SCHEMA_KEY, filters: ['bsnGemaskeerd' => $masked]) as $flag) {
            $start = (string) ($flag['ingangsdatum'] ?? '');
            $end   = (string) ($flag['einddatum'] ?? '');
            if ($start !== '' && $start > $now) {
                continue;
            }

            if ($end !== '' && $end < $now) {
                continue;
            }

            return $flag;
        }

        return null;
    }//end getOptOut()

    /**
     * Whether an active opt-out exists for a BSN.
     *
     * @param string $bsn The raw BSN.
     *
     * @return bool True when an active flag exists.
     *
     * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.5
     */
    public function hasOptOut(string $bsn): bool
    {
        return $this->getOptOut(bsn: $bsn) !== null;
    }//end hasOptOut()

    /**
     * Mirror a BRP secrecy indication into an OptOutVlag.
     *
     * Creates a `geheimhouding-brp` flag when the person carries indicatieGeheim
     * = "1" and no active municipal/BRP flag exists yet. Idempotent.
     *
     * @param BrpPersoon $person The looked-up person.
     * @param string     $bsn    The raw BSN (masked when stored).
     *
     * @return void
     *
     * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.5
     */
    public function recordFromBrpResponse(BrpPersoon $person, string $bsn): void
    {
        if ($person->heeftGeheimhouding() === false) {
            return;
        }

        $existing = $this->getOptOut(bsn: $bsn);
        if ($existing !== null
            && in_array((string) ($existing['type'] ?? ''), ['geheimhouding-brp', 'geheimhouding-gemeente'], true) === true
        ) {
            return;
        }

        $this->save(
            schemaKey: self::SCHEMA_KEY,
            object: [
                'bsnGemaskeerd' => BsnMasker::mask($bsn),
                'type'          => 'geheimhouding-brp',
                'bron'          => 'BRP',
                'ingangsdatum'  => (new DateTimeImmutable())->format('Y-m-d'),
            ]
        );
    }//end recordFromBrpResponse()

    /**
     * Remove every opt-out flag for a BSN (AVG art. 17 erasure).
     *
     * @param string $bsn The raw BSN.
     *
     * @return int The number of flags removed.
     *
     * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.5
     */
    public function removeForBsn(string $bsn): int
    {
        $masked  = BsnMasker::mask($bsn);
        $removed = 0;
        foreach ($this->findAllBy(schemaKey: self::SCHEMA_KEY, filters: ['bsnGemaskeerd' => $masked]) as $flag) {
            $uuid = (string) ($flag['id'] ?? $flag['uuid'] ?? '');
            if ($uuid === '') {
                continue;
            }

            $this->delete(schemaKey: self::SCHEMA_KEY, uuid: $uuid);
            $removed++;
        }

        return $removed;
    }//end removeForBsn()
}//end class
