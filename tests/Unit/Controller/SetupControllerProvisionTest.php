<?php

/**
 * SetupController: the provisioning action, a throwing seed and the optional steps.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\SetupController;
use OCA\Pipelinq\Kernel\Contracts\DemoRegisterImporterInterface;
use OCA\Pipelinq\Kernel\Contracts\DemoSeedServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\SettingsServiceInterface;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The `provision-register` action, a seed that throws, and the optional till step.
 *
 * @covers \OCA\Pipelinq\Controller\SetupController
 */
class SetupControllerProvisionTest extends TestCase {

	/**
	 * Stored app-config values.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * App-config values written by the controller.
	 *
	 * @var array<string, string>
	 */
	private array $written = [];

	/**
	 * Settings service mock.
	 *
	 * @var SettingsServiceInterface&MockObject
	 */
	private SettingsServiceInterface&MockObject $settings;

	/**
	 * Demo seed service mock.
	 *
	 * @var DemoSeedServiceInterface&MockObject
	 */
	private DemoSeedServiceInterface&MockObject $demoSeed;

	/**
	 * App manager mock.
	 *
	 * @var IAppManager&MockObject
	 */
	private IAppManager&MockObject $appManager;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->config = [];
		$this->written = [];
		$this->settings = $this->createMock(SettingsServiceInterface::class);
		$this->demoSeed = $this->createMock(DemoSeedServiceInterface::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The controller under test.
	 *
	 * @return SetupController
	 */
	private function controller(): SetupController {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')
			->willReturnCallback(fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default));
		$appConfig->method('setValueString')
			->willReturnCallback(function (string $app, string $key, string $value): bool {
				$this->written[$key] = $value;
				return true;
			});

		$importer = $this->createMock(DemoRegisterImporterInterface::class);
		$importer->method('import')->willReturn(['imported' => 0, 'skipped' => 0]);

		return new SetupController(
			'pipelinq',
			$this->createMock(IRequest::class),
			$appConfig,
			$this->settings,
			$this->demoSeed,
			$this->appManager,
			$this->logger,
			$importer
		);
	}//end controller()

	/**
	 * Without OpenRegister nothing is provisioned and the step says why.
	 *
	 * @return void
	 */
	public function testProvisioningWithoutOpenRegisterIsAPreconditionFailure(): void {
		$this->appManager->expects($this->once())->method('isInstalled')->with('openregister')->willReturn(false);
		$this->settings->expects($this->never())->method('loadSettings');
		$this->settings->expects($this->never())->method('createDefaultPipelines');

		$response = $this->controller()->runAction('provision-register');

		$this->assertSame(Http::STATUS_PRECONDITION_FAILED, $response->getStatus());
		$this->assertSame(
			[
				'success' => false,
				'message' => 'OpenRegister is not installed — install and enable it, then run this step.',
			],
			$response->getData()
		);
	}//end testProvisioningWithoutOpenRegisterIsAPreconditionFailure()

	/**
	 * Provisioning imports without force, creates the defaults and counts what it imported.
	 *
	 * @return void
	 */
	public function testProvisioningReportsTheImportedRegistersAndSchemas(): void {
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->settings->expects($this->once())->method('loadSettings')->with(false)
			->willReturn(['registers' => ['pipelinq'], 'schemas' => ['lead', 'deal', 'contact']]);
		$this->settings->expects($this->once())->method('createDefaultPipelines');
		$this->settings->expects($this->once())->method('createDefaultSkills');

		$response = $this->controller()->runAction('provision-register');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			[
				'success' => true,
				'message' => 'Provisioned 1 register(s) and 3 schema(s); default pipelines and skills are ready.',
			],
			$response->getData()
		);
	}//end testProvisioningReportsTheImportedRegistersAndSchemas()

	/**
	 * An import result without registers or schemas counts as zero of each.
	 *
	 * @return void
	 */
	public function testProvisioningCountsMissingListsAsZero(): void {
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->settings->method('loadSettings')->willReturn([]);

		$data = $this->controller()->runAction('provision-register')->getData();

		$this->assertSame(
			'Provisioned 0 register(s) and 0 schema(s); default pipelines and skills are ready.',
			$data['message']
		);
	}//end testProvisioningCountsMissingListsAsZero()

	/**
	 * A failing import is logged and answered with a 500, and no defaults are created.
	 *
	 * @return void
	 */
	public function testAFailedProvisioningIsLoggedAndReported(): void {
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->settings->method('loadSettings')->willThrowException(new RuntimeException('schema clash'));
		$this->settings->expects($this->never())->method('createDefaultPipelines');
		$this->logger->expects($this->once())->method('error')
			->with('Pipelinq setup provisioning failed', ['exception' => 'schema clash']);

		$response = $this->controller()->runAction('provision-register');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['success' => false, 'message' => 'Provisioning failed: schema clash'], $response->getData());
	}//end testAFailedProvisioningIsLoggedAndReported()

	/**
	 * A seed that throws is logged, answered with a 500 and leaves the step open.
	 *
	 * @return void
	 */
	public function testASeedThatThrowsLeavesTheStepOpen(): void {
		$this->demoSeed->method('seed')->willThrowException(new RuntimeException('register missing'));
		$this->logger->expects($this->once())->method('error')
			->with('Pipelinq demo seed failed', ['exception' => 'register missing']);

		$response = $this->controller()->runAction('seed-demo-data');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['success' => false, 'message' => 'Demo seed failed: register missing'], $response->getData());
		$this->assertSame([], $this->written);
	}//end testASeedThatThrowsLeavesTheStepOpen()

	/**
	 * The till step is done once one of its fields is set, the integrations step stays open.
	 *
	 * @return void
	 */
	public function testTheTillStepIsDoneOnceOneOfItsFieldsIsSet(): void {
		$this->demoSeed->method('listChoices')->willReturn([]);
		$this->config['receipt_email_sender'] = 'kassa@example.org';

		$steps = $this->controller()->status()->getData()['steps'];

		$this->assertTrue($steps['pos']['done']);
		$this->assertFalse($steps['integrations']['done']);
		$this->assertFalse($steps['currency']['done']);
	}//end testTheTillStepIsDoneOnceOneOfItsFieldsIsSet()
}//end class
