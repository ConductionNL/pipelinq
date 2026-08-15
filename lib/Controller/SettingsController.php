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
 * @spec openspec/specs/admin-settings/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
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
class SettingsController extends Controller {

	/**
	 * The OpenRegister object service.
	 *
	 * @var \OCA\OpenRegister\Service\ObjectServiceInterface|null The OpenRegister object service.
	 */
	private ?\OCA\OpenRegister\Contract\ObjectServiceInterface $objectService = null;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param ContainerInterface $container The container.
	 * @param IAppManager $appManager The app manager.
	 * @param IGroupManager $groupManager The group manager.
	 * @param SettingsService $settingsService The settings service.
	 * @param IUserSession $userSession The user session.
	 * @param IL10N $l10n The localization service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
		private readonly IGroupManager $groupManager,
		private SettingsService $settingsService,
		private IUserSession $userSession,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Attempts to retrieve the OpenRegister service from the container.
	 *
	 * @return \OCA\OpenRegister\Service\ObjectServiceInterface|null The OpenRegister service if available, null otherwise.
	 * @throws \RuntimeException If the service is not available.
	 * @spec   openspec/changes/reverse-2026-05-26-be-settings/tasks.md#task-6
	 */
	public function getObjectService(): ?\OCA\OpenRegister\Contract\ObjectServiceInterface {
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
	public function getConfigurationService(): ?\OCA\OpenRegister\Service\ConfigurationService {
		if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
			$configurationService = $this->container->get('OCA\OpenRegister\Service\ConfigurationService');
			return $configurationService;
		}

		throw new RuntimeException('Configuration service is not available.');
	}//end getConfigurationService()

	/**
	 * Get current Pipelinq settings.
	 *
	 * @return JSONResponse The settings response.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 * @spec openspec/changes/admin-settings/tasks.md#task-3
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$user = $this->userSession->getUser();
		$isAdmin = $user !== null && $this->groupManager->isAdmin($user->getUID());

		$config = $this->settingsService->getSettings();
		$response = [
			'success' => true,
			'openRegisters' => in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()),
			'isAdmin' => $isAdmin,
			'config' => $config,
			// Surfaced as a top-level camelCase property so the Vue settings
			// store can read it without parsing the raw config map (REQ-LM-002).
			'leadStaleThresholdDays' => (int)($config['lead_stale_threshold_days'] ?? 14),
		];

		return new JSONResponse(data: $response);
	}//end index()

	/**
	 * Update Pipelinq settings — the canonical instance-wide write.
	 *
	 * This is the ADR-066 canonical write verb, matching
	 * {@see \OCA\OpenRegister\AppHost\Controller\GenericSettingsControllerBase::update()}.
	 * Pipelinq does NOT adopt `\OCA\OpenRegister\AppHost\Routes::standard()`,
	 * and it ships its own SettingsController — so
	 * `AppHost\Bootstrap::aliasControllerUnlessLeafDefinesIt()` never aliases
	 * the generic in and this leaf owes the method itself. Measured on the dev
	 * instance 2026-08-08, before this change: `PUT /api/settings` answered
	 * **405 Method Not Allowed** (no PUT route existed), not a 500.
	 *
	 * Scope: INSTANCE-WIDE. The write goes to
	 * {@see SettingsService::updateSettings()}, which persists the app-config
	 * map (`register`, `*_schema`, `export.*`, thresholds, …) shared by every
	 * user of the instance. It deliberately does NOT touch the per-user
	 * surface served by {@see updateUserSettings()} — that is a different
	 * scope with a different (non-admin) auth posture.
	 *
	 * Auth: the AuthorizedAdminSetting posture, identical to {@see create()}.
	 * An instance-wide write must never inherit the non-admin posture of the
	 * per-user write or of the {@see index()} read.
	 *
	 * Attribute syntax is deliberately NOT written out in this docblock:
	 * gate-5 (route-auth) decides by grepping the lines above the signature,
	 * so a comment quoting the attribute would satisfy the gate on its own and
	 * a later deletion of the real attribute would still report PASS. The
	 * posture is asserted properly, by reflection, in
	 * tests/Unit/Controller/SettingsControllerWriteTest.php.
	 *
	 * @return JSONResponse The updated settings response.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function update(): JSONResponse {
		$data = $this->request->getParams();
		$config = $this->settingsService->updateSettings($data);

		return new JSONResponse(
			[
				'success' => true,
				'config' => $config,
			]
		);
	}//end update()

	/**
	 * Legacy alias for {@see update()} — `POST /api/settings`.
	 *
	 * The canonical AppHost route table keeps `settings#create` for the
	 * fleet's pre-ADR-066 `index/create/load` dialect, and pipelinq has live
	 * callers on it (`src/store/modules/settings.js::saveSettings()` and
	 * `src/views/settings/ExportConfigurationSettings.vue::save()`), so the
	 * POST verb stays reachable and behaviourally identical (ADR-029).
	 *
	 * The attribute is repeated deliberately: Nextcloud's SecurityMiddleware
	 * evaluates the attributes of the DISPATCHED method only, so delegating to
	 * `update()` does not carry `update()`'s posture across.
	 *
	 * @return JSONResponse The updated settings response.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function create(): JSONResponse {
		return $this->update();
	}//end create()

	/**
	 * Re-import the Pipelinq configuration from the JSON file.
	 *
	 * @return JSONResponse The re-import result.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function reimport(): JSONResponse {
		try {
			$result = $this->settingsService->loadSettings(force: true);

			return new JSONResponse(
				[
					'success' => true,
					'message' => $this->l10n->t('Configuration re-imported successfully'),
					'config' => $this->settingsService->getSettings(),
					'result' => [
						'registers' => count($result['registers'] ?? []),
						'schemas' => count($result['schemas'] ?? []),
					],
				]
			);
		} catch (\Exception $e) {
			$this->logger->error('SettingsController::reimport failed', ['exception' => $e]);
			return new JSONResponse(
				[
					'success' => false,
					'message' => $this->l10n->t('An unexpected error occurred'),
					'error' => $e->getMessage(),
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
	public function getUserSettings(): JSONResponse {
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
	public function updateUserSettings(): JSONResponse {
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
