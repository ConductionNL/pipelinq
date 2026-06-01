<?php

/**
 * Pipelinq Admin PosBookkeepingConfigController.
 *
 * Admin-only endpoint to persist POS bookkeeping configuration:
 * Z-report generation time, Shillinq API endpoint, bearer token,
 * alert email, and GL account mapping.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller\Admin
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#4.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller\Admin;

use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Admin controller for POS bookkeeping configuration.
 *
 * All endpoints require admin access; non-admin requests receive 403.
 * Sensitive values (bearer token) are stored via IAppConfig::setValueMixed
 * with isSensitive=true so they are masked in audit logs.
 *
 * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#4.2
 */
class PosBookkeepingConfigController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest        $request      The HTTP request.
     * @param IAppConfig      $appConfig    The NC app configuration.
     * @param IUserSession    $userSession  The current user session.
     * @param IGroupManager   $groupManager The NC group manager.
     * @param LoggerInterface $logger       The logger.
     */
    public function __construct(
        IRequest $request,
        private IAppConfig $appConfig,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Return the current POS bookkeeping configuration.
     *
     * Sensitive values (token) are masked in the response.
     *
     * @return JSONResponse The current configuration.
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#4.2
     */
    public function index(): JSONResponse
    {
        if ($this->isAdmin() === false) {
            return new JSONResponse(['error' => 'Admin access required'], Http::STATUS_FORBIDDEN);
        }

        $token = (string) $this->appConfig->getValueString(Application::APP_ID, 'shillinq_token', '');

        return new JSONResponse(
                [
                    'zReportTime'      => $this->appConfig->getValueString(Application::APP_ID, 'bookkeeping_z_report_time', '23:59'),
                    'shillinqEndpoint' => $this->appConfig->getValueString(Application::APP_ID, 'shillinq_endpoint', ''),
                    'shillinqToken'    => $token !== '' ? '***' : '', // phpcs:ignore
                    'alertEmail'       => $this->appConfig->getValueString(Application::APP_ID, 'bookkeeping_alert_email', ''),
                ]
                );
    }//end index()

    /**
     * Save POS bookkeeping configuration.
     *
     * Validates input (HH:MM time format, URL, email) and persists to IAppConfig.
     * The bearer token is stored with isSensitive=true.
     *
     * @return JSONResponse Confirmation or validation error.
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#4.2
     */
    public function save(): JSONResponse
    {
        if ($this->isAdmin() === false) {
            return new JSONResponse(['error' => 'Admin access required'], Http::STATUS_FORBIDDEN);
        }

        $zReportTime      = (string) $this->request->getParam('zReportTime', '23:59');
        $shillinqEndpoint = (string) $this->request->getParam('shillinqEndpoint', '');
        $shillinqToken    = (string) $this->request->getParam('shillinqToken', '');
        $alertEmail       = (string) $this->request->getParam('alertEmail', '');

        // Validate zReportTime format (HH:MM).
        if (preg_match('/^\d{2}:\d{2}$/', $zReportTime) !== 1) {
            return new JSONResponse(
                ['error' => 'zReportTime must be in HH:MM format'],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        // Validate URL if provided.
        if ($shillinqEndpoint !== '' && filter_var($shillinqEndpoint, FILTER_VALIDATE_URL) === false) {
            return new JSONResponse(
                ['error' => 'shillinqEndpoint must be a valid URL'],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        // Validate email if provided.
        if ($alertEmail !== '' && filter_var($alertEmail, FILTER_VALIDATE_EMAIL) === false) {
            return new JSONResponse(
                ['error' => 'alertEmail must be a valid email address'],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        // Persist config values.
        $this->appConfig->setValueString(Application::APP_ID, 'bookkeeping_z_report_time', $zReportTime);
        $this->appConfig->setValueString(Application::APP_ID, 'shillinq_endpoint', $shillinqEndpoint);
        $this->appConfig->setValueString(Application::APP_ID, 'bookkeeping_alert_email', $alertEmail);

        // Store token only when a new value is provided (non-empty and not the masked placeholder).
        if ($shillinqToken !== '' && $shillinqToken !== '***') {
            $this->appConfig->setValueMixed(
                appId: Application::APP_ID,
                key: 'shillinq_token',
                value: $shillinqToken,
                isSensitive: true
            );
        }

        $this->logger->info('PosBookkeepingConfigController: configuration saved by admin');

        return new JSONResponse(['success' => true, 'message' => 'Configuratie opgeslagen']);
    }//end save()

    /**
     * Test the connection to the configured Shillinq endpoint.
     *
     * POSTs an empty JSON body to {endpoint}/api/JournalEntry/test.
     * Returns 200 on success, 502 on connection failure.
     *
     * @return JSONResponse The connection test result.
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#4.2
     */
    public function testConnection(): JSONResponse
    {
        if ($this->isAdmin() === false) {
            return new JSONResponse(['error' => 'Admin access required'], Http::STATUS_FORBIDDEN);
        }

        $endpoint = rtrim((string) $this->appConfig->getValueString(Application::APP_ID, 'shillinq_endpoint', ''), '/');
        $token    = (string) $this->appConfig->getValueString(Application::APP_ID, 'shillinq_token', '');

        if ($endpoint === '') {
            return new JSONResponse(
                ['error' => 'Shillinq endpoint is not configured'],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        try {
            $ch = curl_init($endpoint.'/api/health');
            if ($ch === false) {
                throw new \RuntimeException('Failed to initialise cURL');
            }

            $headers = ['Accept: application/json'];
            if ($token !== '') {
                $headers[] = 'Authorization: Bearer '.$token;
            }

            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

            $raw        = curl_exec($ch);
            $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError  = curl_error($ch);
            curl_close($ch);

            if ($raw === false) {
                throw new \RuntimeException('cURL error: '.$curlError);
            }

            if ($statusCode >= 200 && $statusCode < 400) {
                return new JSONResponse(['success' => true, 'statusCode' => $statusCode]);
            }

            return new JSONResponse(
                ['success' => false, 'statusCode' => $statusCode, 'error' => 'Unexpected response from Shillinq'],
                Http::STATUS_BAD_GATEWAY
            );
        } catch (\Throwable $e) {
            return new JSONResponse(
                ['success' => false, 'error' => $e->getMessage()],
                Http::STATUS_BAD_GATEWAY
            );
        }//end try
    }//end testConnection()

    /**
     * Check whether the current user is a Nextcloud administrator.
     *
     * @return bool True if the user is an admin.
     */
    private function isAdmin(): bool
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return false;
        }

        return $this->groupManager->isAdmin(userId: $user->getUID());
    }//end isAdmin()
}//end class
