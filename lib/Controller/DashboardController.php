<?php

/**
 * Pipelinq DashboardController.
 *
 * Controller for serving the Pipelinq SPA dashboard page.
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Settings\MenuStructure;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use OCP\IRequest;

/**
 * Controller for the Pipelinq dashboard.
 *
 * @spec openspec/specs/dashboard/spec.md#requirement-crm-dashboard-layout
 */
class DashboardController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param IInitialState $initialState The initial state the page hands to the frontend.
	 * @param IAppConfig $appConfig The app configuration.
	 * @param MenuStructure $menuStructure How the stored structure settings read.
	 * @param IAppManager $appManager Reads the installed app version.
	 */
	public function __construct(
		IRequest $request,
		private readonly IInitialState $initialState,
		private readonly IAppConfig $appConfig,
		private readonly MenuStructure $menuStructure,
		private readonly IAppManager $appManager,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Render the main dashboard page.
	 *
	 * The structure settings travel with the page as initial state. The
	 * frontend builds its navigation before anything renders, so it has to
	 * know the structure and the modules without a round trip.
	 *
	 * The installed version travels as `version`: the user settings footer
	 * prints it, and the bundle reads it at run time because a build cannot
	 * know the version it is released as (scripts/appVersionDefine.js).
	 *
	 * @return TemplateResponse The template response.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @spec            openspec/changes/reverse-2026-05-26-be-dashboard-pipeline/tasks.md#task-1
	 * @spec            openspec/changes/simple-structure-profile/specs/navigation-ia/spec.md#REQ-NIA-101
	 * @spec            openspec/changes/simple-tour-and-readable-labels/specs/navigation-ia/spec.md#requirement-the-user-settings-show-the-installed-app-version-req-nia-108
	 */
	public function page(): TemplateResponse {
		$this->initialState->provideInitialState(
			MenuStructure::KEY,
			$this->menuStructure->normalise(
				stored: $this->appConfig->getValueString(Application::APP_ID, MenuStructure::KEY, '')
			)
		);
		$this->initialState->provideInitialState(
			MenuStructure::MODULES_KEY,
			$this->menuStructure->normaliseModules(
				stored: $this->appConfig->getValueString(Application::APP_ID, MenuStructure::MODULES_KEY, '')
			)
		);

		$this->initialState->provideInitialState(
			'version',
			$this->appManager->getAppVersion(appId: Application::APP_ID)
		);

		return new TemplateResponse(Application::APP_ID, 'index');
	}//end page()
}//end class
