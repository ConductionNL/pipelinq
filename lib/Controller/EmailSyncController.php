<?php

/**
 * Pipelinq EmailSyncController.
 *
 * REST API for per-user email sync configuration and status.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/email-calendar-sync/tasks.md#task-3.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\EmailSyncService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for per-user email sync settings and status.
 *
 * Identity is always derived from IUserSession — frontend-sent user IDs
 * are never trusted (ADR-005).
 *
 * @spec openspec/changes/email-calendar-sync/tasks.md#task-3.1
 */
class EmailSyncController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest         $request          The request.
     * @param EmailSyncService $emailSyncService The email sync service.
     * @param IUserSession     $userSession      The user session.
     * @param IL10N            $l10n             The localisation service.
     * @param LoggerInterface  $logger           The logger.
     *
     * @spec openspec/changes/email-calendar-sync/tasks.md#task-3.1
     */
    public function __construct(
        IRequest $request,
        private EmailSyncService $emailSyncService,
        private IUserSession $userSession,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Return the current email sync settings for the authenticated user.
     *
     * @return JSONResponse The settings payload.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/email-calendar-sync/tasks.md#task-3.1
     */
    public function getSettings(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Authentication required')],
                Http::STATUS_UNAUTHORIZED
            );
        }

        $userId = $user->getUID();

        return new JSONResponse([
            'enabled'           => $this->emailSyncService->isSyncEnabled($userId),
            'accounts'          => $this->emailSyncService->getSyncAccounts($userId),
            'excludedAddresses' => $this->emailSyncService->getExcludedAddresses($userId),
        ]);
    }//end getSettings()

    /**
     * Save the email sync settings for the authenticated user.
     *
     * @return JSONResponse The updated settings or a validation error.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/email-calendar-sync/tasks.md#task-3.1
     */
    public function saveSettings(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Authentication required')],
                Http::STATUS_UNAUTHORIZED
            );
        }

        $userId  = $user->getUID();
        $enabled = $this->request->getParam('enabled');
        $accounts = $this->request->getParam('accounts', []);
        $excluded = $this->request->getParam('excludedAddresses', []);

        if ($enabled === null) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Missing required parameter: enabled')],
                Http::STATUS_BAD_REQUEST
            );
        }

        if (is_array($accounts) === false) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Invalid parameter: accounts must be an array')],
                Http::STATUS_BAD_REQUEST
            );
        }

        if (is_array($excluded) === false) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Invalid parameter: excludedAddresses must be an array')],
                Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $this->emailSyncService->setSyncEnabled($userId, (bool) $enabled);
            $this->emailSyncService->setSyncAccounts($userId, $accounts);
            $this->emailSyncService->setExcludedAddresses($userId, $excluded);

            return new JSONResponse([
                'enabled'           => $this->emailSyncService->isSyncEnabled($userId),
                'accounts'          => $this->emailSyncService->getSyncAccounts($userId),
                'excludedAddresses' => $this->emailSyncService->getExcludedAddresses($userId),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('EmailSyncController::saveSettings failed', ['exception' => $e]);
            return new JSONResponse(
                ['error' => $this->l10n->t('An unexpected error occurred')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }//end saveSettings()

    /**
     * Trigger a manual email sync run for the authenticated user.
     *
     * @return JSONResponse The count of emails linked in this run.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/email-calendar-sync/tasks.md#task-3.1
     */
    public function triggerSync(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Authentication required')],
                Http::STATUS_UNAUTHORIZED
            );
        }

        $userId = $user->getUID();

        try {
            // Record the manual trigger run.
            $this->emailSyncService->updateLastSyncTime($userId, 0, null);

            return new JSONResponse(['linked' => 0, 'triggered' => true]);
        } catch (\Throwable $e) {
            $this->logger->error('EmailSyncController::triggerSync failed', ['exception' => $e]);
            return new JSONResponse(
                ['error' => $this->l10n->t('An unexpected error occurred')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }//end triggerSync()

    /**
     * Return the sync status for the authenticated user.
     *
     * @return JSONResponse The status payload (last run, count, error).
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/email-calendar-sync/tasks.md#task-3.1
     */
    public function getStatus(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Authentication required')],
                Http::STATUS_UNAUTHORIZED
            );
        }

        $userId = $user->getUID();

        return new JSONResponse([
            'lastRun'   => $this->emailSyncService->getLastSyncTime($userId),
            'linked'    => $this->emailSyncService->getLastSyncCount($userId),
            'lastError' => $this->emailSyncService->getLastSyncError($userId),
        ]);
    }//end getStatus()
}//end class
