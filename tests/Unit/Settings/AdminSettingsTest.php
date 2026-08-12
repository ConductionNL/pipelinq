<?php

/**
 * Unit tests for AdminSettings.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use OCA\Pipelinq\Service\SettingsService;
use OCA\Pipelinq\Settings\AdminSettings;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AdminSettings.
 */
class AdminSettingsTest extends TestCase {

	/**
	 * Build an AdminSettings instance with all required mocks.
	 *
	 * @param SettingsService|null $settingsService Optional pre-configured mock.
	 * @param IAppManager|null $appManager Optional pre-configured mock.
	 *
	 * @return AdminSettings
	 */
	private function buildAdminSettings(
		?SettingsService $settingsService = null,
		?IAppManager $appManager = null,
	): AdminSettings {
		$settingsService = $settingsService ?? $this->createMock(SettingsService::class);
		$appManager = $appManager ?? $this->createMock(IAppManager::class);
		$initialState = $this->createMock(IInitialState::class);

		return new AdminSettings($settingsService, $appManager, $initialState);
	}//end buildAdminSettings()

	/**
	 * Test getForm returns a TemplateResponse.
	 *
	 * @return void
	 */
	public function testGetFormReturnsTemplateResponse(): void {
		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getSettings')->willReturn([]);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('1.0.0');

		$admin = $this->buildAdminSettings($settingsService, $appManager);

		$this->assertInstanceOf(TemplateResponse::class, $admin->getForm());
	}//end testGetFormReturnsTemplateResponse()

	/**
	 * Test getSection returns 'pipelinq'.
	 *
	 * @return void
	 */
	public function testGetSectionReturnsPipelinq(): void {
		$admin = $this->buildAdminSettings();
		$this->assertSame('pipelinq', $admin->getSection());
	}//end testGetSectionReturnsPipelinq()

	/**
	 * Test getPriority returns an integer.
	 *
	 * @return void
	 */
	public function testGetPriorityReturnsInt(): void {
		$admin = $this->buildAdminSettings();
		$this->assertIsInt($admin->getPriority());
	}//end testGetPriorityReturnsInt()

}//end class
