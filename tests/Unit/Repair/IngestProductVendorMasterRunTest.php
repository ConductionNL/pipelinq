<?php

/**
 * Unit tests for the IngestProductVendorMaster repair step entry point.
 *
 * Covers run(): the skips when OpenRegister is missing, the export is unset or
 * it is not JSON, the persisted FK maps after an ingest, and a failed write
 * reported as a warning instead of breaking the upgrade.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Repair;

use OCA\Pipelinq\Kernel\Contracts\ContactVcardServiceInterface;
use OCA\Pipelinq\Repair\IngestProductVendorMaster;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The run() entry point of the PVM ingest.
 */
#[CoversClass(IngestProductVendorMaster::class)]
final class IngestProductVendorMasterRunTest extends TestCase {

	/**
	 * The app manager.
	 *
	 * @var IAppManager&MockObject
	 */
	private IAppManager&MockObject $appManager;

	/**
	 * The app configuration.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * The DI container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The repair output.
	 *
	 * @var IOutput&MockObject
	 */
	private IOutput&MockObject $output;

	/**
	 * Build the collaborators; OpenRegister is installed unless a test says otherwise.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->appManager = $this->createMock(IAppManager::class);
		$this->appManager->method('getInstalledApps')->willReturn(['openregister', 'pipelinq']);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->output = $this->createMock(IOutput::class);
	}//end setUp()

	/**
	 * The step under test.
	 *
	 * @param IAppManager|null $appManager An app manager to use instead of the default.
	 *
	 * @return IngestProductVendorMaster
	 */
	private function repair(?IAppManager $appManager=null): IngestProductVendorMaster {
		return new IngestProductVendorMaster(
			$appManager ?? $this->appManager,
			$this->appConfig,
			$this->createMock(ContactVcardServiceInterface::class),
			$this->container,
			$this->logger,
		);
	}//end repair()

	/**
	 * Answer app-config reads from a map; every other key reads empty.
	 *
	 * @param array<string,string> $values Key => value.
	 *
	 * @return void
	 */
	private function config(array $values): void {
		$this->appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default=''): string => ($values[$key] ?? $default)
		);
	}//end config()

	public function testTheNameSaysTheStepIsIdempotent(): void {
		$this->assertSame(
			'Ingest shillinq product and vendor master-data export into pipelinq (idempotent)',
			$this->repair()->getName()
		);
	}//end testTheNameSaysTheStepIsIdempotent()

	public function testWithoutOpenRegisterTheStepWarnsAndReadsNothing(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['pipelinq']);
		$this->appConfig->expects($this->never())->method('getValueString');
		$this->appConfig->expects($this->never())->method('setValueString');
		$this->output->expects($this->once())->method('warning')->with('OpenRegister not installed — skipping PVM ingest.');
		$this->output->expects($this->never())->method('info');

		$this->repair(appManager: $appManager)->run($this->output);
	}//end testWithoutOpenRegisterTheStepWarnsAndReadsNothing()

	public function testWithoutAnExportTheStepIsANoOp(): void {
		$this->appConfig->expects($this->once())->method('getValueString')
			->with('pipelinq', 'shillinq_pvm_export', '')
			->willReturn('');
		$this->appConfig->expects($this->never())->method('setValueString');
		$this->output->expects($this->once())->method('info')->with('No shillinq PVM export found in app-config — skipping ingest (no-op).');
		$this->output->expects($this->never())->method('warning');

		$this->repair()->run($this->output);
	}//end testWithoutAnExportTheStepIsANoOp()

	public function testAnExportThatIsNotJsonIsSkipped(): void {
		$this->config(['shillinq_pvm_export' => '{not json']);
		$this->appConfig->expects($this->never())->method('setValueString');
		$this->container->expects($this->never())->method('get');
		$this->output->expects($this->once())->method('warning')->with('shillinq PVM export is not valid JSON — skipping ingest.');

		$this->repair()->run($this->output);
	}//end testAnExportThatIsNotJsonIsSkipped()

	public function testAJsonScalarExportIsSkippedToo(): void {
		$this->config(['shillinq_pvm_export' => '"products"']);
		$this->appConfig->expects($this->never())->method('setValueString');
		$this->output->expects($this->once())->method('warning')->with('shillinq PVM export is not valid JSON — skipping ingest.');

		$this->repair()->run($this->output);
	}//end testAJsonScalarExportIsSkippedToo()

	public function testAnIngestReportsTheCountsAndPersistsBothMaps(): void {
		// One product without a ref is skipped; the configured product schema is missing, so nothing maps.
		$this->config(['shillinq_pvm_export' => json_encode(['products' => [['sku' => 'S-1'], ['ref' => 'P-1', 'sku' => 'S-2']], 'vendors' => []])]);
		$this->container->method('get')->willReturn(new \stdClass());

		$writes = [];
		$this->appConfig->expects($this->exactly(2))->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$writes): bool {
				$writes[] = [$app, $key, $value];
				return true;
			}
		);
		$this->output->expects($this->once())->method('info')->with('PVM ingest: 0 products mapped, 0 vendors mapped.');
		$this->output->expects($this->never())->method('warning');

		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(
			static function (string $message) use (&$warnings): void {
				$warnings[] = $message;
			}
		);

		$this->repair()->run($this->output);

		$this->assertSame(
			[
				['pipelinq', 'shillinq_pvm_product_map', '[]'],
				['pipelinq', 'shillinq_pvm_vendor_map', '[]'],
			],
			$writes
		);
		$this->assertSame(['PVM ingest: skipping product with no ref', 'PVM ingest: product register/schema not configured'], $warnings);
	}//end testAnIngestReportsTheCountsAndPersistsBothMaps()

	public function testAFailedWriteIsReportedAndDoesNotThrow(): void {
		$this->config(['shillinq_pvm_export' => '{"products":[],"vendors":[]}']);
		$this->appConfig->expects($this->once())->method('setValueString')->willThrowException(new RuntimeException('config table locked'));
		$this->output->expects($this->once())->method('info')->with('PVM ingest: 0 products mapped, 0 vendors mapped.');
		$this->output->expects($this->once())->method('warning')->with('PVM ingest failed: config table locked');
		$this->logger->expects($this->once())->method('error')->with('PVM ingest failed', ['exception' => 'config table locked']);

		$this->repair()->run($this->output);
	}//end testAFailedWriteIsReportedAndDoesNotThrow()
}//end class
