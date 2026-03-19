<?php

/**
 * Pipelinq Metrics Controller
 *
 * Exposes application metrics in Prometheus text exposition format.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
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

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TextPlainResponse;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * Controller for exposing Prometheus metrics.
 *
 * @psalm-suppress UnusedClass
 */
class MetricsController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest        $request    The HTTP request
     * @param IDBConnection   $db         Database connection
     * @param IAppManager     $appManager App manager
     * @param LoggerInterface $logger     Logger
     */
    public function __construct(
        IRequest $request,
        private IDBConnection $db,
        private IAppManager $appManager,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Return Prometheus metrics in text exposition format.
     *
     * @NoCSRFRequired
     *
     * @return TextPlainResponse Prometheus-formatted metrics
     */
    public function index(): TextPlainResponse
    {
        $metrics  = $this->collectMetrics();
        $response = new TextPlainResponse($metrics);
        $response->addHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');

        return $response;
    }//end index()

    /**
     * Collect all metrics and format as Prometheus text.
     *
     * @return string Prometheus exposition format text
     */
    private function collectMetrics(): string
    {
        $lines = [];

        $this->addAppInfoMetrics($lines);
        $this->addLeadMetrics($lines);
        $this->addEntityCountMetrics($lines);
        $this->addRequestMetrics($lines);

        return implode("\n", $lines)."\n";
    }//end collectMetrics()

    /**
     * Add application info and health metrics.
     *
     * @param array $lines The metrics lines to append to.
     *
     * @return void
     */
    private function addAppInfoMetrics(array &$lines): void
    {
        $version    = $this->getAppVersion();
        $phpVersion = PHP_VERSION;

        $lines[] = '# HELP pipelinq_info Application information';
        $lines[] = '# TYPE pipelinq_info gauge';
        $lines[] = 'pipelinq_info{version="'.$version.'",php_version="'.$phpVersion.'"} 1';
        $lines[] = '';
        $lines[] = '# HELP pipelinq_up Whether the application is healthy';
        $lines[] = '# TYPE pipelinq_up gauge';
        $lines[] = 'pipelinq_up 1';
        $lines[] = '';
    }//end addAppInfoMetrics()

    /**
     * Add lead count and value metrics.
     *
     * @param array $lines The metrics lines to append to.
     *
     * @return void
     */
    private function addLeadMetrics(array &$lines): void
    {
        $lines[]    = '# HELP pipelinq_leads_total Total leads by status and pipeline';
        $lines[]    = '# TYPE pipelinq_leads_total gauge';
        $leadCounts = $this->getLeadCounts();
        foreach ($leadCounts as $row) {
            $status   = $this->sanitizeLabel(value: $row['status']);
            $pipeline = $this->sanitizeLabel(value: $row['pipeline']);
            $count    = (int) $row['cnt'];
            $lines[]  = 'pipelinq_leads_total{status="'.$status.'",pipeline="'.$pipeline.'"} '.$count;
        }

        $lines[] = '';

        $lines[]     = '# HELP pipelinq_leads_value_total Total pipeline value in EUR';
        $lines[]     = '# TYPE pipelinq_leads_value_total gauge';
        $valueCounts = $this->getLeadValueByPipeline();
        foreach ($valueCounts as $row) {
            $pipeline = $this->sanitizeLabel(value: $row['pipeline']);
            $value    = (float) $row['total_value'];
            $lines[]  = 'pipelinq_leads_value_total{pipeline="'.$pipeline.'"} '.$value;
        }

        $lines[] = '';
    }//end addLeadMetrics()

    /**
     * Add entity count metrics (clients and contacts).
     *
     * @param array $lines The metrics lines to append to.
     *
     * @return void
     */
    private function addEntityCountMetrics(array &$lines): void
    {
        $clientsTotal = $this->countObjectsBySchemaPattern(pattern: '%client%');
        $lines[]      = '# HELP pipelinq_clients_total Total clients';
        $lines[]      = '# TYPE pipelinq_clients_total gauge';
        $lines[]      = 'pipelinq_clients_total '.$clientsTotal;
        $lines[]      = '';

        $contactsTotal = $this->countObjectsBySchemaPattern(pattern: '%contact%');
        $lines[]       = '# HELP pipelinq_contacts_total Total contacts';
        $lines[]       = '# TYPE pipelinq_contacts_total gauge';
        $lines[]       = 'pipelinq_contacts_total '.$contactsTotal;
        $lines[]       = '';
    }//end addEntityCountMetrics()

    /**
     * Add service request metrics.
     *
     * @param array $lines The metrics lines to append to.
     *
     * @return void
     */
    private function addRequestMetrics(array &$lines): void
    {
        $lines[]       = '# HELP pipelinq_service_requests_total Total service requests by status';
        $lines[]       = '# TYPE pipelinq_service_requests_total gauge';
        $requestCounts = $this->getRequestCounts();
        foreach ($requestCounts as $row) {
            $status  = $this->sanitizeLabel(value: $row['status']);
            $count   = (int) $row['cnt'];
            $lines[] = 'pipelinq_service_requests_total{status="'.$status.'"} '.$count;
        }

        $lines[] = '';
    }//end addRequestMetrics()

    /**
     * Get lead counts grouped by status and pipeline.
     *
     * @return array<array{status: string, pipeline: string, cnt: string}> Grouped counts
     */
    private function getLeadCounts(): array
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
            $this->logger->warning('[MetricsController] Failed to get lead counts', ['error' => $e->getMessage()]);
            return [];
        }//end try
    }//end getLeadCounts()

    /**
     * Get lead value totals grouped by pipeline.
     *
     * @return array<array{pipeline: string, total_value: string}> Pipeline values
     */
    private function getLeadValueByPipeline(): array
    {
        try {
            $valueSumExpr = "COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(o.object, '$.value')) AS DECIMAL(15,2))), 0)";

            $qb = $this->db->getQueryBuilder();
            $qb->select(
                $qb->createFunction("JSON_UNQUOTE(JSON_EXTRACT(o.object, '$.pipeline')) AS pipeline"),
            )
                ->selectAlias(
                    $qb->createFunction($valueSumExpr),
                    'total_value'
                )
                ->from('openregister_objects', 'o')
                ->innerJoin('o', 'openregister_schemas', 's', $qb->expr()->eq('o.schema', 's.id'))
                ->where($qb->expr()->like('s.title', $qb->createNamedParameter('%ead%')))
                ->groupBy('pipeline');

            $result = $qb->executeQuery();
            $rows   = $result->fetchAll();
            $result->closeCursor();

            return $rows;
        } catch (\Exception $e) {
            $this->logger->warning('[MetricsController] Failed to get lead values', ['error' => $e->getMessage()]);
            return [];
        }//end try
    }//end getLeadValueByPipeline()

    /**
     * Count objects matching a schema title pattern.
     *
     * @param string $pattern SQL LIKE pattern for schema title
     *
     * @return int Object count
     */
    private function countObjectsBySchemaPattern(string $pattern): int
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
            $this->logger->warning('[MetricsController] Failed to count objects', ['error' => $e->getMessage()]);
            return 0;
        }
    }//end countObjectsBySchemaPattern()

    /**
     * Get service request counts grouped by status.
     *
     * @return array<array{status: string, cnt: string}> Grouped counts
     */
    private function getRequestCounts(): array
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
            $this->logger->warning('[MetricsController] Failed to get request counts', ['error' => $e->getMessage()]);
            return [];
        }
    }//end getRequestCounts()

    /**
     * Get the app version.
     *
     * @return string The app version
     */
    private function getAppVersion(): string
    {
        try {
            return $this->appManager->getAppVersion(Application::APP_ID);
        } catch (\Exception $e) {
            return 'unknown';
        }
    }//end getAppVersion()

    /**
     * Sanitize a label value for Prometheus format.
     *
     * @param string $value The label value
     *
     * @return string Sanitized label value
     */
    private function sanitizeLabel(string $value): string
    {
        return str_replace(
            ['\\', '"', "\n"],
            ['\\\\', '\\"', '\\n'],
            $value
        );
    }//end sanitizeLabel()
}//end class
