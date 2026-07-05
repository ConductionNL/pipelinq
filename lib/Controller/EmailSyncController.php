<?php

/**
 * Pipelinq EmailSyncController.
 *
 * Per-user matching-job configuration + manual trigger + status endpoints for
 * the leaf-first email matching feature (`email-calendar-sync` ADR-022). The
 * controller does NOT operate on email-link records — those are owned by the
 * OpenRegister `email` leaf. It only exposes the matching-job's per-user
 * settings, a manual trigger that calls `EmailMatchService::runForUser`, and
 * a status view of the last run.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/email-calendar-sync/specs/email-calendar-sync/spec.md#req-per-user-matching-settings
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\EmailMatchService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * REST endpoints for the per-user email-matching settings, manual trigger,
 * and last-run status.
 *
 * @spec openspec/changes/email-calendar-sync/specs/email-calendar-sync/spec.md#req-per-user-matching-settings
 */
class EmailSyncController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest          $request           The request.
     * @param EmailMatchService $emailMatchService Matching service.
     * @param IUserSession      $userSession       User session.
     * @param IL10N             $l10n              Localization service.
     * @param LoggerInterface   $logger            Logger.
     */
    public function __construct(
        IRequest $request,
        private EmailMatchService $emailMatchService,
        private IUserSession $userSession,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()

    /**
     * Return the current user's matching settings.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/email-calendar-sync/specs/email-calendar-sync/spec.md#req-per-user-matching-settings
     */
    public function getSettings(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $settings = $this->emailMatchService->getSettings(userId: $user->getUID());
            return new JSONResponse($this->responseShape(settings: $settings));
        } catch (Throwable $e) {
            $this->logger->error('EmailSyncController::getSettings failed', ['exception' => $e]);
            return new JSONResponse(
                ['error' => $this->l10n->t('An unexpected error occurred')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }

    }//end getSettings()

    /**
     * Persist the current user's matching settings.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/email-calendar-sync/specs/email-calendar-sync/spec.md#req-per-user-matching-settings
     */
    public function saveSettings(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
        }

        $accountParam = $this->request->getParam('account');
        if ($accountParam === null || is_numeric($accountParam) === false) {
            return new JSONResponse(['error' => $this->l10n->t('Invalid account')], Http::STATUS_BAD_REQUEST);
        }

        $account = (int) $accountParam;
        if ($account < 0) {
            return new JSONResponse(['error' => $this->l10n->t('Invalid account')], Http::STATUS_BAD_REQUEST);
        }

        $enabled  = (bool) $this->request->getParam('enabled', false);
        $excluded = $this->request->getParam('excludedAddresses', []);

        try {
            $current = $this->emailMatchService->getSettings(userId: $user->getUID());
            $this->emailMatchService->writeSettings(
                userId: $user->getUID(),
                settings: [
                    'account'           => $account,
                    'enabled'           => $enabled,
                    'excludedAddresses' => $excluded,
                    'cursor'            => $current['cursor'],
                ]
            );
            $settings = $this->emailMatchService->getSettings(userId: $user->getUID());
            return new JSONResponse($this->responseShape(settings: $settings));
        } catch (Throwable $e) {
            $this->logger->error('EmailSyncController::saveSettings failed', ['exception' => $e]);
            return new JSONResponse(
                ['error' => $this->l10n->t('An unexpected error occurred')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try

    }//end saveSettings()

    /**
     * Run the matching job once for the current user.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/email-calendar-sync/specs/email-calendar-sync/spec.md#req-per-user-matching-settings
     */
    public function trigger(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $result = $this->emailMatchService->runForUser(userId: $user->getUID());
            return new JSONResponse(
                [
                    'linked'  => (int) $result['linked'],
                    'scanned' => (int) $result['scanned'],
                ]
            );
        } catch (Throwable $e) {
            $this->logger->error('EmailSyncController::trigger failed', ['exception' => $e]);
            return new JSONResponse(
                ['error' => $this->l10n->t('An unexpected error occurred')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }

    }//end trigger()

    /**
     * Return the current user's last-run status.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/email-calendar-sync/specs/email-calendar-sync/spec.md#req-matching-job-status-display
     */
    public function getStatus(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $status = $this->emailMatchService->getStatus(userId: $user->getUID());
            return new JSONResponse($status);
        } catch (Throwable $e) {
            $this->logger->error('EmailSyncController::getStatus failed', ['exception' => $e]);
            return new JSONResponse(
                ['error' => $this->l10n->t('An unexpected error occurred')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }

    }//end getStatus()

    /**
     * Project the internal settings shape onto the public response.
     *
     * Keeps the per-user cursor internal — the UI doesn't need it.
     *
     * @param array<string,mixed> $settings Internal settings shape.
     *
     * @return array{account:int,enabled:bool,excludedAddresses:array<int,string>}
     */
    private function responseShape(array $settings): array
    {
        $excludedAddresses = [];
        if (is_array($settings['excludedAddresses'] ?? null) === true) {
            $excludedAddresses = array_values($settings['excludedAddresses']);
        }

        return [
            'account'           => (int) ($settings['account'] ?? 0),
            'enabled'           => (bool) ($settings['enabled'] ?? false),
            'excludedAddresses' => $excludedAddresses,
        ];

    }//end responseShape()
}//end class
