<?php

/**
 * Unit tests for ForecastSnapshotJob: the weekly forecast snapshot runs once on the configured day, from the configured hour, in the organisation's time zone.
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
use OCA\Pipelinq\BackgroundJob\ForecastSnapshotJob;
use OCA\Pipelinq\Service\SnapshotGenerationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Tests for ForecastSnapshotJob.
 *
 * @covers \OCA\Pipelinq\BackgroundJob\ForecastSnapshotJob
 */
class ForecastSnapshotJobTest extends TestCase {

	/**
	 * App config values.
	 *
	 * @var array<string, string|int>
	 */
	private array $config = [];

	/**
	 * Run the job at a moment.
	 *
	 * @param string                    $at        The moment, ISO 8601 with offset.
	 * @param SnapshotGenerationService $generator The generator.
	 * @param LoggerInterface|null      $logger    The logger.
	 *
	 * @return void
	 */
	private function runAt(string $at, SnapshotGenerationService $generator, ?LoggerInterface $logger = null): void {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn((new DateTimeImmutable($at))->getTimestamp());
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => (string)($this->config[$key] ?? $default));
		$appConfig->method('getValueInt')->willReturnCallback(fn (string $app, string $key, int $default = 0): int => (int)($this->config[$key] ?? $default));
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);

		$job = new ForecastSnapshotJob($time, $generator, $appConfig, ($logger ?? $this->createMock(LoggerInterface::class)));
		(new ReflectionMethod(ForecastSnapshotJob::class, 'run'))->invoke($job, null);
	}//end runAt()

	/**
	 * Set up a configured register and schema.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->config = ['register' => 'reg', 'forecastSnapshot_schema' => 'snap'];
	}//end setUp()

	/**
	 * On Monday from 06:00 the snapshot is generated once and the day recorded.
	 *
	 * @return void
	 */
	public function testItRunsOnceOnMondayFromSix(): void {
		$generator = $this->createMock(SnapshotGenerationService::class);
		$generator->expects($this->once())->method('generate')
			->with($this->callback(static fn (DateTimeImmutable $d): bool => $d->format('Y-m-d H:i') === '2026-11-16 07:15'))
			->willReturn(['written' => 3]);

		$this->runAt('2026-11-16T05:59:00+00:00', $generator);
		$this->runAt('2026-11-16T07:15:00+00:00', $generator);
		$this->runAt('2026-11-16T09:00:00+00:00', $generator);

		$this->assertSame('2026-11-16', $this->config[ForecastSnapshotJob::LAST_RUN_KEY]);
	}//end testItRunsOnceOnMondayFromSix()

	/**
	 * Another day, or a missing register or schema, generates nothing.
	 *
	 * @return void
	 */
	public function testOtherDaysAndAnUnconfiguredAppGenerateNothing(): void {
		$generator = $this->createMock(SnapshotGenerationService::class);
		$generator->expects($this->never())->method('generate');

		$this->runAt('2026-11-17T08:00:00+00:00', $generator);
		$this->config['forecastSnapshot_schema'] = '';
		$this->runAt('2026-11-16T08:00:00+00:00', $generator);
	}//end testOtherDaysAndAnUnconfiguredAppGenerateNothing()

	/**
	 * The configured day, hour and time zone decide; an unknown zone falls back to UTC.
	 *
	 * @return void
	 */
	public function testTheConfiguredScheduleAndZoneDecide(): void {
		$this->config += [ForecastSnapshotJob::DAY_KEY => 5, ForecastSnapshotJob::HOUR_KEY => 9, ForecastSnapshotJob::TIMEZONE_KEY => 'Europe/Amsterdam'];
		$generator = $this->createMock(SnapshotGenerationService::class);
		$generator->expects($this->once())->method('generate')->willReturn([]);

		// 08:30 UTC is 09:30 in Amsterdam on a Friday.
		$this->runAt('2026-11-13T07:30:00+00:00', $generator);
		$this->runAt('2026-11-13T08:30:00+00:00', $generator);

		$this->config[ForecastSnapshotJob::TIMEZONE_KEY] = 'Not/AZone';
		$this->config[ForecastSnapshotJob::LAST_RUN_KEY] = '';
		$other = $this->createMock(SnapshotGenerationService::class);
		$other->expects($this->once())->method('generate')->with($this->callback(static fn (DateTimeImmutable $d): bool => $d->getTimezone()->getName() === 'UTC'))->willReturn([]);
		$this->runAt('2026-11-20T09:30:00+00:00', $other);
	}//end testTheConfiguredScheduleAndZoneDecide()

	/**
	 * A failed generation is logged and the day is not recorded, so it is tried again.
	 *
	 * @return void
	 */
	public function testAFailedGenerationIsRetried(): void {
		$generator = $this->createMock(SnapshotGenerationService::class);
		$generator->expects($this->exactly(2))->method('generate')->willThrowException(new RuntimeException('register down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('error')->with('ForecastSnapshotJob: generation failed', ['exception' => 'register down']);

		$this->runAt('2026-11-16T07:00:00+00:00', $generator, $logger);
		$this->runAt('2026-11-16T08:00:00+00:00', $generator, $logger);
		$this->assertArrayNotHasKey(ForecastSnapshotJob::LAST_RUN_KEY, $this->config);
	}//end testAFailedGenerationIsRetried()
}//end class
