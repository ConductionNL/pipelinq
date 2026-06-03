<?php

/**
 * Pipelinq EmailSyncController.
 *
 * REST API for a user's own email matching-job configuration and status. Every
 * method derives identity from the session (ADR-005, IDOR-safe): a user can only
 * read and change their own matching settings, and the manual trigger only runs
 * the matching job for the calling user. The leaf owns the email link records;
 * this controller governs the pipelinq matching job only.
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
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/email-calendar-sync/specs/email-calendar-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\BackgroundJob\EmailMatchJob;
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
 * Controller for per-user email matching-job settings, status, and trigger.
 *
 * @spec openspec/changes/email-calendar-sync/tasks.md#task-3.1
 */
class EmailSyncController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest          $request      The request.
     * @param EmailMatchService $matchService The matching service (settings + status).
     * @param EmailMatchJob     $matchJob     The matching job (manual trigger).
     * @param IUserSession      $userSession  The user session.
     * @param IL10N             $l10n         The localization service.
     * @param LoggerInterface   $logger       The logger.
     */
    public function __construct(
        IRequest $request,
        private EmailMatchService $matchService,
        private EmailMatchJob $matchJob,
        private IUserSession $userSession,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Return the current user's matching-job settings.
     *
     * @return JSONResponse The settings payload, or 401 when unauthenticated.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/email-calendar-sync/specs/email-calendar-sync/spec.md
     */
    public function getSettings(): JSONResponse
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->unauthorized();
        }

        return new JSONResponse(
            [
                'enabled'           => $this->matchService->isSyncEnabled(userId: $userId),
                'account'           => $this->matchService->getSyncAccount(userId: $userId),
                'excludedAddresses' => $this->matchService->getExcludedAddresses(userId: $userId),
            ]
        );
    }//end getSettings()

    /**
     * Save the current user's matching-job settings.
     *
     * @return JSONResponse The saved settings, 401 when unauthenticated, or 400 on invalid input.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/email-calendar-sync/specs/email-calendar-sync/spec.md
     */
    public function saveSettings(): JSONResponse
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->unauthorized();
        }

        $enabled = $this->request->getParam('enabled');
        if (is_bool($enabled) === false) {
            return $this->badRequest(message: $this->l10n->t('The enabled flag must be a boolean'));
        }

        $account = $this->normaliseAccount(raw: $this->request->getParam('account'));
        if ($account === false) {
            return $this->badRequest(message: $this->l10n->t('The mail account must be a positive number'));
        }

        $excluded = $this->normaliseExcluded(raw: $this->request->getParam('excludedAddresses', []));
        if ($excluded === false) {
            return $this->badRequest(message: $this->l10n->t('Excluded addresses must be a list of email addresses'));
        }

        $this->matchService->setSyncEnabled(userId: $userId, enabled: $enabled);
        $this->matchService->setSyncAccount(userId: $userId, accountId: $account);
        $this->matchService->setExcludedAddresses(userId: $userId, addresses: $excluded);

        return new JSONResponse(
            [
                'enabled'           => $enabled,
                'account'           => $account,
                'excludedAddresses' => $this->matchService->getExcludedAddresses(userId: $userId),
            ]
        );
    }//end saveSettings()

    /**
     * Run the matching job for the current user and return the link count.
     *
     * @return JSONResponse The link count, or 401 when unauthenticated.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/email-calendar-sync/specs/email-calendar-sync/spec.md
     */
    public function trigger(): JSONResponse
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->unauthorized();
        }

        try {
            $this->matchJob->runForUser(userId: $userId);
        } catch (Throwable $e) {
            $this->logger->error('EmailSyncController::trigger failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(
                ['error' => $this->l10n->t('Could not run the sync')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        return new JSONResponse($this->matchService->getStatus(userId: $userId));
    }//end trigger()

    /**
     * Return the current user's last matching-job run status.
     *
     * @return JSONResponse The status payload, or 401 when unauthenticated.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/email-calendar-sync/specs/email-calendar-sync/spec.md
     */
    public function status(): JSONResponse
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->unauthorized();
        }

        return new JSONResponse($this->matchService->getStatus(userId: $userId));
    }//end status()

    /**
     * Resolve the authenticated user's id, or null when unauthenticated.
     *
     * @return string|null The user id.
     */
    private function currentUserId(): ?string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return null;
        }

        return $user->getUID();
    }//end currentUserId()

    /**
     * Validate and normalise the account param.
     *
     * @param mixed $raw The raw account param.
     *
     * @return int|null|false The account id, null when unset, or false when invalid.
     */
    private function normaliseAccount(mixed $raw): int|null|false
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if ((is_int($raw) === true || (is_string($raw) === true && ctype_digit($raw) === true)) && (int) $raw > 0) {
            return (int) $raw;
        }

        return false;
    }//end normaliseAccount()

    /**
     * Validate and normalise the excluded-addresses param.
     *
     * @param mixed $raw The raw excludedAddresses param.
     *
     * @return array<int, string>|false The cleaned list, or false when invalid.
     */
    private function normaliseExcluded(mixed $raw): array|false
    {
        if (is_array($raw) === false) {
            return false;
        }

        $clean = [];
        foreach ($raw as $value) {
            if (is_string($value) === false) {
                return false;
            }

            $trimmed = trim($value);
            if ($trimmed !== '') {
                $clean[] = $trimmed;
            }
        }

        return $clean;
    }//end normaliseExcluded()

    /**
     * Build a 401 response with a static message.
     *
     * @return JSONResponse The unauthorized response.
     */
    private function unauthorized(): JSONResponse
    {
        return new JSONResponse(
            ['error' => $this->l10n->t('Authentication required')],
            Http::STATUS_UNAUTHORIZED
        );
    }//end unauthorized()

    /**
     * Build a 400 response with the given static message.
     *
     * @param string $message The validation message.
     *
     * @return JSONResponse The bad-request response.
     */
    private function badRequest(string $message): JSONResponse
    {
        return new JSONResponse(['error' => $message], Http::STATUS_BAD_REQUEST);
    }//end badRequest()
}//end class
