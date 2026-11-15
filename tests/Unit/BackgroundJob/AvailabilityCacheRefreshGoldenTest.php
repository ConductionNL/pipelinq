<?php

/**
 * Golden test of AvailabilityCacheRefreshJob: what it reads, invalidates and logs.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/appointment-booking-10-calendar-sync/specs/appointment-booking/spec.md#req-apt-018
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\BackgroundJob\AvailabilityCacheRefreshJob;
use OCA\Pipelinq\Service\AvailabilityService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the resource read, the invalidations and every log line of a refresh run.
 */
final class AvailabilityCacheRefreshGoldenTest extends TestCase {
	/**
	 * Every findAll() config, invalidation and log line, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $events = [];

	/**
	 * Run the job.
	 *
	 * @param array<string, string> $config      App config values, or ['throw' => ''] to make config fail.
	 * @param mixed                 $rows        What findAll() returns, or a Throwable to throw.
	 * @param array<int, string>    $failingIds  Resources whose first invalidation fails.
	 *
	 * @return array<int, array<int, mixed>> The events.
	 */
	private function refresh(array $config, mixed $rows, array $failingIds = []): array {
		$this->events = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($config): string {
				if (array_key_exists('throw', $config) === true) {
					throw new RuntimeException('config offline');
				}

				return ($config[$key] ?? $default);
			}
		);
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config) use ($rows): mixed {
				$this->events[] = ['findAll', $config];
				if ($rows instanceof \Throwable) {
					throw $rows;
				}

				return $rows;
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);
		$failed = [];
		$availability = $this->createMock(AvailabilityService::class);
		$availability->method('invalidateCache')->willReturnCallback(
			function (string $resourceId, string $date) use ($failingIds, &$failed): void {
				$this->events[] = ['invalidate', $resourceId, $date];
				if (in_array($resourceId, $failingIds, true) === true && isset($failed[$resourceId]) === false) {
					$failed[$resourceId] = true;
					throw new RuntimeException('cache down');
				}
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->events[] = [$level, $message, $context];
				}
			);
		}

		$job = new AvailabilityCacheRefreshJob(
			time: $this->createMock(ITimeFactory::class),
			container: $container,
			appConfig: $appConfig,
			availabilityService: $availability,
			logger: $logger,
		);
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);
		return $this->events;
	}//end refresh()

	/**
	 * The 31 dates from today (UTC) on.
	 *
	 * @return array<int, string> The dates.
	 */
	private function horizon(): array {
		$today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
		return array_map(static fn (int $offset): string => $today->modify('+'.$offset.' days')->format('Y-m-d'), range(0, 30));
	}//end horizon()

	/**
	 * Every active resource with an id is invalidated over the horizon; a failure is logged and counted.
	 *
	 * @return void
	 */
	public function testInvalidatesEveryResourceAndLogsTheTally(): void {
		$config = ['register' => 'pipelinq', 'resource_schema' => 'appointmentResource'];
		$rows = [['id' => 'r-1'], 'not a row', ['name' => 'no id'], ['id' => 'r-2']];
		$dates = $this->horizon();

		$expected = [['findAll', ['filters' => ['register' => 'pipelinq', 'schema' => 'appointmentResource', 'status' => 'active']]]];
		foreach (['r-1', 'r-2'] as $resource) {
			foreach ($dates as $index => $date) {
				$expected[] = ['invalidate', $resource, $date];
				if ($resource === 'r-2' && $index === 0) {
					$expected[] = ['warning', 'Pipelinq: availability cache invalidation failed', ['resource' => 'r-2', 'date' => $date]];
				}
			}
		}

		$expected[] = [
			'info',
			'Pipelinq: availability cache refresh complete',
			['resources' => 2, 'invalidated' => 61, 'errors' => 1, 'horizon_days' => 30],
		];
		$this->assertSame($expected, $this->refresh(config: $config, rows: $rows, failingIds: ['r-2']));
	}//end testInvalidatesEveryResourceAndLogsTheTally()

	/**
	 * Unconfigured, unreadable and id-less reads invalidate nothing; a failing config is an error.
	 *
	 * @return void
	 */
	public function testNothingIsInvalidatedWithoutResources(): void {
		$config = ['register' => 'pipelinq', 'resource_schema' => 'appointmentResource'];
		$query = ['findAll', ['filters' => ['register' => 'pipelinq', 'schema' => 'appointmentResource', 'status' => 'active']]];

		$this->assertSame([], $this->refresh(config: ['register' => 'pipelinq'], rows: [['id' => 'r-1']]));
		$this->assertSame([], $this->refresh(config: ['resource_schema' => 'appointmentResource'], rows: [['id' => 'r-1']]));
		$this->assertSame(
			[$query, ['warning', 'Pipelinq: availability cache refresh resource lookup failed', ['exception' => 'register offline']]],
			$this->refresh(config: $config, rows: new RuntimeException('register offline'))
		);
		$this->assertSame([$query], $this->refresh(config: $config, rows: [['name' => 'no id']]));
		$this->assertSame(
			[['error', 'Pipelinq: availability cache refresh failed to load resources', ['exception' => 'config offline']]],
			$this->refresh(config: ['throw' => ''], rows: [])
		);
	}//end testNothingIsInvalidatedWithoutResources()
}//end class
