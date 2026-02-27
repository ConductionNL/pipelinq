<?php

/**
 * Pipelinq SettingsController.
 *
 * Controller for managing Pipelinq application settings.
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
use OCA\Pipelinq\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for Pipelinq settings.
 */
class SettingsController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest        $request         The request.
     * @param SettingsService $settingsService The settings service.
     * @param IUserSession    $userSession     The user session.
     */
    public function __construct(
        IRequest $request,
        private SettingsService $settingsService,
        private IUserSession $userSession,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }//end __construct()

    /**
     * Get current Pipelinq settings.
     *
     * @return JSONResponse The settings response.
     *
     * @NoAdminRequired
     */
    public function index(): JSONResponse
    {
        return new JSONResponse(
                [
                    'success' => true,
                    'config'  => $this->settingsService->getSettings(),
                ]
                );
    }//end index()

    /**
     * Update Pipelinq settings.
     *
     * Admin-only: no @NoAdminRequired annotation, so Nextcloud
     * enforces admin privileges. The index() method is intentionally
     * marked @NoAdminRequired so non-admin users can read settings.
     *
     * @return JSONResponse The updated settings response.
     */
    public function create(): JSONResponse
    {
        $data   = $this->request->getParams();
        $config = $this->settingsService->updateSettings($data);

        return new JSONResponse(
                [
                    'success' => true,
                    'config'  => $config,
                ]
                );
    }//end create()

    /**
     * Re-import the Pipelinq configuration from the JSON file.
     *
     * @return JSONResponse The re-import result.
     */
    public function reimport(): JSONResponse
    {
        try {
            $result = $this->settingsService->loadSettings(force: true);

            return new JSONResponse(
                    [
                        'success' => true,
                        'message' => 'Configuration re-imported successfully',
                        'config'  => $this->settingsService->getSettings(),
                        'result'  => [
                            'registers' => count($result['registers'] ?? []),
                            'schemas'   => count($result['schemas'] ?? []),
                        ],
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                    [
                        'success' => false,
                        'message' => $e->getMessage(),
                    ],
                    500
                    );
        }//end try
    }//end reimport()

    /**
     * Get user settings for the current user.
     *
     * @return JSONResponse The user settings response.
     *
     * @NoAdminRequired
     */
    public function getUserSettings(): JSONResponse
    {
        $userId = $this->userSession->getUser()->getUID();

        return new JSONResponse(
            data: $this->settingsService->getUserSettings(userId: $userId)
        );
    }//end getUserSettings()

    /**
     * Update user settings for the current user.
     *
     * @return JSONResponse The updated user settings response.
     *
     * @NoAdminRequired
     */
    public function updateUserSettings(): JSONResponse
    {
        $userId = $this->userSession->getUser()->getUID();
        $data   = $this->request->getParams();

        return new JSONResponse(
            data: $this->settingsService->updateUserSettings(userId: $userId, data: $data)
        );
    }//end updateUserSettings()
}//end class
