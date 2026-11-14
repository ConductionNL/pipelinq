<?php

/**
 * Unit tests for MigratePosBookkeepingToShillinq.
 *
 * Verifies the guards (OpenRegister, register, ObjectService), that posted
 * outbound journals are projected onto their Z-report, that in-flight ones are
 * re-raised with their original idempotency key, that a Z-report already
 * raised or not found is skipped, and that the work runs under the system
 * identity.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Repair;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Repair\MigratePosBookkeepingToShillinq;
use OCA\Pipelinq\Service\PosBookkeepingService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The POS bookkeeping migration to the shillinq integration registry.
 */
class MigratePosBookkeepingToShillinqTest extends TestCase {

	/**
	 * @var IAppManager&MockObject
	 */
	private IAppManager&MockObject $appManager;

	/**
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * @var PosBookkeepingService&MockObject
	 */
	private PosBookkeepingService&MockObject $service;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * @var IOutput&MockObject
	 */
	private IOutput&MockObject $output;

	/**
	 * Set up mocks with OpenRegister installed and the register configured.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->appManager = $this->createMock(IAppManager::class);
		$this->appConfig  = $this->createMock(IAppConfig::class);
		$this->container  = $this->createMock(ContainerInterface::class);
		$this->service    = $this->createMock(PosBookkeepingService::class);
		$this->logger     = $this->createMock(LoggerInterface::class);
		$this->output     = $this->createMock(IOutput::class);
		$this->appManager->method('getInstalledApps')->willReturn(['openregister', 'pipelinq']);
		$this->appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($app === Application::APP_ID && $key === 'register') ? 'crm' : $default
		);
	}//end setUp()

	/**
	 * The repair step.
	 *
	 * @return MigratePosBookkeepingToShillinq
	 */
	private function step(): MigratePosBookkeepingToShillinq {
		return new MigratePosBookkeepingToShillinq($this->appManager, $this->appConfig, $this->container, $this->service, $this->logger);
	}//end step()

	/**
	 * A fluent object service holding rows per schema.
	 *
	 * @param array<string, array<int, mixed>> $rows Schema => rows.
	 *
	 * @return object
	 */
	private function objects(array $rows): object {
		$objects = new class {
			/** @var array<string, array<int, mixed>> */
			public array $rows = [];

			/** @var array<int, array<string, mixed>> */
			public array $saved = [];

			public string $schema = '';

			public int $systemRuns = 0;

			public bool $insideSystem = false;

			public ?\Throwable $saveFail = null;

			/**
			 * @param callable $work The work.
			 *
			 * @return void
			 */
			public function runAsSystem(callable $work): void {
				$this->systemRuns++;
				$this->insideSystem = true;
				$work();
				$this->insideSystem = false;
			}

			/**
			 * @param string $register The register.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}

			/**
			 * @param string $schema The schema.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}

			/**
			 * @param array<string, mixed> $config The query.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $config): array {
				$rows = $this->rows[$this->schema] ?? [];
				if ($rows instanceof \Throwable) {
					throw $rows;
				}

				return $rows;
			}

			/**
			 * @param array<string, mixed> $object   The object.
			 * @param array<int, string>   $extend   Extends.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param string               $uuid     The uuid.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, array $extend, string $register, string $schema, string $uuid): array {
				$this->saved[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid, 'system' => $this->insideSystem];
				if ($this->saveFail !== null) {
					throw $this->saveFail;
				}

				return $object;
			}
		};
		$objects->rows = $rows;
		$this->container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($objects);
		return $objects;
	}//end objects()

	/**
	 * Without OpenRegister the step warns and does nothing.
	 *
	 * @return void
	 */
	public function testSkipsWithoutOpenRegister(): void {
		$this->appManager = $this->createMock(IAppManager::class);
		$this->appManager->method('getInstalledApps')->willReturn(['pipelinq']);
		$this->container->expects($this->never())->method('get');
		$this->output->expects($this->once())->method('warning')->with('OpenRegister not installed — skipping POS bookkeeping migration.');

		$this->step()->run($this->output);
	}//end testSkipsWithoutOpenRegister()

	/**
	 * Without a configured register the step warns and does nothing.
	 *
	 * @return void
	 */
	public function testSkipsWithoutARegister(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturn('');
		$this->container->expects($this->never())->method('get');
		$this->output->expects($this->once())->method('warning')->with('Pipelinq register not configured — skipping POS bookkeeping migration.');

		$this->step()->run($this->output);
	}//end testSkipsWithoutARegister()

	/**
	 * An unresolvable ObjectService is logged and the step warns.
	 *
	 * @return void
	 */
	public function testSkipsWhenTheObjectServiceCannotBeResolved(): void {
		$this->container->method('get')->willThrowException(new \RuntimeException('no service'));
		$this->logger->expects($this->once())->method('warning')
			->with('POS bookkeeping migration: OpenRegister ObjectService not resolvable', ['exception' => 'no service']);
		$this->output->expects($this->once())->method('warning')->with('OpenRegister ObjectService unavailable — skipping POS bookkeeping migration.');
		$this->service->expects($this->never())->method('dispatchRaise');

		$this->step()->run($this->output);
	}//end testSkipsWhenTheObjectServiceCannotBeResolved()

	/**
	 * With no outbound journals the step reports that and reads no Z-reports.
	 *
	 * @return void
	 */
	public function testNoOutboundRecordsIsReported(): void {
		$this->objects(['posJournalEntryOutbound' => []]);
		$this->output->expects($this->once())->method('info')->with('POS bookkeeping migration: no outbound journal records to migrate.');
		$this->service->expects($this->never())->method('dispatchRaise');

		$this->step()->run($this->output);
	}//end testNoOutboundRecordsIsReported()

	/**
	 * A posted outbound is projected onto its Z-report (found by id) as raised,
	 * under the system identity, without @self.
	 *
	 * @return void
	 */
	public function testAPostedOutboundIsProjectedOntoItsZReport(): void {
		$objects = $this->objects([
			'posJournalEntryOutbound' => [['zReport' => 'z-1', 'status' => 'posted', 'shillinqJournalEntryId' => 'je-9', 'idempotencyKey' => 'k-1']],
			'posZReport' => [['id' => 'z-1', 'total' => 100, '@self' => ['slug' => 'z-report-1']]],
		]);
		$this->service->expects($this->never())->method('dispatchRaise');
		$this->output->expects($this->once())->method('info')
			->with($this->stringStartsWith('POS bookkeeping migration: 0 re-raised, 1 posted-projected, 0 left pending for retry.'));
		$this->logger->expects($this->once())->method('info')
			->with('POS bookkeeping migration completed', ['raised' => 0, 'projected' => 1, 'pending' => 0]);

		$this->step()->run($this->output);

		$this->assertSame(1, $objects->systemRuns);
		$this->assertSame(
			[[
				'object' => ['id' => 'z-1', 'total' => 100, 'bookkeepingStatus' => 'raised', 'shillinqJournalEntryId' => 'je-9'],
				'register' => 'crm',
				'schema' => 'posZReport',
				'uuid' => 'z-1',
				'system' => true,
			]],
			$objects->saved
		);
	}//end testAPostedOutboundIsProjectedOntoItsZReport()

	/**
	 * A posted outbound without a journal entry id projects its idempotency key,
	 * and a Z-report can be found by its slug.
	 *
	 * @return void
	 */
	public function testAPostedOutboundFallsBackToTheIdempotencyKeyAndSlug(): void {
		$objects = $this->objects([
			'posJournalEntryOutbound' => [['zReport' => 'z-report-1', 'status' => 'posted', 'idempotencyKey' => 'k-1']],
			'posZReport' => [['uuid' => 'z-uuid', '@self' => ['slug' => 'z-report-1']]],
		]);

		$this->step()->run($this->output);

		$this->assertSame('k-1', $objects->saved[0]['object']['shillinqJournalEntryId']);
		$this->assertSame('z-uuid', $objects->saved[0]['uuid']);
	}//end testAPostedOutboundFallsBackToTheIdempotencyKeyAndSlug()

	/**
	 * In-flight outbounds are re-raised with their original idempotency key and
	 * counted raised or pending by the outcome.
	 *
	 * @return void
	 */
	public function testInFlightOutboundsAreReRaisedWithTheirKey(): void {
		$objects = $this->objects([
			'posJournalEntryOutbound' => [
				['zReport' => 'z-1', 'status' => 'failed', 'idempotencyKey' => 'k-1'],
				['zReport' => 'z-2', 'status' => 'pending', 'idempotencyKey' => 'k-2'],
			],
			'posZReport' => [['id' => 'z-1'], ['id' => 'z-2']],
		]);
		$raises = [];
		$this->service->expects($this->exactly(2))->method('dispatchRaise')
			->willReturnCallback(function (array $zReport, ?string $key) use (&$raises): array {
				$raises[] = [$zReport['id'], $key];
				return ['bookkeepingStatus' => $zReport['id'] === 'z-1' ? 'raised' : 'failed'];
			});
		$this->logger->expects($this->once())->method('info')
			->with('POS bookkeeping migration completed', ['raised' => 1, 'projected' => 0, 'pending' => 1]);

		$this->step()->run($this->output);

		$this->assertSame([['z-1', 'k-1'], ['z-2', 'k-2']], $raises);
		$this->assertSame([], $objects->saved);
	}//end testInFlightOutboundsAreReRaisedWithTheirKey()

	/**
	 * A Z-report already raised is never re-raised, and an outbound without a
	 * known Z-report is logged and skipped.
	 *
	 * @return void
	 */
	public function testAlreadyRaisedAndOrphanedOutboundsAreSkipped(): void {
		$objects = $this->objects([
			'posJournalEntryOutbound' => [
				['zReport' => 'z-1', 'status' => 'pending', 'idempotencyKey' => 'k-1'],
				['zReport' => 'z-missing', 'status' => 'pending'],
			],
			'posZReport' => [['id' => 'z-1', 'bookkeepingStatus' => 'raised']],
		]);
		$this->service->expects($this->never())->method('dispatchRaise');
		$this->logger->expects($this->once())->method('warning')
			->with('POS bookkeeping migration: parent Z-report not found for outbound', ['zReportRef' => 'z-missing']);
		$this->logger->expects($this->once())->method('info')
			->with('POS bookkeeping migration completed', ['raised' => 0, 'projected' => 0, 'pending' => 0]);

		$this->step()->run($this->output);

		$this->assertSame([], $objects->saved);
	}//end testAlreadyRaisedAndOrphanedOutboundsAreSkipped()

	/**
	 * A failing projection save is logged; the record still counts as projected.
	 *
	 * @return void
	 */
	public function testAFailingProjectionIsLogged(): void {
		$objects           = $this->objects([
			'posJournalEntryOutbound' => [['zReport' => 'z-1', 'status' => 'posted', 'idempotencyKey' => 'k']],
			'posZReport' => [['id' => 'z-1']],
		]);
		$objects->saveFail = new \RuntimeException('read only');
		$this->logger->expects($this->once())->method('warning')
			->with('POS bookkeeping migration: failed to project posted outcome onto Z-report', ['exception' => 'read only']);
		$this->logger->expects($this->once())->method('info')
			->with('POS bookkeeping migration completed', ['raised' => 0, 'projected' => 1, 'pending' => 0]);

		$this->step()->run($this->output);
	}//end testAFailingProjectionIsLogged()

	/**
	 * A failing read of the outbound schema is logged and treated as no records.
	 *
	 * @return void
	 */
	public function testAFailingReadIsLoggedAsNoRecords(): void {
		$this->objects(['posJournalEntryOutbound' => new \RuntimeException('schema missing')]);
		$this->logger->expects($this->once())->method('warning')
			->with('POS bookkeeping migration: failed to read objects', ['schema' => 'posJournalEntryOutbound', 'exception' => 'schema missing']);
		$this->output->expects($this->once())->method('info')->with('POS bookkeeping migration: no outbound journal records to migrate.');

		$this->step()->run($this->output);
	}//end testAFailingReadIsLoggedAsNoRecords()

	/**
	 * The step names itself.
	 *
	 * @return void
	 */
	public function testName(): void {
		$this->assertSame(
			'Migrate POS bookkeeping (GL mapping + outbound journal) to the shillinq integration registry',
			$this->step()->getName()
		);
	}//end testName()
}//end class
