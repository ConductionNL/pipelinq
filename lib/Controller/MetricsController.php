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
use OCA\Pipelinq\Service\MetricsFormatter;
use OCA\Pipelinq\Service\MetricsRepository;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TextPlainResponse;
use OCP\IRequest;
use OCP\App\IAppManager;

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
     * @param IRequest          $request    The HTTP request.
     * @param IAppManager       $appManager App manager.
     * @param MetricsRepository $repository Metrics data repository.
     * @param MetricsFormatter  $formatter  Metrics text formatter.
     */
    public function __construct(
        IRequest $request,
        private IAppManager $appManager,
        private MetricsRepository $repository,
        private MetricsFormatter $formatter,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Return Prometheus metrics in text exposition format.
     *
     * @NoCSRFRequired
     * @NoAdminRequired
     *
     * @return TextPlainResponse Prometheus-formatted metrics.
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
     * @return string Prometheus exposition format text.
     */
    private function collectMetrics(): string
    {
        $lines = array_merge(
            $this->formatter->formatAppInfo(version: $this->getAppVersion(), phpVersion: PHP_VERSION),
            $this->formatter->formatLeadCounts(leadCounts: $this->repository->getLeadCounts()),
            $this->formatter->formatLeadValues(valueCounts: $this->repository->getLeadValueByPipeline()),
            $this->formatter->formatGauge(
                name: 'pipelinq_clients_total',
                help: 'Total clients',
                value: $this->repository->countObjectsBySchemaPattern(pattern: '%client%')
            ),
            $this->formatter->formatGauge(
                name: 'pipelinq_contacts_total',
                help: 'Total contacts',
                value: $this->repository->countObjectsBySchemaPattern(pattern: '%contact%')
            ),
            $this->formatter->formatRequestCounts(requestCounts: $this->repository->getRequestCounts())
        );

        return implode("\n", $lines)."\n";
    }//end collectMetrics()

    /**
     * Get the app version.
     *
     * @return string The app version.
     */
    private function getAppVersion(): string
    {
        try {
            return $this->appManager->getAppVersion(Application::APP_ID);
        } catch (\Exception $e) {
            return 'unknown';
        }
    }//end getAppVersion()
}//end class
