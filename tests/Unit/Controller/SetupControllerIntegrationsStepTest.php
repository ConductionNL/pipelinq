<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\SetupController;
use OCA\Pipelinq\Service\DemoSeedService;
use OCA\Pipelinq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The optional integrations step has an answer that counts.
 *
 * With integriq installed, the step was met only by a Shillinq or XWiki URL.
 * An operator who uses neither had no way to finish it, so CnAppRoot reopened
 * the wizard on every fresh browser profile. Pipelinq's E2E seed caught it the
 * first time the job installed integriq.
 *
 * @covers \OCA\Pipelinq\Controller\SetupController
 *
 * @uses \OCA\Pipelinq\Support\FleetAppId
 */
class SetupControllerIntegrationsStepTest extends TestCase {
	/** @var array<string,string> */
	private array $written = [];

	/** @var array<string,string> */
	private array $config = [];

	protected function setUp(): void {
		$this->written = [];
		$this->config = [];
	}

	/**
	 * A controller over fake config, a fake request and a set of installed apps.
	 *
	 * @param array<string,mixed> $params The request body.
	 * @param string[] $installed The app ids the instance has.
	 *
	 * @return SetupController
	 */
	private function controller(array $params = [], array $installed = []): SetupController {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')
			->willReturnCallback(function (string $app, string $key, string $default = ''): string {
				return ($this->written[$key] ?? $this->config[$key] ?? $default);
			});
		$appConfig->method('setValueString')
			->willReturnCallback(function (string $app, string $key, string $value): bool {
				$this->written[$key] = $value;
				return true;
			});

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')
			->willReturnCallback(static function (string $key, $default = null) use ($params) {
				return ($params[$key] ?? $default);
			});

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')
			->willReturnCallback(static fn (string $app): bool => in_array($app, $installed, true));
		$appManager->method('isEnabledForUser')
			->willReturnCallback(static fn (string $app): bool => in_array($app, $installed, true));

		$demoSeed = $this->createMock(DemoSeedService::class);
		$demoSeed->method('listChoices')->willReturn([]);

		return new SetupController(
			'pipelinq',
			$request,
			$appConfig,
			$this->createMock(SettingsService::class),
			$demoSeed,
			$appManager,
			new NullLogger()
		);
	}

	/**
	 * The integrations step's done flag.
	 *
	 * @param SetupController $controller The controller to ask.
	 *
	 * @return bool
	 */
	private function integrationsDone(SetupController $controller): bool {
		return $controller->status()->getData()['steps']['integrations']['done'];
	}

	public function testUnansweredIsOutstandingWhenIntegriqIsInstalled(): void {
		$this->assertFalse($this->integrationsDone($this->controller(installed: ['integriq'])));
	}

	public function testNothingToConfigureWithoutEitherApp(): void {
		$this->assertTrue($this->integrationsDone($this->controller()));
	}

	public function testSavingBlankUrlsAnswersTheStep(): void {
		$save = $this->controller(params: ['shillinq_app_url' => '', 'xwiki_direct_url' => ''], installed: ['integriq']);
		$this->assertTrue($save->saveConfig()->getData()['success']);
		$this->assertSame('answered', $this->written['integrations_decided'] ?? null);

		$this->assertTrue($this->integrationsDone($this->controller(installed: ['integriq'])));
	}

	public function testAnotherStepsSaveDoesNotAnswerIt(): void {
		$this->controller(params: ['receipt_company_name' => 'Acme'], installed: ['integriq'])->saveConfig();
		$this->assertArrayNotHasKey('integrations_decided', $this->written);

		$this->assertFalse($this->integrationsDone($this->controller(installed: ['integriq'])));
	}

	public function testAUrlStillAnswersIt(): void {
		$this->config['xwiki_direct_url'] = 'https://wiki.example.test';

		$this->assertTrue($this->integrationsDone($this->controller(installed: ['integriq'])));
	}
}//end class
