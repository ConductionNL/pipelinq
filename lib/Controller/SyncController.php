<?php

/**
 * Pipelinq SyncController.
 *
 * Controller for managing email and calendar synchronization settings and data.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/2026-03-20-email-calendar-sync/tasks.md#task-3.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\EmailSyncService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for email and calendar sync settings and data.
 */
class SyncController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest          $request          The request.
     * @param IUserSession      $userSession      The user session.
     * @param IConfig           $config           The configuration.
     * @param EmailSyncService  $emailSyncService The email sync service.
     * @param IL10N             $l10n             The localization service.
     */
    public function __construct(
        IRequest $request,
        private IUserSession $userSession,
        private IConfig $config,
        private EmailSyncService $emailSyncService,
        private IL10N $l10n,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Get the user's sync settings.
     *
     * @return JSONResponse The sync settings.
     *
     * @NoAdminRequired
     * @spec openspec/changes/2026-03-20-email-calendar-sync/tasks.md#task-3.1
     */
    public function getSettings(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l10n->t('User not authenticated')], 401);
        }

        $userId = $user->getUID();
        return new JSONResponse([
            'email_sync_enabled' => $this->config->getUserValue(
                userId: $userId,
                app: Application::APP_ID,
                key: 'email_sync_enabled',
                default: 'false'
            ) === 'true',
            'calendar_sync_enabled' => $this->config->getUserValue(
                userId: $userId,
                app: Application::APP_ID,
                key: 'calendar_sync_enabled',
                default: 'false'
            ) === 'true',
            'mail_account' => $this->config->getUserValue(
                userId: $userId,
                app: Application::APP_ID,
                key: 'mail_account',
                default: null
            ),
            'default_calendar' => $this->config->getUserValue(
                userId: $userId,
                app: Application::APP_ID,
                key: 'default_calendar',
                default: null
            ),
            'exclude_personal_emails' => $this->config->getUserValue(
                userId: $userId,
                app: Application::APP_ID,
                key: 'exclude_personal_emails',
                default: 'true'
            ) === 'true',
        ]);
    }//end getSettings()

    /**
     * Update the user's sync settings.
     *
     * @return JSONResponse The updated sync settings.
     *
     * @NoAdminRequired
     * @spec openspec/changes/2026-03-20-email-calendar-sync/tasks.md#task-3.1
     */
    public function updateSettings(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l10n->t('User not authenticated')], 401);
        }

        $userId = $user->getUID();
        $settings = $this->request->getParams();

        // Update allowed settings
        $allowedKeys = ['email_sync_enabled', 'calendar_sync_enabled', 'mail_account', 'default_calendar', 'exclude_personal_emails'];
        foreach ($allowedKeys as $key) {
            if (isset($settings[$key])) {
                $value = $settings[$key];
                // Convert booleans to string for config storage
                if (is_bool($value)) {
                    $value = $value ? 'true' : 'false';
                }
                $this->config->setUserValue(
                    userId: $userId,
                    app: Application::APP_ID,
                    key: $key,
                    value: (string)$value
                );
            }
        }

        // Return updated settings
        return $this->getSettings();
    }//end updateSettings()

    /**
     * Get email links for an entity.
     *
     * @return JSONResponse The email links.
     *
     * @NoAdminRequired
     * @spec openspec/changes/2026-03-20-email-calendar-sync/tasks.md#task-3.2
     */
    public function getEmailLinks(): JSONResponse
    {
        $entityType = $this->request->getParam('entityType', '');
        $entityId = $this->request->getParam('entityId', '');

        if ($entityType === '' || $entityId === '') {
            return new JSONResponse(
                ['error' => $this->l10n->t('Missing entityType or entityId parameter')],
                400
            );
        }

        // Validate entityType
        if (!in_array($entityType, ['client', 'contact', 'lead', 'request'], true)) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Invalid entityType')],
                400
            );
        }

        try {
            // For now, return an empty list (integration with ObjectService would happen here)
            return new JSONResponse([
                'emails' => [],
            ]);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                500
            );
        }
    }//end getEmailLinks()

    /**
     * Update an email link (exclude/include).
     *
     * @param string $messageId The email message ID.
     *
     * @return JSONResponse The update result.
     *
     * @NoAdminRequired
     * @spec openspec/changes/2026-03-20-email-calendar-sync/tasks.md#task-3.2
     */
    public function updateEmailLink(string $messageId): JSONResponse
    {
        if ($messageId === '') {
            return new JSONResponse(
                ['error' => $this->l10n->t('Missing messageId parameter')],
                400
            );
        }

        $excluded = $this->request->getParam('excluded', false);

        try {
            // For now, acknowledge the request (integration with ObjectService would happen here)
            return new JSONResponse([
                'success' => true,
                'messageId' => $messageId,
                'excluded' => $excluded,
            ]);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                500
            );
        }
    }//end updateEmailLink()
}//end class
