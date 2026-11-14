<?php

/**
 * Unit tests for the run() of GenerateZReportJob and PosRetryBackoffJob:
 * when a Z-report is generated and for which terminals, and how pending
 * Z-reports are re-raised to the journal.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\BackgroundJob\GenerateZReportJob;
use OCA\Pipelinq\BackgroundJob\PosRetryBackoffJob;
use OCA\Pipelinq\Service\PosBookkeepingService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * The scheduled runs of the two POS bookkeeping jobs.
 *
 * @covers \OCA\Pipelinq\BackgroundJob\GenerateZReportJob
 * @covers \OCA\Pipelinq\BackgroundJob\PosRetryBackoffJob
 * @uses   \OCA\Pipelinq\BackgroundJob\ConfiguredSchemaRows
 * @uses   \OCA\Pipelinq\Util\CastingPayloadToArray
 * @uses   \OCA\Pipelinq\Util\EntityPayload
 * @uses   \OCA\Pipelinq\Util\SerialisedPayload
 * @uses   \OCA\Pipelinq\Util\SerialisedToArray
 */
final class PosJobRunTest extends TestCase {

	/**
	 * The mocked bookkeeping service.
	 *
	 * @var PosBookkeepingService&MockObject
	 */
	private PosBookkeepingService $service;

	/**
	 * Every info line logged, as [message, context].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $infos = [];

	/**
	 * Every warning logged, as [message, context].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $warnings = [];

	/**
	 * Set up the service double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = $this->createMock(PosBookkeepingService::class);
	}//end setUp()

	/**
	 * Build a job over a fake object service answering the given rows.
	 *
	 * @param class-string          $class  The job class.
	 * @param array<string, string> $config The app config values.
	 * @param array<int, mixed>     $rows   The rows findAll() returns.
	 *
	 * @return object The job.
	 */
	private function job(string $class, array $config, array $rows = []): object {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($config): string {
				return ($config[$key] ?? $default);
			}
		);
		$objects = new class ($rows) {
			/**
			 * Constructor.
			 *
			 * @param array<int, mixed> $rows The rows.
			 */
			public function __construct(private array $rows) {
			}//end __construct()

			/**
			 * The configured rows.
			 *
			 * @param array<string, mixed> $config The query.
			 *
			 * @return array<int, mixed> The rows.
			 */
			public function findAll(array $config): array {
				return $this->rows;
			}//end findAll()
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->infos[] = [$message, $context];
			}
		);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = [$message, $context];
			}
		);

		return new $class(
			$this->createMock(ITimeFactory::class),
			$appConfig,
			$this->service,
			$container,
			$logger
		);
	}//end job()

	/**
	 * Run a job once.
	 *
	 * @param object $job The job.
	 *
	 * @return void
	 */
	private function runJob(object $job): void {
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end runJob()

	/**
	 * The configured Z-report time that falls in the current UTC hour.
	 *
	 * @return string
	 */
	private function thisHour(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('H') . ':15';
	}//end thisHour()

	/**
	 * Yesterday as a UTC date.
	 *
	 * @return string
	 */
	private function yesterday(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-1 day')->format('Y-m-d');
	}//end yesterday()

	/**
	 * A malformed or other-hour Z-report time generates nothing.
	 *
	 * @return void
	 */
	public function testNoZReportOutsideTheConfiguredHour(): void {
		$this->service->expects($this->never())->method('generateZReport');

		$otherHour = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+2 hours')->format('H') . ':00';
		foreach (['24:00', '9:00', 'tonight', '', $otherHour] as $configured) {
			$this->runJob($this->job(GenerateZReportJob::class, ['pos_eod.z_report_time' => $configured]));
		}

		$this->assertSame([], $this->infos);
	}//end testNoZReportOutsideTheConfiguredHour()

	/**
	 * In the configured hour a day without sales still gets one zero-value
	 * Z-report for yesterday, without a terminal.
	 *
	 * @return void
	 */
	public function testADayWithoutSalesGetsOneZReportWithoutTerminal(): void {
		$this->service->expects($this->once())
			->method('generateZReport')
			->with($this->yesterday(), null)
			->willReturn(['transactionCount' => 0, 'total' => 0]);

		$this->runJob($this->job(GenerateZReportJob::class, ['pos_eod.z_report_time' => ' ' . $this->thisHour() . ' ']));

		$this->assertSame(
			[['GenerateZReportJob: Z-report created', ['reportDate' => $this->yesterday(), 'terminalId' => null, 'transactionCount' => 0, 'total' => 0.0]]],
			$this->infos
		);
	}//end testADayWithoutSalesGetsOneZReportWithoutTerminal()

	/**
	 * Each terminal that sold yesterday gets its own Z-report, and one
	 * failing terminal is logged without stopping the others.
	 *
	 * @return void
	 */
	public function testEachTerminalThatSoldGetsItsOwnZReport(): void {
		$day = $this->yesterday();
		$rows = [
			['terminalId' => 't-1', 'settledAt' => $day . 'T10:00:00Z'],
			['terminalId' => 't-2', 'settledAt' => $day . 'T11:00:00Z'],
			['terminalId' => 't-1', 'confirmedAt' => $day . 'T12:00:00Z'],
		];
		$calls = [];
		$this->service->method('generateZReport')->willReturnCallback(
			static function (string $reportDate, ?string $terminalId = null) use (&$calls): array {
				$calls[] = [$reportDate, $terminalId];
				if ($terminalId === 't-1') {
					throw new RuntimeException('ledger locked');
				}

				return ['transactionCount' => '4', 'total' => '12.50'];
			}
		);

		$this->runJob(
			$this->job(
				GenerateZReportJob::class,
				['pos_eod.z_report_time' => $this->thisHour(), 'register' => 'r-1', 'posTransaction_schema' => 's-tx'],
				$rows
			)
		);

		$this->assertSame([[$day, 't-1'], [$day, 't-2']], $calls);
		$this->assertSame(
			[['GenerateZReportJob: failed to generate Z-report', ['reportDate' => $day, 'terminalId' => 't-1', 'exception' => 'ledger locked']]],
			$this->warnings
		);
		$this->assertSame(
			[['GenerateZReportJob: Z-report created', ['reportDate' => $day, 'terminalId' => 't-2', 'transactionCount' => 4, 'total' => 12.5]]],
			$this->infos
		);
	}//end testEachTerminalThatSoldGetsItsOwnZReport()

	/**
	 * Without the journal integration nothing is read or retried.
	 *
	 * @return void
	 */
	public function testNoRetryWithoutTheJournalIntegration(): void {
		$this->service->method('shouldDispatch')->willReturn(false);
		$this->service->expects($this->never())->method('dispatchRaise');

		$this->runJob(
			$this->job(
				PosRetryBackoffJob::class,
				['register' => 'r-1', 'posZReport_schema' => 's-z'],
				[['id' => 'z-1', 'bookkeepingStatus' => 'pending', 'transactionCount' => 3]]
			)
		);

		$this->assertSame([], $this->infos);
		$this->assertSame([], $this->warnings);
	}//end testNoRetryWithoutTheJournalIntegration()

	/**
	 * Each pending Z-report with takings is re-raised; a failing raise is
	 * logged and the next one is still tried.
	 *
	 * @return void
	 */
	public function testPendingZReportsAreReRaisedOneByOne(): void {
		$this->service->method('shouldDispatch')->willReturn(true);
		$raised = [];
		$this->service->method('dispatchRaise')->willReturnCallback(
			static function (array $zReport, ?string $idempotencyKey = null) use (&$raised): array {
				$raised[] = $zReport['id'];
				if ($zReport['id'] === 'z-1') {
					throw new RuntimeException('shillinq unavailable');
				}

				return ['bookkeepingStatus' => 'posted'];
			}
		);

		$this->runJob(
			$this->job(
				PosRetryBackoffJob::class,
				['register' => 'r-1', 'posZReport_schema' => 's-z'],
				[
					['id' => 'z-1', 'bookkeepingStatus' => 'pending', 'transactionCount' => 3],
					['id' => 'z-2', 'bookkeepingStatus' => 'posted', 'transactionCount' => 3],
					['id' => 'z-3', 'transactionCount' => 1],
				]
			)
		);

		$this->assertSame(['z-1', 'z-3'], $raised);
		$this->assertSame(
			[['PosRetryBackoffJob: journal raise retry failed', ['zReportId' => 'z-1', 'exception' => 'shillinq unavailable']]],
			$this->warnings
		);
		$this->assertSame(
			[['PosRetryBackoffJob: journal raise retried', ['zReportId' => 'z-3', 'bookkeepingStatus' => 'posted']]],
			$this->infos
		);
	}//end testPendingZReportsAreReRaisedOneByOne()
}//end class
