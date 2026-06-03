<?php

/**
 * Pipelinq BrpMonitorService.
 *
 * Aggregates the immutable BSN audit trail into a 24-hour BRP service report:
 * lookup count, cache-hit ratio, average response time and error rate
 * (REQ-BSN-010). Pure aggregation over already-masked audit records — no BSN is
 * read or exposed.
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
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#3.4
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Service\Bsn\BsnObjectStoreTrait;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Aggregates BRP audit records into a service-health report (REQ-BSN-010).
 *
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#3.4
 */
class BrpMonitorService
{
    use BsnObjectStoreTrait;

    /**
     * Schema config key for the audit record.
     *
     * @var string
     */
    private const SCHEMA_KEY = 'bsnAuditRecord_schema';

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
     * Aggregate audit records at or after a cut-off into a report.
     *
     * Separated from persistence so it is exercised in isolation by the tests
     * with a plain array of records.
     *
     * @param array<int, array<string, mixed>> $records The audit records.
     *
     * @return array<string, mixed> The report (lookups, cacheHitRatio,
     *                               errorRate, avgResponseMs, refusals).
     *
     * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#3.4
     */
    public function aggregate(array $records): array
    {
        $lookups   = 0;
        $cacheHits = 0;
        $errors    = 0;
        $refusals  = 0;
        $durations = [];

        foreach ($records as $record) {
            $actie = (string) ($record['actie'] ?? '');
            if ($actie === 'brp-lookup-geweigerd' || (string) ($record['uitkomst'] ?? '') === 'geweigerd-onbevoegd') {
                $refusals++;
                continue;
            }

            $lookups++;
            $uitkomst = (string) ($record['uitkomst'] ?? '');
            if ($uitkomst === 'fout') {
                $errors++;
            }

            if (($record['responseInCache'] ?? false) === true) {
                $cacheHits++;
            }

            if (isset($record['responseDuurMs']) === true) {
                $durations[] = (int) $record['responseDuurMs'];
            }
        }//end foreach

        $avg = 0.0;
        if (count($durations) > 0) {
            $avg = round(array_sum($durations) / count($durations), 1);
        }

        $cacheHitRatio = 0.0;
        $errorRate     = 0.0;
        if ($lookups > 0) {
            $cacheHitRatio = round(($cacheHits / $lookups) * 100, 1);
            $errorRate     = round(($errors / $lookups) * 100, 1);
        }

        return [
            'lookups'       => $lookups,
            'cacheHitRatio' => $cacheHitRatio,
            'errorRate'     => $errorRate,
            'avgResponseMs' => $avg,
            'refusals'      => $refusals,
        ];
    }//end aggregate()

    /**
     * Build the report for all audit records at or after a cut-off timestamp.
     *
     * @param string $since ISO 8601 lower bound (e.g. now − 24h).
     *
     * @return array<string, mixed> The aggregated report.
     *
     * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#3.4
     */
    public function report(string $since): array
    {
        $records = array_filter(
            $this->findAllBy(schemaKey: self::SCHEMA_KEY),
            static fn (array $row): bool => (string) ($row['tijdstip'] ?? '') >= $since
        );

        return $this->aggregate(records: array_values($records));
    }//end report()
}//end class
