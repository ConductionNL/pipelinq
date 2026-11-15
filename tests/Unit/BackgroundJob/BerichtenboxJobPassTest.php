<?php

/**
 * Unit tests for BerichtenboxJobPass.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use OCA\Pipelinq\BackgroundJob\BerichtenboxJobPass;
use OCA\Pipelinq\Kernel\Contracts\BerichtenboxServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * BerichtenboxJobPass runs one Berichtenbox job's work and contains its failures.
 */
class BerichtenboxJobPassTest extends TestCase {

	/**
	 * The work runs with the Berichtenbox service.
	 *
	 * @return void
	 */
	public function testTheWorkRunsWithTheService(): void {
		$service = $this->createMock(BerichtenboxServiceInterface::class);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(BerichtenboxServiceInterface::class)->willReturn($service);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method(self::anything());

		$given = null;
		(new BerichtenboxJobPass(container: $container, logger: $logger))->run(job: 'RetryJob', work: static function ($received) use (&$given): void {
			$given = $received;
		});

		self::assertSame($service, $given);
	}//end testTheWorkRunsWithTheService()

	/**
	 * Without the service the work is skipped with a warning.
	 *
	 * @return void
	 */
	public function testWithoutTheServiceTheWorkIsSkipped(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('not registered'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with('StatsJob: BerichtenboxService unavailable.', ['exception' => 'not registered']);

		$ran = false;
		(new BerichtenboxJobPass(container: $container, logger: $logger))->run(job: 'StatsJob', work: static function () use (&$ran): void {
			$ran = true;
		});

		self::assertFalse($ran);
	}//end testWithoutTheServiceTheWorkIsSkipped()

	/**
	 * A failing run is logged as an error, not thrown.
	 *
	 * @return void
	 */
	public function testAFailingRunIsLogged(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->createMock(BerichtenboxServiceInterface::class));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error')->with('RetryJob run failed.', ['exception' => 'gateway timeout']);

		(new BerichtenboxJobPass(container: $container, logger: $logger))->run(job: 'RetryJob', work: static function (): void {
			throw new RuntimeException('gateway timeout');
		});
	}//end testAFailingRunIsLogged()
}//end class
