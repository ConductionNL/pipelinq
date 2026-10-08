<?php

/**
 * A closed setup wizard stays closed in every browser.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/setup-wizard-close-on-server/specs/first-time-setup/spec.md
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
 * Closing the wizard used to live in one browser's localStorage, so every
 * fresh browser opened it again. nextcloud-vue 2.71 posts the manifest's
 * `setup.dismissAction` and reads `dismissed` back from the status.
 *
 * @covers \OCA\Pipelinq\Controller\SetupController
 */
class SetupControllerDismissTest extends TestCase {

	/**
	 * The in-memory app config the controller reads and writes.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * Every key the controller wrote, in order.
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
	 * A wizard nobody closed reports `dismissed: false`.
	 *
	 * @return void
	 */
	public function testStatusSaysNotDismissedUntilSomeoneClosesTheWizard(): void {
		$status = $this->controller()->status()->getData();

		$this->assertArrayHasKey('dismissed', $status);
		$this->assertFalse($status['dismissed']);
	}//end testStatusSaysNotDismissedUntilSomeoneClosesTheWizard()

	/**
	 * The manifest's dismiss action records the setup version, and the status reports it.
	 *
	 * @return void
	 */
	public function testClosingTheWizardIsRecordedAndReportedAtTheSetupVersion(): void {
		$response = $this->controller(['finished' => false])->runAction('dismiss-setup');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['success']);

		$status = $this->controller()->status()->getData();
		$this->assertSame($status['version'], $status['dismissed']);
	}//end testClosingTheWizardIsRecordedAndReportedAtTheSetupVersion()

	/**
	 * Closing the wizard answers no step: it only stores the version.
	 *
	 * @return void
	 */
	public function testClosingTheWizardOverwritesNoChoice(): void {
		$this->config['demo_dataset'] = 'demo';
		$this->config['currency']     = 'EUR';

		$this->controller(['finished' => true])->runAction('dismiss-setup');

		$this->assertSame(['setup_dismissed_version'], array_keys($this->written));
		$this->assertSame('demo', $this->config['demo_dataset']);
		$this->assertSame('EUR', $this->config['currency']);

		// The organisation step was never answered and stays open.
		$status = $this->controller()->status()->getData();
		$this->assertFalse($status['steps']['organisation']['done']);
	}//end testClosingTheWizardOverwritesNoChoice()

	/**
	 * A stored value that is not a version reads as not dismissed.
	 *
	 * @return void
	 */
	public function testAGarbledRecordDoesNotKeepTheWizardClosed(): void {
		$this->config['setup_dismissed_version'] = 'yes';

		$this->assertFalse($this->controller()->status()->getData()['dismissed']);
	}//end testAGarbledRecordDoesNotKeepTheWizardClosed()

	/**
	 * The action the manifest names is one this controller answers.
	 *
	 * @return void
	 */
	public function testTheManifestDismissActionIsAnActionTheControllerAnswers(): void {
		$manifest = json_decode((string)file_get_contents(__DIR__ . '/../../../src/manifest.json'), true);
		$action   = ($manifest['setup']['dismissAction'] ?? '');

		$this->assertNotSame('', $action, 'manifest.setup.dismissAction is not declared');
		$this->assertSame(Http::STATUS_OK, $this->controller()->runAction($action)->getStatus());
	}//end testTheManifestDismissActionIsAnActionTheControllerAnswers()
}//end class
