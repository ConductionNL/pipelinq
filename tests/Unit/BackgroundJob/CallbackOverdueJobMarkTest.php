<?php

/**
 * Unit tests for CallbackOverdueJob::markNotified: the reminder stamp is written and stamps older than two cooldowns are pruned.
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

use OCA\Pipelinq\BackgroundJob\LabelRenameJob;
use OCA\Pipelinq\BackgroundJob\CallbackOverdueJob;
use OCA\Pipelinq\Service\NotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for CallbackOverdueJob::markNotified and its pruning.
 *
 * @covers \OCA\Pipelinq\BackgroundJob\CallbackOverdueJob
 */
class CallbackOverdueJobMarkTest extends TestCase {

	/**
	 * App config values.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * The keys deleted.
	 *
	 * @var array<int, string>
	 */
	private array $deleted = [];

	/**
	 * Build the job over an in-memory app config.
	 *
	 * @param \Throwable|null      $keysFail What getKeys throws, if anything.
	 * @param LoggerInterface|null $logger   The logger.
	 *
	 * @return CallbackOverdueJob
	 */
	private function job(?\Throwable $keysFail = null, ?LoggerInterface $logger = null): CallbackOverdueJob {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default));
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);
		if ($keysFail !== null) {
			$appConfig->method('getKeys')->willThrowException($keysFail);
		} else {
			$appConfig->method('getKeys')->willReturnCallback(fn (): array => array_keys($this->config));
		}

		$appConfig->method('deleteKey')->willReturnCallback(
			function (string $app, string $key): void {
				$this->deleted[] = $key;
				unset($this->config[$key]);
			}
		);

		return new CallbackOverdueJob($this->createMock(ITimeFactory::class), $appConfig, $this->createMock(NotificationService::class), ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end job()

	/**
	 * Marking stamps the task now and prunes only stamps older than two cooldowns.
	 *
	 * @return void
	 */
	public function testMarkingStampsAndPrunesOldStamps(): void {
		$cooldown = CallbackOverdueJob::NOTIFICATION_COOLDOWN;
		$this->config = [
			'callback_notified_old' => (string)(time() - (3 * $cooldown)),
			'callback_notified_recent' => (string)(time() - $cooldown),
			'callback_notified_zero' => '0',
			'register' => '5',
		];

		$job = $this->job();
		$job->markNotified('task-7');

		$this->assertEqualsWithDelta(time(), (int)$this->config['callback_notified_task-7'], 5);
		$this->assertSame(['callback_notified_old'], $this->deleted);
		$this->assertTrue($job->wasRecentlyNotified('task-7'));
		$this->assertArrayHasKey('register', $this->config);
	}//end testMarkingStampsAndPrunesOldStamps()

	/**
	 * A failing key listing is logged and the stamp is still written.
	 *
	 * @return void
	 */
	public function testAFailedPruneIsLogged(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('CallbackOverdueJob: failed to prune stale notifications', $this->arrayHasKey('exception'));

		$this->job(new RuntimeException('config down'), $logger)->markNotified('task-8');
		$this->assertArrayHasKey('callback_notified_task-8', $this->config);
	}//end testAFailedPruneIsLogged()
}//end class
