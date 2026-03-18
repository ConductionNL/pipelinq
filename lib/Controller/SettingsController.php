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
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;

/**
 * Controller for Pipelinq settings.
 */
class SettingsController extends Controller
{

    /**
     * The OpenRegister object service.
     *
     * @var \OCA\OpenRegister\Service\ObjectService|null The OpenRegister object service.
     */
    private ?\OCA\OpenRegister\Service\ObjectService $objectService = null;

    /**
     * Constructor.
     *
     * @param IRequest           $request         The request.
     * @param ContainerInterface $container       The container.
     * @param IAppManager        $appManager      The app manager.
     * @param IGroupManager      $groupManager    The group manager.
     * @param SettingsService    $settingsService The settings service.
     * @param IUserSession       $userSession     The user session.
     * @param IL10N              $l10n            The localization service.
     */
    public function __construct(
        IRequest $request,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly IGroupManager $groupManager,
        private SettingsService $settingsService,
        private IUserSession $userSession,
        private IL10N $l10n,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()


    /**
     * Attempts to retrieve the OpenRegister service from the container.
     *
     * @return \OCA\OpenRegister\Service\ObjectService|null The OpenRegister service if available, null otherwise.
     * @throws \RuntimeException If the service is not available.
     */
    public function getObjectService(): ?\OCA\OpenRegister\Service\ObjectService
    {
        if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
            $this->objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            return $this->objectService;
        }

        throw new \RuntimeException('OpenRegister service is not available.');

    }//end getObjectService()


    /**
     * Attempts to retrieve the Configuration service from the container.
     *
     * @return \OCA\OpenRegister\Service\ConfigurationService|null The Configuration service if available, null otherwise.
     * @throws \RuntimeException If the service is not available.
     */
    public function getConfigurationService(): ?\OCA\OpenRegister\Service\ConfigurationService
    {
        if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
            $configurationService = $this->container->get('OCA\OpenRegister\Service\ConfigurationService');
            return $configurationService;
        }

        throw new \RuntimeException('Configuration service is not available.');

    }//end getConfigurationService()

    /**
     * Get current Pipelinq settings.
     *
     * @return JSONResponse The settings response.
     *
     * @NoAdminRequired
     */
    public function index(): JSONResponse
    {
        $user    = $this->userSession->getUser();
        $isAdmin = $user !== null && $this->groupManager->isAdmin($user->getUID());

        return new JSONResponse(
                [
                    'success'       => true,
                    'openRegisters' => in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()),
                    'isAdmin'       => $isAdmin,
                    'config'        => $this->settingsService->getSettings(),
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
                        'message' => $this->l10n->t('Configuration re-imported successfully'),
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
