<?php

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
 * ADR-042 / ADR-111 — the example-data steps.
 *
 * 🔴 THIS FILE EXISTS BECAUSE SEEDING LEFT THE CHOICE OPEN. The step is a
 * choice followed by a run-action now, and `seed-demo-data` recorded only the
 * decision. So CI seeded 48 demo objects, the `demo-data` step stayed unmet,
 * and CnAppRoot reopened the wizard over every page — which is exactly what
 * the seed script's own assertion caught.
 *
 * @covers \OCA\Pipelinq\Controller\SetupController
 */
class SetupControllerDemoDataTest extends TestCase {
	private array $written = [];
	private array $config = [];
	private DemoSeedService $demoSeed;

	protected function setUp(): void {
		$this->written = [];
		$this->config = [];
		$this->demoSeed = $this->createMock(DemoSeedService::class);
	}

	private function controller(array $params = []): SetupController {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')
			->willReturnCallback(function (string $app, string $key, string $default = ''): string {
				return ($this->config[$key] ?? $default);
			});
		$appConfig->method('setValueString')
			->willReturnCallback(function (string $app, string $key, string $value): bool {
				$this->written[$key] = $value;

				return true;
			});

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		// 🔴 BOTH READERS, OR THE VALIDATION IS INVISIBLE TO THE TEST.
		// saveConfig() reads the dataset with getParam() and the rest of the
		// body with getParams(); a fake that answers only the second makes
		// every assertion about the first pass for the wrong reason.
		$request->method('getParam')
			->willReturnCallback(static function (string $key, $default = null) use ($params) {
				return ($params[$key] ?? $default);
			});

		return new SetupController(
			'pipelinq',
			$request,
			$appConfig,
			$this->createMock(SettingsService::class),
			$this->demoSeed,
			$this->createMock(IAppManager::class),
			new NullLogger()
		);
	}

	public function testStatusReportsBothExampleDataSteps(): void {
		$this->demoSeed->method('listChoices')->willReturn([]);

		$steps = $this->controller()->status()->getData()['steps'];

		// Absence is the defect this guards: a step the wizard is never told
		// about cannot be offered and cannot be completed.
		$this->assertArrayHasKey('demo-data', $steps);
		$this->assertArrayHasKey('load-demo-data', $steps);
		$this->assertFalse($steps['demo-data']['done']);
		$this->assertFalse($steps['load-demo-data']['done']);
	}

	public function testStatusCarriesTheOptionListTheChoiceStepReads(): void {
		// 🔴 THIS RESPONSE *IS* THE OPTION LIST. The step declares
		// `optionsSource: datasets` and carries no options of its own, so a
		// dataset missing here is a dataset nobody can pick.
		$this->demoSeed->method('listChoices')->willReturn([
			['id' => 'none', 'label' => 'None', 'description' => 'Nothing.', 'objectCount' => 0, 'icon' => 'CloseCircleOutline'],
			['id' => 'demo', 'label' => 'Example data', 'description' => 'A sales pipeline.', 'objectCount' => 0, 'icon' => 'DatabaseOutline'],
		]);

		$data = $this->controller()->status()->getData();

		$this->assertSame(['none', 'demo'], array_column($data['datasets'], 'id'));
	}

	public function testChoosingNoneClosesBothStepsWithoutRunningAnything(): void {
		$this->demoSeed->method('listChoices')->willReturn([]);
		$this->config['demo_dataset'] = 'none';

		$steps = $this->controller()->status()->getData()['steps'];

		$this->assertTrue($steps['demo-data']['done']);
		$this->assertTrue($steps['load-demo-data']['done']);
	}

	public function testAnUnknownDatasetIsRefusedRatherThanStored(): void {
		// Storing it would leave the seed step pointing at nothing, so the
		// failure would surface one step later with no clue why.
		$this->demoSeed->method('listChoices')->willReturn([
			['id' => 'none', 'label' => 'None', 'description' => '', 'objectCount' => 0, 'icon' => ''],
		]);

		$data = $this->controller(['demo_dataset' => 'atlantis'])->saveConfig()->getData();

		$this->assertFalse($data['success']);
		$this->assertSame([], $this->written);
	}

	public function testSkippingClosesBOTHStepsOrTheWizardNeverCloses(): void {
		$response = $this->controller()->runAction('skip-demo-data');

		$this->assertTrue($response->getData()['success']);
		$this->assertSame('skipped', $this->written['demo_data_decided'] ?? null);
		$this->assertSame('none', $this->written['demo_dataset'] ?? null, 'skipping IS choosing none');
	}

	public function testSeedingClosesTheChoiceStepToo(): void {
		// 🔴 THE REGRESSION. `seed-demo-data` used to record only the decision,
		// so the choice step stayed unmet after a successful seed and the
		// wizard covered every page. Seeding IS choosing the set.
		$this->demoSeed->method('seed')->willReturn(['success' => true, 'created' => ['deal' => 3], 'skipped' => []]);

		$data = $this->controller()->runAction('seed-demo-data')->getData();

		$this->assertTrue($data['success']);
		$this->assertSame('demo', $this->written['demo_dataset'] ?? null);
		$this->assertSame('seeded', $this->written['demo_data_decided'] ?? null);
	}

	public function testLoadingWithoutAChoiceRefusesRatherThanGuessing(): void {
		// 🔴 NO SILENT DEFAULT. Seeding because the operator clicked Run one
		// step early would plant example objects nobody asked for.
		$this->demoSeed->expects($this->never())->method('seed');

		$data = $this->controller()->runAction('load-demo-data')->getData();

		$this->assertFalse($data['success']);
		$this->assertSame([], $this->written);
	}

	public function testChoosingNoneAndThenRunningSeedsNothing(): void {
		$this->config['demo_dataset'] = 'none';
		$this->demoSeed->expects($this->never())->method('seed');

		$data = $this->controller()->runAction('load-demo-data')->getData();

		$this->assertTrue($data['success']);
		$this->assertStringContainsString('No example data', $data['message']);
	}

	public function testAFailedSeedIsReportedAndLeavesTheStepUNDECIDED(): void {
		// Recording the decision here would close the step for an operator who
		// asked for example data and received none.
		$this->config['demo_dataset'] = 'demo';
		$this->demoSeed->method('seed')->willReturn(['success' => false, 'message' => 'OpenRegister is not installed.']);

		$response = $this->controller()->runAction('load-demo-data');

		$this->assertFalse($response->getData()['success']);
		$this->assertSame([], $this->written);
	}
}
