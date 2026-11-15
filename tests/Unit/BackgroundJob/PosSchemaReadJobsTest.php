<?php

/**
 * Unit tests for the schema reads of GenerateZReportJob and PosRetryBackoffJob:
 * which terminals sold on a day, and which Z-reports still await bookkeeping.
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

use OCA\Pipelinq\BackgroundJob\GenerateZReportJob;
use OCA\Pipelinq\BackgroundJob\PosRetryBackoffJob;
use OCA\Pipelinq\Service\PosBookkeepingService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Pins the terminal discovery and the pending Z-report read before they share their schema read.
 */
final class PosSchemaReadJobsTest extends TestCase {
	/**
	 * Every findAll() config the fake object service received.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * Every warning logged, as [message, context].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $warnings = [];

	/**
	 * Build a job over a fake object service answering the given rows.
	 *
	 * @param class-string           $class  The job class.
	 * @param array<string, string>  $config The app config values.
	 * @param array<int, mixed>|null $rows   The rows findAll() returns, or null to make it throw.
	 *
	 * @return object The job.
	 */
	private function job(string $class, array $config, ?array $rows): object {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);
		$objects = new class ($rows, $this->queries) {
			/**
			 * @param array<int, mixed>|null           $rows    The rows.
			 * @param array<int, array<string, mixed>> $queries The query log.
			 */
			public function __construct(private ?array $rows, private array &$queries) {
			}

			/**
			 * @param array<string, mixed> $config The query.
			 *
			 * @return array<int, mixed> The rows.
			 */
			public function findAll(array $config): array {
				$this->queries[] = $config;
				if ($this->rows === null) {
					throw new RuntimeException('register offline');
				}

				return $this->rows;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($objects);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = [$message, $context];
			}
		);

		return new $class(
			$this->createMock(ITimeFactory::class),
			$appConfig,
			$this->createMock(PosBookkeepingService::class),
			$container,
			$logger
		);
	}//end job()

	/**
	 * Call a private method of a job.
	 *
	 * @param object            $job    The job.
	 * @param string            $method The method.
	 * @param array<int, mixed> $args   The arguments.
	 *
	 * @return mixed The result.
	 */
	private function call(object $job, string $method, array $args): mixed {
		return (new ReflectionMethod($job, $method))->invokeArgs($job, $args);
	}//end call()

	/**
	 * Terminals that settled or confirmed a sale on the UTC day are found once each.
	 *
	 * @return void
	 */
	public function testDiscoversEachTerminalThatSoldOnTheDay(): void {
		$rows = [
			['terminalId' => 't-1', 'settledAt' => '2026-11-09T10:00:00Z'],
			['terminalId' => 't-1', 'settledAt' => '2026-11-09T11:00:00Z'],
			['terminalId' => 't-2', 'confirmedAt' => '2026-11-09T23:30:00+00:00'],
			['terminalId' => 't-3', 'settledAt' => '2026-11-09T23:30:00-02:00'],
			['terminalId' => 't-4', 'settledAt' => '2026-11-10T00:30:00+02:00'],
			['terminalId' => 't-5', 'settledAt' => '2026-11-08T12:00:00Z'],
			['terminalId' => '', 'settledAt' => '2026-11-09T12:00:00Z'],
			['terminalId' => 't-6'],
			['terminalId' => 't-7', 'settledAt' => 'not a date'],
			['terminalId' => 't-8', 'settledAt' => '', 'confirmedAt' => '2026-11-09T09:00:00Z'],
		];
		$job = $this->job(GenerateZReportJob::class, ['register' => 'r-1', 'posTransaction_schema' => 's-tx'], $rows);

		$this->assertSame(['t-1', 't-2', 't-4'], $this->call($job, 'discoverTerminals', ['2026-11-09']));
		$this->assertSame([['filters' => ['register' => 'r-1', 'schema' => 's-tx']]], $this->queries);
		$this->assertSame([], $this->warnings);
	}//end testDiscoversEachTerminalThatSoldOnTheDay()

	/**
	 * Without a configured register or schema nothing is read.
	 *
	 * @return void
	 */
	public function testDiscoversNothingWithoutConfiguration(): void {
		$job = $this->job(GenerateZReportJob::class, ['register' => 'r-1'], [['terminalId' => 't-1', 'settledAt' => '2026-11-09T10:00:00Z']]);
		$this->assertSame([], $this->call($job, 'discoverTerminals', ['2026-11-09']));

		$job = $this->job(GenerateZReportJob::class, ['posTransaction_schema' => 's-tx'], []);
		$this->assertSame([], $this->call($job, 'discoverTerminals', ['2026-11-09']));
		$this->assertSame([], $this->queries);
		$this->assertSame([], $this->warnings);
	}//end testDiscoversNothingWithoutConfiguration()

	/**
	 * A failing read is logged and finds no terminals.
	 *
	 * @return void
	 */
	public function testDiscoveryFailureIsLoggedAndFindsNothing(): void {
		$job = $this->job(GenerateZReportJob::class, ['register' => 'r-1', 'posTransaction_schema' => 's-tx'], null);

		$this->assertSame([], $this->call($job, 'discoverTerminals', ['2026-11-09']));
		$this->assertSame(
			[['GenerateZReportJob: failed to enumerate terminals', ['exception' => 'register offline']]],
			$this->warnings
		);
	}//end testDiscoveryFailureIsLoggedAndFindsNothing()

	/**
	 * Pending Z-reports with takings are found; booked, failed-free and empty ones are not.
	 *
	 * @return void
	 */
	public function testFindsThePendingZReportsWithTakings(): void {
		$rows = [
			['id' => 'z-1', 'bookkeepingStatus' => 'pending', 'transactionCount' => 3],
			['id' => 'z-2', 'transactionCount' => '2'],
			['id' => 'z-3', 'bookkeepingStatus' => 'posted', 'transactionCount' => 5],
			['id' => 'z-4', 'bookkeepingStatus' => 'pending', 'transactionCount' => 0],
			['id' => 'z-5', 'bookkeepingStatus' => 'pending'],
			['id' => 'z-6', 'bookkeepingStatus' => 'failed', 'transactionCount' => 1],
		];
		$job = $this->job(PosRetryBackoffJob::class, ['register' => 'r-1', 'posZReport_schema' => 's-z'], $rows);

		$this->assertSame(
			[
				['id' => 'z-1', 'bookkeepingStatus' => 'pending', 'transactionCount' => 3],
				['id' => 'z-2', 'transactionCount' => '2'],
			],
			$this->call($job, 'findPendingZReports', [])
		);
		$this->assertSame([['filters' => ['register' => 'r-1', 'schema' => 's-z']]], $this->queries);
	}//end testFindsThePendingZReportsWithTakings()

	/**
	 * Without configuration nothing is read; a failing read is logged and finds nothing.
	 *
	 * @return void
	 */
	public function testPendingReadWithoutConfigurationOrOnFailure(): void {
		$job = $this->job(PosRetryBackoffJob::class, ['register' => 'r-1'], [['bookkeepingStatus' => 'pending', 'transactionCount' => 1]]);
		$this->assertSame([], $this->call($job, 'findPendingZReports', []));
		$this->assertSame([], $this->queries);

		$job = $this->job(PosRetryBackoffJob::class, ['register' => 'r-1', 'posZReport_schema' => 's-z'], null);
		$this->assertSame([], $this->call($job, 'findPendingZReports', []));
		$this->assertSame(
			[['PosRetryBackoffJob: failed to read pending Z-reports', ['exception' => 'register offline']]],
			$this->warnings
		);
	}//end testPendingReadWithoutConfigurationOrOnFailure()
}//end class
