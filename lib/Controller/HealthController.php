<?php

/**
 * Pipelinq Health Controller
 *
 * Exposes health check endpoint for container orchestration and monitoring.
 * Checks database, filesystem, and OpenRegister dependency health.
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
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * Controller for health check endpoints.
 *
 * @psalm-suppress UnusedClass
 */
class HealthController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest        $request    The HTTP request
     * @param IDBConnection   $db         Database connection
     * @param IAppManager     $appManager App manager
     * @param IAppConfig      $appConfig  App configuration
     * @param LoggerInterface $logger     Logger
     */
    public function __construct(
        IRequest $request,
        private IDBConnection $db,
        private IAppManager $appManager,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Health check endpoint.
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Health status
     */
    public function index(): JSONResponse
    {
        $checks = [];
        $status = 'ok';

        // Check database connectivity.
        $checks['database'] = $this->checkDatabase();
        if ($checks['database'] !== 'ok') {
            $status = 'error';
        }

        // Check filesystem.
        $checks['filesystem'] = $this->checkFilesystem();
        if ($checks['filesystem'] !== 'ok' && $status !== 'error') {
            $status = 'degraded';
        }

        // Check OpenRegister dependency.
        $checks['openregister'] = $this->checkOpenRegister();
        if ($checks['openregister'] !== 'ok') {
            // OpenRegister is critical — Pipelinq cannot function without it.
            $status = 'error';
        }

        // Check register configuration.
        $checks['register_configured'] = $this->checkRegisterConfigured();
        if ($checks['register_configured'] !== 'ok' && $status === 'ok') {
            $status = 'degraded';
        }

        $httpStatus = Http::STATUS_SERVICE_UNAVAILABLE;
        if ($status === 'ok' || $status === 'degraded') {
            $httpStatus = Http::STATUS_OK;
        }

        return new JSONResponse(
            [
                'status'  => $status,
                'version' => $this->getAppVersion(),
                'checks'  => $checks,
            ],
            $httpStatus
        );
    }//end index()

    /**
     * Check database connectivity.
     *
     * @return string 'ok' or error message
     */
    private function checkDatabase(): string
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->createFunction('1'));
            $result = $qb->executeQuery();
            $result->closeCursor();

            return 'ok';
        } catch (\Exception $e) {
            $this->logger->error('[HealthController] Database check failed', ['error' => $e->getMessage()]);
            return 'failed: '.$e->getMessage();
        }
    }//end checkDatabase()

    /**
     * Check filesystem access.
     *
     * @return string 'ok' or error message
     */
    private function checkFilesystem(): string
    {
        try {
            $tmpFile = sys_get_temp_dir().'/pipelinq_health_'.getmypid();
            $written = file_put_contents($tmpFile, 'health');
            if ($written === false) {
                return 'failed: cannot write to temp directory';
            }

            unlink($tmpFile);

            return 'ok';
        } catch (\Exception $e) {
            return 'failed: '.$e->getMessage();
        }
    }//end checkFilesystem()

    /**
     * Check whether the OpenRegister app is installed and enabled.
     *
     * @return string 'ok' or status message
     */
    private function checkOpenRegister(): string
    {
        try {
            $installedApps = $this->appManager->getInstalledApps();
            if (in_array('openregister', $installedApps, true) === false) {
                return 'unavailable: app not installed';
            }

            if ($this->appManager->isEnabledForUser('openregister') === false) {
                return 'unavailable: app disabled';
            }

            return 'ok';
        } catch (\Exception $e) {
            $this->logger->error(
                '[HealthController] OpenRegister check failed',
                ['error' => $e->getMessage()]
            );
            return 'failed: '.$e->getMessage();
        }
    }//end checkOpenRegister()

    /**
     * Check whether the Pipelinq register has been configured.
     *
     * @return string 'ok' or 'missing'
     */
    private function checkRegisterConfigured(): string
    {
        $registerId = $this->appConfig->getValueString(
            Application::APP_ID,
            'register',
            ''
        );

        if ($registerId === '') {
            return 'missing';
        }

        return 'ok';
    }//end checkRegisterConfigured()

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
}//end class
