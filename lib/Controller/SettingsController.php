<?php

/**
 * Pipelinq SettingsController.
 *
 * Controller for managing Pipelinq application settings.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-3
 * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-8
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ApiAuthService;
use OCA\Pipelinq\Service\ObjectenAccessService;
use OCA\Pipelinq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Controller for Pipelinq settings.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/admin-settings/tasks.md#task-3
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
     * @param IRequest              $request               The request.
     * @param ContainerInterface    $container             The container.
     * @param IAppManager           $appManager            The app manager.
     * @param IGroupManager         $groupManager          The group manager.
     * @param SettingsService       $settingsService       The settings service.
     * @param ApiAuthService        $apiAuthService        The API auth service.
     * @param ObjectenAccessService $objectenAccessService The objecten access service.
     * @param IUserSession          $userSession           The user session.
     * @param IL10N                 $l10n                  The localization service.
     * @param LoggerInterface       $logger                The logger.
     */
    public function __construct(
        IRequest $request,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly IGroupManager $groupManager,
        private SettingsService $settingsService,
        private ApiAuthService $apiAuthService,
        private ObjectenAccessService $objectenAccessService,
        private IUserSession $userSession,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Attempts to retrieve the OpenRegister service from the container.
     *
     * @return \OCA\OpenRegister\Service\ObjectService|null The OpenRegister service if available, null otherwise.
     * @throws \RuntimeException If the service is not available.
     * @spec   openspec/changes/reverse-2026-05-26-be-settings/tasks.md#task-6
     */
    public function getObjectService(): ?\OCA\OpenRegister\Service\ObjectService
    {
        if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
            $this->objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            return $this->objectService;
        }

        throw new RuntimeException('OpenRegister service is not available.');

    }//end getObjectService()

    /**
     * Attempts to retrieve the Configuration service from the container.
     *
     * @return \OCA\OpenRegister\Service\ConfigurationService|null The Configuration service if available, null otherwise.
     * @throws \RuntimeException If the service is not available.
     * @spec   openspec/changes/reverse-2026-05-26-be-settings/tasks.md#task-5
     */
    public function getConfigurationService(): ?\OCA\OpenRegister\Service\ConfigurationService
    {
        if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
            $configurationService = $this->container->get('OCA\OpenRegister\Service\ConfigurationService');
            return $configurationService;
        }

        throw new RuntimeException('Configuration service is not available.');

    }//end getConfigurationService()

    /**
     * Get current Pipelinq settings.
     *
     * Admins also receive objectenAccess, apiTokens, oauthConfig (no secret), and mcpConfig (no secrets).
     *
     * @return JSONResponse The settings response.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-3
     * @spec openspec/changes/admin-settings/tasks.md#task-3
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        $user    = $this->userSession->getUser();
        $isAdmin = $user !== null && $this->groupManager->isAdmin($user->getUID());

        $config   = $this->settingsService->getSettings();
        $response = [
            'success'                => true,
            'openRegisters'          => in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()),
            'isAdmin'                => $isAdmin,
            'config'                 => $config,
            // Surfaced as a top-level camelCase property so the Vue settings
            // store can read it without parsing the raw config map (REQ-LM-002).
            'leadStaleThresholdDays' => (int) ($config['lead_stale_threshold_days'] ?? 14),
        ];

        if ($isAdmin === true) {
            $response['objectenAccess'] = $this->objectenAccessService->getAccessMap();
            $response['apiTokens']      = $this->apiAuthService->listTokens();
            $response['oauthConfig']    = $this->apiAuthService->getOAuthConfig();
            $response['mcpConfig']      = $this->apiAuthService->getMcpConfig();
        }

        return new JSONResponse(data: $response);

    }//end index()

    /**
     * Get the full Objects API access map and check the current user's access to a schema.
     *
     * Returns the full map for admins; for non-admins returns only the access check result
     * for the requested schema.
     *
     * @return JSONResponse The access map or per-schema access check result.
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-3.1
     */
    #[NoAdminRequired]
    public function getObjectenAccess(): JSONResponse
    {
        $schemaSlug = $this->request->getParam('schema', '');
        $user       = $this->userSession->getUser();
        $isAdmin    = $user !== null && $this->groupManager->isAdmin($user->getUID());

        if ($isAdmin === true) {
            return new JSONResponse(
                data: [
                    'success'        => true,
                    'objectenAccess' => $this->objectenAccessService->getAccessMap(),
                ]
            );
        }

        if ($schemaSlug === '' || $user === null) {
            return new JSONResponse(data: ['message' => 'schema parameter is required'], statusCode: Http::STATUS_BAD_REQUEST);
        }

        $allowed = $this->objectenAccessService->isAllowed(
            schemaSlug: $schemaSlug,
            userId: $user->getUID()
        );

        return new JSONResponse(
            data: [
                'success' => true,
                'allowed' => $allowed,
                'schema'  => $schemaSlug,
            ]
        );

    }//end getObjectenAccess()

    /**
     * Save per-schema Objects API access groups.
     *
     * @return JSONResponse The updated access map.
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-3.1
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function saveObjectenAccess(): JSONResponse
    {
        $schemaSlug = $this->request->getParam('schemaSlug', '');
        $groupIds   = $this->request->getParam('groupIds', []);

        if ($schemaSlug === '') {
            return new JSONResponse(data: ['message' => 'schemaSlug is required'], statusCode: Http::STATUS_BAD_REQUEST);
        }

        $normalizedGroupIds = [];
        if (is_array($groupIds) === true) {
            $normalizedGroupIds = $groupIds;
        }

        $this->objectenAccessService->setSchemaAccess(
            schemaSlug: $schemaSlug,
            groupIds: $normalizedGroupIds
        );

        return new JSONResponse(
            data: [
                'success'        => true,
                'objectenAccess' => $this->objectenAccessService->getAccessMap(),
            ]
        );

    }//end saveObjectenAccess()

    /**
     * List all API tokens (metadata only — no hashes).
     *
     * @return JSONResponse List of token metadata.
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-3.2
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function listTokens(): JSONResponse
    {
        return new JSONResponse(
            data: [
                'success' => true,
                'tokens'  => $this->apiAuthService->listTokens(),
            ]
        );

    }//end listTokens()

    /**
     * Generate a new API token. Returns the plaintext token ONCE.
     *
     * @return JSONResponse The new token metadata including plaintext (one-time only).
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-3.2
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function generateToken(): JSONResponse
    {
        $label = $this->request->getParam('label', '');

        if ($label === '') {
            return new JSONResponse(data: ['message' => 'label is required'], statusCode: Http::STATUS_BAD_REQUEST);
        }

        $token = $this->apiAuthService->generateToken(label: $label);

        return new JSONResponse(data: ['success' => true, 'token' => $token]);

    }//end generateToken()

    /**
     * Revoke an API token by ID.
     *
     * @param string $id The token UUID.
     *
     * @return JSONResponse Success response.
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-3.2
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function revokeToken(string $id): JSONResponse
    {
        $this->apiAuthService->revokeToken(id: $id);

        return new JSONResponse(data: ['success' => true]);

    }//end revokeToken()

    /**
     * Save OAuth 2.0 configuration.
     *
     * @return JSONResponse The saved OAuth config (no secret).
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-3.3
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function saveOAuth(): JSONResponse
    {
        $data = $this->request->getParams();
        $this->apiAuthService->saveOAuthConfig(config: $data);

        return new JSONResponse(
            data: [
                'success'     => true,
                'oauthConfig' => $this->apiAuthService->getOAuthConfig(),
            ]
        );

    }//end saveOAuth()

    /**
     * Save MCP server configuration.
     *
     * @return JSONResponse The saved MCP config (no secrets).
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-3.4
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function saveMcp(): JSONResponse
    {
        $data = $this->request->getParams();
        $this->apiAuthService->saveMcpConfig(config: $data);

        return new JSONResponse(
            data: [
                'success'   => true,
                'mcpConfig' => $this->apiAuthService->getMcpConfig(),
            ]
        );

    }//end saveMcp()

    /**
     * Update Pipelinq settings.
     *
     * Admin-only endpoint (requires admin settings permission). The index()
     * method carries the read permission so non-admin users can read settings.
     *
     * @return JSONResponse The updated settings response.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-3
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
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
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-8
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
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
            $this->logger->error('SettingsController::reimport failed', ['exception' => $e]);
            return new JSONResponse(
                    [
                        'success' => false,
                        'message' => $this->l10n->t('An unexpected error occurred'),
                        'error'   => $e->getMessage(),
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
     * @spec openspec/changes/reverse-2026-05-26-be-settings/tasks.md#task-7
     */
    #[NoAdminRequired]
    public function getUserSettings(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Not logged in'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        return new JSONResponse(
            data: $this->settingsService->getUserSettings(userId: $user->getUID())
        );
    }//end getUserSettings()

    /**
     * Update user settings for the current user.
     *
     * @return JSONResponse The updated user settings response.
     *
     * @spec openspec/changes/reverse-2026-05-26-be-settings/tasks.md#task-8
     */
    #[NoAdminRequired]
    public function updateUserSettings(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Not logged in'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $data = $this->request->getParams();

        return new JSONResponse(
            data: $this->settingsService->updateUserSettings(userId: $user->getUID(), data: $data)
        );
    }//end updateUserSettings()
}//end class
