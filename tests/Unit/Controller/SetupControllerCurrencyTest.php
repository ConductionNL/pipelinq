<?php

/**
 * SetupController: the wizard is done once a reporting currency is chosen.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/first-time-setup/specs/first-time-setup/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\SetupController;
use OCA\Pipelinq\Service\Demo\DemoRegisterImporter;
use OCA\Pipelinq\Service\DemoSeedService;
use OCA\Pipelinq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The currency is the one step the wizard waits for.
 *
 * WHY THIS FILE EXISTS. `status()` decides whether the wizard still covers the
 * app. The other setup tests pin the step list, the example data and the
 * close button; none of them said what makes the wizard done.
 *
 * @covers \OCA\Pipelinq\Controller\SetupController
 *
 * @spec openspec/changes/first-time-setup/specs/first-time-setup/spec.md
 */
class SetupControllerCurrencyTest extends TestCase {
	/**
	 * The app config the controller reads and writes.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * What the controller wrote, by key.
	 *
	 * @var array<string, string>
	 */
	private array $written = [];

	/**
	 * Reset the in-memory config.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->config  = [];
		$this->written = [];
	}//end setUp()

	/**
	 * Build the controller on an app config that keeps what it is given.
	 *
	 * @param array<string, mixed> $params The request body.
	 *
	 * @return SetupController
	 */
	private function controller(array $params = []): SetupController {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')
			->willReturnCallback(function (string $app, string $key, string $default = ''): string {
				return ($this->config[$key] ?? $default);
			});
		$appConfig->method('setValueString')
			->willReturnCallback(function (string $app, string $key, string $value): bool {
				$this->config[$key]  = $value;
				$this->written[$key] = $value;

				return true;
			});

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')
			->willReturnCallback(static function (string $key, $default = null) use ($params) {
				return ($params[$key] ?? $default);
			});

		$demoSeed = $this->createMock(DemoSeedService::class);
		$demoSeed->method('listChoices')->willReturn([]);

		return new SetupController(
			'pipelinq',
			$request,
			$appConfig,
			$this->createMock(SettingsService::class),
			$demoSeed,
			$this->createMock(IAppManager::class),
			new NullLogger(),
			$this->createMock(DemoRegisterImporter::class)
		);
	}//end controller()

	/**
	 * Without a currency the wizard is not done, and no completion is stored,
	 * whatever the optional steps hold.
	 *
	 * @return void
	 */
	public function testTheWizardWaitsForTheCurrency(): void {
		$this->config['receipt_company_name'] = 'Zuiddrecht Sportcentrum';
		$this->config['demo_dataset']         = 'none';

		$status = $this->controller()->status()->getData();

		$this->assertFalse($status['completed']);
		$this->assertFalse($status['steps']['currency']['done']);
		$this->assertArrayNotHasKey('setup_completed_version', $this->written);
	}//end testTheWizardWaitsForTheCurrency()

	/**
	 * The currency step's own save makes the wizard done: the status says so
	 * and stores the setup version, with every optional step still open.
	 *
	 * @return void
	 */
	public function testChoosingACurrencyCompletesTheWizard(): void {
		$saved = $this->controller(['currency' => 'USD', '_route' => 'pipelinq.setup.saveConfig'])->saveConfig();
		$this->assertTrue($saved->getData()['success']);
		$this->assertSame('USD', $this->config['currency']);
		$this->assertArrayNotHasKey('_route', $this->written);

		$status = $this->controller()->status()->getData();

		$this->assertTrue($status['completed']);
		$this->assertTrue($status['steps']['currency']['done']);
		$this->assertFalse($status['steps']['organisation']['done']);
		$this->assertFalse($status['steps']['demo-data']['done']);
		$this->assertSame((string)$status['version'], $this->written['setup_completed_version']);
	}//end testChoosingACurrencyCompletesTheWizard()

	/**
	 * The manifest asks for the currency and for nothing else: it is the one
	 * required step, and it writes the `currency` key the status reads.
	 *
	 * @return void
	 */
	public function testTheCurrencyIsTheOnlyRequiredStepOfTheManifest(): void {
		$manifest = json_decode((string)file_get_contents(__DIR__ . '/../../../src/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
		$steps    = $manifest['setup']['steps'];

		$required = array_values(array_filter($steps, static fn (array $step): bool => ($step['required'] ?? false) === true));

		$this->assertSame(['currency'], array_column($required, 'id'));
		$this->assertSame('currency', $required[0]['configKey']);
		$this->assertSame('setup_completed_version', $manifest['setup']['completionConfigKey']);
	}//end testTheCurrencyIsTheOnlyRequiredStepOfTheManifest()
}//end class
