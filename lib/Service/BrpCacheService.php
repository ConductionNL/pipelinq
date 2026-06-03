<?php

/**
 * Pipelinq BrpCacheService.
 *
 * Persists and serves BRP person records with a configurable TTL (default 24h,
 * AVG art. 5 storage limitation). Cache entries ARE the brpPersoon records, so
 * the data-at-rest, retention and right-to-erasure flows act on one store. The
 * BSN is only ever matched by its masked form (ADR-005).
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
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Bsn\BrpPersoon;
use OCA\Pipelinq\Service\Bsn\BsnMasker;
use OCA\Pipelinq\Service\Bsn\BsnObjectStoreTrait;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service caching BRP responses with TTL (REQ-BSN-004).
 *
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.3
 */
class BrpCacheService
{
    use BsnObjectStoreTrait;

    /**
     * Schema config key for the cached person record.
     *
     * @var string
     */
    private const SCHEMA_KEY = 'brpPersoon_schema';

    /**
     * App-config key for the cache TTL in hours.
     *
     * @var string
     */
    private const TTL_KEY = 'brp.cache_ttl_hours';

    /**
     * Default cache TTL in hours.
     *
     * @var int
     */
    private const DEFAULT_TTL = 24;

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
     * Configured cache TTL in hours (clamped to a sane maximum of 24h).
     *
     * @return int The TTL in hours.
     */
    private function ttlHours(): int
    {
        $hours = (int) $this->appConfig->getValueString(Application::APP_ID, self::TTL_KEY, (string) self::DEFAULT_TTL);
        if ($hours <= 0 || $hours > self::DEFAULT_TTL) {
            return self::DEFAULT_TTL;
        }

        return $hours;
    }//end ttlHours()

    /**
     * Return the freshest non-expired cached record for a BSN, or null.
     *
     * @param string $bsn The raw BSN (masked internally; never logged).
     *
     * @return array<string, mixed>|null The cached brpPersoon record, or null.
     *
     * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.3
     */
    public function get(string $bsn): ?array
    {
        $masked = BsnMasker::mask($bsn);
        $now    = $this->now();
        $best   = null;

        foreach ($this->findAllBy(schemaKey: self::SCHEMA_KEY, filters: ['bsnGemaskeerd' => $masked]) as $record) {
            $retentie = (string) ($record['retentieTot'] ?? '');
            if ($retentie === '' || $retentie <= $now) {
                continue;
            }

            $opgehaald = (string) ($record['opgehaaldOp'] ?? '');
            if ($best === null || $opgehaald > (string) ($best['opgehaaldOp'] ?? '')) {
                $best = $record;
            }
        }

        return $best;
    }//end get()

    /**
     * Store (or refresh) a BRP person as a cache entry.
     *
     * Reuses an existing record's UUID for the BSN+contact pair so a refresh
     * overwrites in place (stable id) rather than accumulating duplicates.
     *
     * @param BrpPersoon $person          The normalised person.
     * @param string     $bsn             The raw BSN (masked when stored).
     * @param string     $lookupVerzoekId The originating lookup-verzoek id.
     * @param string     $contactId       The linked contact id.
     *
     * @return array<string, mixed> The saved record (incl. id, opgehaaldOp, retentieTot).
     *
     * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.3
     */
    public function set(BrpPersoon $person, string $bsn, string $lookupVerzoekId, string $contactId): array
    {
        $now      = $this->now();
        $retentie = (new DateTimeImmutable())->modify('+'.$this->ttlHours().' hours')->format(DateTimeInterface::ATOM);

        $record = array_merge(
            $person->toArray(),
            [
                'opgehaaldOp'      => $now,
                'retentieTot'      => $retentie,
                'lookupVerzoekId'  => $lookupVerzoekId,
                'gekoppeldContact' => $contactId,
            ]
        );

        $existing = $this->get(bsn: $bsn);
        $uuid     = null;
        if ($existing !== null && (string) ($existing['gekoppeldContact'] ?? '') === $contactId) {
            $uuid = (string) ($existing['id'] ?? $existing['uuid'] ?? '');
            if ($uuid === '') {
                $uuid = null;
            }
        }

        return $this->save(schemaKey: self::SCHEMA_KEY, object: $record, uuid: $uuid);
    }//end set()

    /**
     * Invalidate every cached entry for a BSN (webhook-driven, REQ-BSN-004-03).
     *
     * Expires entries in place (sets retentieTot to now) so the next lookup is
     * forced to re-query, while the historical record is left for the retention
     * job to remove.
     *
     * @param string $bsn The raw BSN.
     *
     * @return int The number of entries invalidated.
     *
     * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.3
     */
    public function invalidate(string $bsn): int
    {
        $masked = BsnMasker::mask($bsn);
        $now    = $this->now();
        $count  = 0;

        foreach ($this->findAllBy(schemaKey: self::SCHEMA_KEY, filters: ['bsnGemaskeerd' => $masked]) as $record) {
            $uuid = (string) ($record['id'] ?? $record['uuid'] ?? '');
            if ($uuid === '') {
                continue;
            }

            $record['retentieTot'] = $now;
            $this->save(schemaKey: self::SCHEMA_KEY, object: $record, uuid: $uuid);
            $count++;
        }

        return $count;
    }//end invalidate()

    /**
     * Current time as an ISO 8601 string.
     *
     * @return string The timestamp.
     */
    private function now(): string
    {
        return (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
    }//end now()
}//end class
