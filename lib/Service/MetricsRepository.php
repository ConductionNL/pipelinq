<?php

/**
 * Pipelinq MetricsRepository.
 *
 * Database queries for Prometheus metrics collection.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-54
 * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-56
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Repository for Pipelinq metrics data queries.
 */
class MetricsRepository
{
    /**
     * Constructor.
     *
     * @param IDBConnection   $db        Database connection.
     * @param LoggerInterface $logger    Logger.
     * @param IAppConfig      $appConfig App config for schema ID lookup.
     */
    public function __construct(
        private IDBConnection $db,
        private LoggerInterface $logger,
        private IAppConfig $appConfig,
    ) {
    }//end __construct()

    /**
     * Get lead counts grouped by status and pipeline.
     *
     * Queries OpenRegister objects table using the configured lead schema ID
     * to scope results to this app's data only (avoids cross-tenant leakage).
     * Returns raw rows; JSON field extraction is done in PHP to remain
     * portable across MySQL and PostgreSQL.
     *
     * @return array<array{status: string, pipeline: string, cnt: int}> Grouped counts.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-54
     */
    public function getLeadCounts(): array
    {
        $schemaId = $this->appConfig->getValueString(Application::APP_ID, 'lead_schema', '');
        if ($schemaId === '') {
            return [];
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('o.object')
                ->from('openregister_objects', 'o')
                ->where($qb->expr()->eq('o.schema', $qb->createNamedParameter($schemaId)));

            $result = $qb->executeQuery();
            $rows   = $result->fetchAll();
            $result->closeCursor();

            // Aggregate in PHP for DB-portability.
            $counts = [];
            foreach ($rows as $row) {
                $obj      = json_decode($row['object'] ?? '{}', true) ?? [];
                $status   = (string) ($obj['status'] ?? '');
                $pipeline = (string) ($obj['pipeline'] ?? '');
                $key      = $status.'|'.$pipeline;
                if (isset($counts[$key]) === false) {
                    $counts[$key] = ['status' => $status, 'pipeline' => $pipeline, 'cnt' => 0];
                }

                $counts[$key]['cnt']++;
            }

            return array_values($counts);
        } catch (\Exception $e) {
            $this->logger->warning(
                message: '[MetricsRepository] Failed to get lead counts',
                context: ['error' => $e->getMessage()]
            );
            return [];
        }//end try
    }//end getLeadCounts()

    /**
     * Get lead value totals grouped by pipeline.
     *
     * Queries using the configured lead schema ID and aggregates in PHP
     * to remain portable across MySQL and PostgreSQL.
     *
     * @return array<array{pipeline: string, total_value: float}> Pipeline values.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-56
     */
    public function getLeadValueByPipeline(): array
    {
        $schemaId = $this->appConfig->getValueString(Application::APP_ID, 'lead_schema', '');
        if ($schemaId === '') {
            return [];
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('o.object')
                ->from('openregister_objects', 'o')
                ->where($qb->expr()->eq('o.schema', $qb->createNamedParameter($schemaId)));

            $result = $qb->executeQuery();
            $rows   = $result->fetchAll();
            $result->closeCursor();

            // Aggregate in PHP for DB-portability.
            $totals = [];
            foreach ($rows as $row) {
                $obj      = json_decode($row['object'] ?? '{}', true) ?? [];
                $pipeline = (string) ($obj['pipeline'] ?? '');
                $value    = (float) ($obj['value'] ?? 0);
                if (isset($totals[$pipeline]) === false) {
                    $totals[$pipeline] = ['pipeline' => $pipeline, 'total_value' => 0.0];
                }

                $totals[$pipeline]['total_value'] += $value;
            }

            return array_values($totals);
        } catch (\Exception $e) {
            $this->logger->warning(
                message: '[MetricsRepository] Failed to get lead values',
                context: ['error' => $e->getMessage()]
            );
            return [];
        }//end try
    }//end getLeadValueByPipeline()

    /**
     * Count objects matching a schema title pattern.
     *
     * @param string $pattern SQL LIKE pattern for schema title.
     *
     * @return int Object count.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-54
     */
    public function countObjectsBySchemaPattern(string $pattern): int
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('o.id', 'cnt'))
                ->from('openregister_objects', 'o')
                ->innerJoin('o', 'openregister_schemas', 's', $qb->expr()->eq('o.schema', 's.id'))
                ->where($qb->expr()->like('s.title', $qb->createNamedParameter($pattern)));

            $result = $qb->executeQuery();
            $row    = $result->fetch();
            $result->closeCursor();

            return (int) ($row['cnt'] ?? 0);
        } catch (\Exception $e) {
            $this->logger->warning(
                message: '[MetricsRepository] Failed to count objects',
                context: ['error' => $e->getMessage()]
            );
            return 0;
        }//end try
    }//end countObjectsBySchemaPattern()

    /**
     * Get service request counts grouped by status.
     *
     * Queries using the configured request schema ID and aggregates in PHP
     * to remain portable across MySQL and PostgreSQL.
     *
     * @return array<array{status: string, cnt: int}> Grouped counts.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-54
     */
    public function getRequestCounts(): array
    {
        $schemaId = $this->appConfig->getValueString(Application::APP_ID, 'request_schema', '');
        if ($schemaId === '') {
            return [];
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('o.object')
                ->from('openregister_objects', 'o')
                ->where($qb->expr()->eq('o.schema', $qb->createNamedParameter($schemaId)));

            $result = $qb->executeQuery();
            $rows   = $result->fetchAll();
            $result->closeCursor();

            // Aggregate in PHP for DB-portability.
            $counts = [];
            foreach ($rows as $row) {
                $obj    = json_decode($row['object'] ?? '{}', true) ?? [];
                $status = (string) ($obj['status'] ?? '');
                if (isset($counts[$status]) === false) {
                    $counts[$status] = ['status' => $status, 'cnt' => 0];
                }

                $counts[$status]['cnt']++;
            }

            return array_values($counts);
        } catch (\Exception $e) {
            $this->logger->warning(
                message: '[MetricsRepository] Failed to get request counts',
                context: ['error' => $e->getMessage()]
            );
            return [];
        }//end try
    }//end getRequestCounts()
}//end class
