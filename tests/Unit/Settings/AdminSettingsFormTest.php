<?php

/**
 * The admin settings form hands the frontend exactly what it reads.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use OCA\Pipelinq\Service\IntegrationDetector;
use OCA\Pipelinq\Service\Settings\MenuStructure;
use OCA\Pipelinq\Service\SettingsService;
use OCA\Pipelinq\Settings\AdminSettings;
use OCP\App\IAppManager;
use OCP\AppFramework\Services\IInitialState;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Initial state, template and the delegated-settings answers of the admin page.
 *
 * @covers \OCA\Pipelinq\Settings\AdminSettings
 * @uses   \OCA\Pipelinq\Service\Settings\MenuStructure
 */
class AdminSettingsFormTest extends TestCase {

	/**
	 * The settings double.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settings;

	/**
	 * The app manager double.
	 *
	 * @var IAppManager&MockObject
	 */
	private IAppManager&MockObject $appManager;

	/**
	 * The integration detector double.
	 *
	 * @var IntegrationDetector&MockObject
	 */
	private IntegrationDetector&MockObject $integrations;

	/**
	 * The initial state the page provided, keyed by name.
	 *
	 * @var array<string, mixed>
	 */
	private array $provided = [];

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settings     = $this->createMock(SettingsService::class);
		$this->appManager   = $this->createMock(IAppManager::class);
		$this->integrations = $this->createMock(IntegrationDetector::class);
	}//end setUp()

	/**
	 * The page under test, recording what it provides as initial state.
	 *
	 * @return AdminSettings
	 */
	private function admin(): AdminSettings {
		$initialState = $this->createMock(IInitialState::class);
		$initialState->method('provideInitialState')->willReturnCallback(
			function (string $key, mixed $data): void {
				$this->provided[$key] = $data;
			}
		);

		return new AdminSettings(
			$this->settings,
			$this->appManager,
			$initialState,
			new MenuStructure(),
			$this->integrations
		);
	}//end admin()

	/**
	 * The form provides version, the normalised menu settings and the detected integrations.
	 *
	 * @return void
	 */
	public function testTheFormProvidesVersionMenuAndDetectedIntegrations(): void {
		$detected = [
			'shillinq' => ['installed' => true, 'url' => 'https://cloud.example/index.php/apps/shillinq/'],
			'xwiki'    => ['available' => false, 'source' => 'none'],
		];
		$this->settings->method('getSettings')->willReturn([
			MenuStructure::KEY         => ' FULL ',
			MenuStructure::MODULES_KEY => 'marketing, nonsense,SALES',
		]);
		$this->appManager->expects(self::once())
			->method('getAppVersion')
			->with('pipelinq')
			->willReturn('3.4.5');
		$this->integrations->expects(self::once())->method('all')->willReturn($detected);

		$this->admin()->getForm();

		self::assertSame(
			['version', MenuStructure::KEY, MenuStructure::MODULES_KEY, 'detected_integrations'],
			array_keys($this->provided)
		);
		self::assertSame('3.4.5', $this->provided['version']);
		self::assertSame('full', $this->provided[MenuStructure::KEY]);
		// Unknown words are dropped; the order is the module list's, not the stored one.
		self::assertSame('sales,marketing', $this->provided[MenuStructure::MODULES_KEY]);
		self::assertSame($detected, $this->provided['detected_integrations']);
	}//end testTheFormProvidesVersionMenuAndDetectedIntegrations()

	/**
	 * Without stored menu settings the page falls back to the simple menu with no modules.
	 *
	 * @return void
	 */
	public function testMissingMenuSettingsFallBackToTheSimpleMenuWithNoModules(): void {
		$this->settings->method('getSettings')->willReturn([]);
		$this->appManager->method('getAppVersion')->willReturn('1.0.0');
		$this->integrations->method('all')->willReturn([]);

		$this->admin()->getForm();

		self::assertSame(MenuStructure::SIMPLE, $this->provided[MenuStructure::KEY]);
		self::assertSame('', $this->provided[MenuStructure::MODULES_KEY]);
	}//end testMissingMenuSettingsFallBackToTheSimpleMenuWithNoModules()

	/**
	 * The template is the app's admin settings template and carries the raw settings as JSON.
	 *
	 * @return void
	 */
	public function testTheTemplateCarriesTheSettingsAsJson(): void {
		$config = ['menu_structure' => 'full', 'vat_rates' => '{"high":21}', 'count' => 3];
		$this->settings->method('getSettings')->willReturn($config);
		$this->appManager->method('getAppVersion')->willReturn('1.0.0');
		$this->integrations->method('all')->willReturn([]);

		$response = $this->admin()->getForm();

		self::assertSame('pipelinq', $response->getApp());
		self::assertSame('settings/admin', $response->getTemplateName());
		self::assertSame(['config' => json_encode($config)], $response->getParams());
	}//end testTheTemplateCarriesTheSettingsAsJson()

	/**
	 * The delegated-settings answers: own section, priority 10, no name, no delegated config.
	 *
	 * @return void
	 */
	public function testTheDelegatedSettingsAnswers(): void {
		$admin = $this->admin();

		self::assertSame('pipelinq', $admin->getSection());
		self::assertSame(10, $admin->getPriority());
		self::assertNull($admin->getName());
		self::assertSame([], $admin->getAuthorizedAppConfig());
		self::assertSame([], $this->provided);
	}//end testTheDelegatedSettingsAnswers()
}//end class
