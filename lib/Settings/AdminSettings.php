<?php

/**
 * Pipelinq AdminSettings.
 *
 * Admin settings form for the Pipelinq application.
 *
 * @category Settings
 * @package  OCA\Pipelinq\Settings
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

namespace OCA\Pipelinq\Settings;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\IDelegatedSettings;

/**
 * Admin settings for Pipelinq.
 *
 * Implements IDelegatedSettings so #[AuthorizedAdminSetting(AdminSettings::class)]
 * can scope the controllers that mutate Pipelinq configuration (SetupController).
 *
 * @spec openspec/specs/admin-settings/spec.md#REQ-AS-011
 */
class AdminSettings implements IDelegatedSettings {
	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings service.
	 * @param IAppManager $appManager The app manager.
	 * @param IInitialState $initialState The initial state service.
	 */
	public function __construct(
		private SettingsService $settingsService,
		private IAppManager $appManager,
		private IInitialState $initialState,
	) {
	}//end __construct()

	/**
	 * Get the admin settings form.
	 *
	 * @return TemplateResponse The settings form template.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function getForm(): TemplateResponse {
		$config = $this->settingsService->getSettings();
		$version = $this->appManager->getAppVersion(appId: Application::APP_ID);

		$this->initialState->provideInitialState('version', $version);

		return new TemplateResponse(
			Application::APP_ID,
			'settings/admin',
			[
				'config' => json_encode($config),
			]
		);
	}//end getForm()

	/**
	 * Get the settings section ID.
	 *
	 * @return string The section ID.
	 * @spec openspec/specs/admin-settings/spec.md#REQ-AS-011
	 */
	public function getSection(): string {
		return 'pipelinq';
	}//end getSection()

	/**
	 * Get the settings priority.
	 *
	 * @return int The priority.
	 * @spec openspec/specs/admin-settings/spec.md#REQ-AS-011
	 */
	public function getPriority(): int {
		return 10;
	}//end getPriority()

	/**
	 * Human-readable name of the delegated settings section.
	 *
	 * @return string|null The section name, or null to use the section default.
	 * @spec openspec/specs/admin-settings/spec.md#REQ-AS-011
	 */
	public function getName(): ?string {
		return null;
	}//end getName()

	/**
	 * App config keys an authorized (delegated) admin may manage.
	 *
	 * @return array<string,string[]> Map of appId to allowed config keys.
	 * @spec openspec/specs/admin-settings/spec.md#REQ-AS-011
	 */
	public function getAuthorizedAppConfig(): array {
		return [];
	}//end getAuthorizedAppConfig()
}//end class
