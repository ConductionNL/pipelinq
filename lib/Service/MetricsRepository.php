<?php

/**
 * Pipelinq MetricsRepository.
 *
 * Database queries for Prometheus metrics collection.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

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
     * @param IDBConnection   $db     Database connection.
     * @param LoggerInterface $logger Logger.
     */
    public function __construct(
        private IDBConnection $db,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get lead counts grouped by status and pipeline.
     *
     * @return array<array{status: string, pipeline: string, cnt: string}> Grouped counts.
     */
    public function getLeadCounts(): array
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select(
                $qb->createFunction("JSON_UNQUOTE(JSON_EXTRACT(o.object, '$.status')) AS status"),
                $qb->createFunction("JSON_UNQUOTE(JSON_EXTRACT(o.object, '$.pipeline')) AS pipeline"),
            )
                ->selectAlias($qb->func()->count('o.id'), 'cnt')
                ->from('openregister_objects', 'o')
                ->innerJoin('o', 'openregister_schemas', 's', $qb->expr()->eq('o.schema', 's.id'))
                ->where($qb->expr()->like('s.title', $qb->createNamedParameter('%ead%')))
                ->groupBy('status', 'pipeline');

            $result = $qb->executeQuery();
            $rows   = $result->fetchAll();
            $result->closeCursor();

            return $rows;
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
     * @return array<array{pipeline: string, total_value: string}> Pipeline values.
     */
    public function getLeadValueByPipeline(): array
    {
        try {
            $valueSumExpr = "COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(o.object, '$.value')) AS DECIMAL(15,2))), 0)";

            $qb = $this->db->getQueryBuilder();
            $qb->select(
                $qb->createFunction("JSON_UNQUOTE(JSON_EXTRACT(o.object, '$.pipeline')) AS pipeline"),
            )
                ->selectAlias($qb->createFunction($valueSumExpr), 'total_value')
                ->from('openregister_objects', 'o')
                ->innerJoin('o', 'openregister_schemas', 's', $qb->expr()->eq('o.schema', 's.id'))
                ->where($qb->expr()->like('s.title', $qb->createNamedParameter('%ead%')))
                ->groupBy('pipeline');

            $result = $qb->executeQuery();
            $rows   = $result->fetchAll();
            $result->closeCursor();

            return $rows;
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
     * @return array<array{status: string, cnt: string}> Grouped counts.
     */
    public function getRequestCounts(): array
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select(
                $qb->createFunction("JSON_UNQUOTE(JSON_EXTRACT(o.object, '$.status')) AS status"),
            )
                ->selectAlias($qb->func()->count('o.id'), 'cnt')
                ->from('openregister_objects', 'o')
                ->innerJoin('o', 'openregister_schemas', 's', $qb->expr()->eq('o.schema', 's.id'))
                ->where($qb->expr()->like('s.title', $qb->createNamedParameter('%equest%')))
                ->groupBy('status');

            $result = $qb->executeQuery();
            $rows   = $result->fetchAll();
            $result->closeCursor();

            return $rows;
        } catch (\Exception $e) {
            $this->logger->warning(
                message: '[MetricsRepository] Failed to get request counts',
                context: ['error' => $e->getMessage()]
            );
            return [];
        }//end try
    }//end getRequestCounts()
}//end class
