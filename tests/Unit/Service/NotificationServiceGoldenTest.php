<?php

/**
 * Golden test for NotificationService: every notify method, opt-out and failure.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
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

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\NotificationService;
use OCP\IConfig;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Calls every public notify method for another user, for the author themself, with
 * the user's category switched on and off, and with a failing notification manager,
 * and compares the settings read, the notification built and the errors logged
 * with a recorded golden file.
 */
class NotificationServiceGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/NotificationServiceGolden.json';

	/**
	 * The calls made, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $trace = [];

	/**
	 * Run every case and compare with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$calls = [
			'assignment lead' => static fn (NotificationService $s, string $author) => $s->notifyAssignment('lead', 'Big deal', 'bob', 'obj-1', $author),
			'assignment request' => static fn (NotificationService $s, string $author) => $s->notifyAssignment('request', 'Help', 'bob', 'obj-2', $author),
			'assignment task' => static fn (NotificationService $s, string $author) => $s->notifyAssignment('task', 'Call back', 'bob', 'obj-3', $author),
			'assignment other' => static fn (NotificationService $s, string $author) => $s->notifyAssignment('complaint', 'Grr', 'bob', 'obj-4', $author),
			'task completed' => static fn (NotificationService $s, string $author) => $s->notifyTaskCompleted('Call back', 'Done', 'bob', 'obj-5', $author),
			'task reassigned' => static fn (NotificationService $s, string $author) => $s->notifyTaskReassigned('Call back', 'bob', 'obj-6', $author, '2026-04-01'),
			'task reassigned without deadline' => static fn (NotificationService $s, string $author) => $s->notifyTaskReassigned('Call back', 'bob', 'obj-6', $author),
			'task expired' => static fn (NotificationService $s, string $author) => $s->notifyTaskExpired('Call back', 'bob', 'obj-7', '2026-04-02'),
			'task expired without deadline' => static fn (NotificationService $s, string $author) => $s->notifyTaskExpired('Call back', 'bob', 'obj-7'),
			'stage change' => static fn (NotificationService $s, string $author) => $s->notifyStageChange('Big deal', 'Proposal', 'bob', 'obj-8', $author),
			'status change' => static fn (NotificationService $s, string $author) => $s->notifyStatusChange('Help', 'Closed', 'bob', 'obj-9', $author),
			'note added' => static fn (NotificationService $s, string $author) => $s->notifyNoteAdded('client', 'ACME', 'bob', 'obj-10', $author),
			'deal won' => static fn (NotificationService $s, string $author) => $s->notifyDealWon('Big deal', '1200', 'bob', 'obj-11', $author),
			'deal lost' => static fn (NotificationService $s, string $author) => $s->notifyDealLost('Big deal', 'bob', 'obj-12', $author),
			'custom subject' => static fn (NotificationService $s, string $author) => $s->sendNotification('bob', 'custom_thing', ['x' => $author]),
			'custom mapped subject' => static fn (NotificationService $s, string $author) => $s->sendNotification('bob', 'lead_won', ['x' => $author], 'lead', 'obj-13'),
		];

		$actual = [];
		foreach ($calls as $name => $call) {
			foreach (['by alice' => ['alice', 'true', false], 'by bob himself' => ['bob', 'true', false], 'opted out' => ['alice', 'false', false], 'manager fails' => ['alice', 'true', true]] as $variant => [$author, $setting, $fails]) {
				$this->trace = [];
				$call($this->service(setting: $setting, fails: $fails), $author);
				$actual[$name . ', ' . $variant] = $this->trace;
			}
		}

		$flags = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (getenv('NOTIFICATION_GOLDEN_RECORD') !== false) {
			file_put_contents(self::GOLDEN, json_encode($actual, $flags) . "\n");
			$this->markTestSkipped(message: 'recorded');
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		foreach ($expected as $name => $want) {
			$this->assertSame(expected: json_encode($want, $flags), actual: json_encode(($actual[$name] ?? null), $flags), message: $name);
		}

		$this->assertSame(expected: array_keys($expected), actual: array_keys($actual));
	}//end testGolden()

	/**
	 * A service on recording doubles.
	 *
	 * @param string $setting The user's value for every notify_* setting.
	 * @param bool   $fails   Whether the notification manager fails.
	 *
	 * @return NotificationService
	 */
	private function service(string $setting, bool $fails): NotificationService {
		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject'] as $method) {
			$notification->method($method)->willReturnCallback(
				function (mixed ...$args) use ($method, $notification): INotification {
					if ($method === 'setDateTime') {
						$args = [get_class($args[0])];
					}

					$this->trace[] = [$method, $args];
					return $notification;
				}
			);
		}

		$manager = $this->createMock(IManager::class);
		$manager->method('createNotification')->willReturnCallback(
			function () use ($notification, $fails): INotification {
				$this->trace[] = ['createNotification'];
				if ($fails === true) {
					throw new \RuntimeException('no notifications app');
				}

				return $notification;
			}
		);
		$manager->method('notify')->willReturnCallback(
			function (): void {
				$this->trace[] = ['notify'];
			}
		);

		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			function (string $userId, string $appName, string $key, mixed $default = '') use ($setting): string {
				$this->trace[] = ['getUserValue', $userId, $appName, $key, $default];
				return $setting;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->trace[] = ['error', $message, $context];
			}
		);

		return new NotificationService($manager, $config, $logger);
	}//end service()
}//end class
