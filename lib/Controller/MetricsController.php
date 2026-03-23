<?php

/**
 * Pipelinq Metrics Controller
 *
 * Exposes application metrics in Prometheus text exposition format.
 * Supports admin session auth and optional Bearer token auth for external scrapers.
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
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\TextPlainResponse;
use OCP\IAppConfig;
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
     * @param IAppConfig        $appConfig  App configuration.
     * @param MetricsRepository $repository Metrics data repository.
     * @param MetricsFormatter  $formatter  Metrics text formatter.
     */
    public function __construct(
        IRequest $request,
        private IAppManager $appManager,
        private IAppConfig $appConfig,
        private MetricsRepository $repository,
        private MetricsFormatter $formatter,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Return Prometheus metrics in text exposition format.
     *
     * Supports Bearer token authentication for external Prometheus scrapers.
     * If a `metrics_api_token` is configured in app settings and the request
     * includes a valid `Authorization: Bearer <token>` header, access is granted
     * without requiring a Nextcloud admin session.
     *
     * @NoCSRFRequired
     * @PublicPage
     *
     * @return TextPlainResponse Prometheus-formatted metrics.
     */
    public function index(): TextPlainResponse
    {
        // Check token-based auth for external scrapers.
        if ($this->isAuthorized() === false) {
            $response = new TextPlainResponse('Unauthorized', Http::STATUS_FORBIDDEN);
            return $response;
        }

        $metrics  = $this->collectMetrics();
        $response = new TextPlainResponse($metrics);
        $response->addHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');

        return $response;
    }//end index()

    /**
     * Check whether the request is authorized to access metrics.
     *
     * Authorization is granted if:
     * 1. The request has a valid Bearer token matching the configured metrics_api_token, OR
     * 2. No metrics_api_token is configured (falls back to Nextcloud session auth via framework)
     *
     * @return bool Whether the request is authorized.
     */
    private function isAuthorized(): bool
    {
        $configuredToken = $this->appConfig->getValueString(
            Application::APP_ID,
            'metrics_api_token',
            ''
        );

        // If no token is configured, allow access (relies on Nextcloud framework auth).
        if ($configuredToken === '') {
            return true;
        }

        // Check for Bearer token in Authorization header.
        $authHeader = $this->request->getHeader('Authorization');
        if ($authHeader !== '' && str_starts_with($authHeader, 'Bearer ')) {
            $providedToken = substr($authHeader, 7);
            return hash_equals($configuredToken, $providedToken);
        }

        // No valid token provided and token is required.
        return false;
    }//end isAuthorized()

    /**
     * Collect all metrics and format as Prometheus text.
     *
     * @return string Prometheus exposition format text.
     */
    private function collectMetrics(): string
    {
        $openRegisterUp = in_array(
            'openregister',
            $this->appManager->getInstalledApps(),
            true
        );

        $lines = array_merge(
            $this->formatter->formatAppInfo(version: $this->getAppVersion(), phpVersion: PHP_VERSION),
            $this->formatter->formatLeadCounts(leadCounts: $this->repository->getLeadCounts()),
            $this->formatter->formatLeadValues(valueCounts: $this->repository->getLeadValueByPipeline()),
            $this->formatter->formatConversionRates(rates: $this->repository->getConversionRates()),
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
            $this->formatter->formatRequestCounts(requestCounts: $this->repository->getRequestCounts()),
            $this->formatter->formatDependencyUp(name: 'openregister', up: $openRegisterUp)
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
