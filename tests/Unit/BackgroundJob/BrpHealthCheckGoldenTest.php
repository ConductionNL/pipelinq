<?php

/**
 * Pins the BRP certificate health check across expiry, admin and notification shapes.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\BackgroundJob\BrpHealthCheckJob;
use OCA\Pipelinq\Service\HaalCentraalClient;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Status written, notifications sent and log lines, in order.
 */
class BrpHealthCheckGoldenTest extends TestCase {

	/**
	 * Days until expiry (null: not configured, 'throw': the client fails), admins, failing admin, and the expected calls.
	 *
	 * @return array<string, array{mixed, bool, array<int, array<int, mixed>>}>
	 */
	public static function cases(): array {
		$saved = static fn (int $days, string $status): array => ['saved', ['expiry' => 'E', 'daysLeft' => $days, 'status' => $status, 'checkedAt' => 'NOW']];
		$sent  = static fn (string $uid, int $days, string $status): array => ['notify', $uid, $status, 'brp_cert_'.$status, ['daysLeft' => $days, 'expiry' => 'D']];
		return [
			'unconfigured'    => [null, true, [['info', 'BRP cert health check: cert not configured', []], ['saved', ['expiry' => null, 'status' => 'unconfigured', 'checkedAt' => 'NOW']]]],
			'client fails'    => ['throw', true, [['error', 'BRP health check failed', ['error' => 'pkcs gone']]]],
			'healthy'         => [90, true, [$saved(90, 'ok')]],
			'warn edge'       => [30, true, [$saved(30, 'warning'), $sent('anna', 30, 'warning'), ['warning', 'BRP cert notify failed', ['admin' => 'bert', 'error' => 'push down']], $sent('cees', 30, 'warning')]],
			'just healthy'    => [31, true, [$saved(31, 'ok')]],
			'critical edge'   => [7, true, [$saved(7, 'critical'), $sent('anna', 7, 'critical'), ['warning', 'BRP cert notify failed', ['admin' => 'bert', 'error' => 'push down']], $sent('cees', 7, 'critical')]],
			'expired'         => [-3, true, [$saved(-3, 'critical'), $sent('anna', -3, 'critical'), ['warning', 'BRP cert notify failed', ['admin' => 'bert', 'error' => 'push down']], $sent('cees', -3, 'critical')]],
			'no admin group'  => [10, false, [$saved(10, 'warning')]],
		];
	}//end cases()

	/**
	 * Each shape writes and sends exactly as pinned.
	 *
	 * @param mixed                         $days     Days until expiry, null or 'throw'.
	 * @param bool                          $admins   Whether the admin group exists.
	 * @param array<int, array<int, mixed>> $expected The calls.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testRun(mixed $days, bool $admins, array $expected): void {
		$calls  = [];
		$now    = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$expiry = null;
		if (is_int($days) === true) {
			$expiry = $now->modify(($days >= 0 ? '+' : '').$days.' days +1 hour');
		}

		$client = $this->createMock(HaalCentraalClient::class);
		if ($days === 'throw') {
			$client->method('getCertificateExpiry')->willThrowException(new RuntimeException('pkcs gone'));
		} else {
			$client->method('getCertificateExpiry')->willReturn($expiry);
		}

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->willReturnArgument(2);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) use (&$calls, $expiry): bool {
				$row = json_decode($value, true);
				$this->assertSame('brp.cert_health', $key);
				$this->assertNotFalse(DateTimeImmutable::createFromFormat(DATE_ATOM, $row['checkedAt']));
				$row['checkedAt'] = 'NOW';
				if ($row['expiry'] !== null) {
					$this->assertSame($expiry->format(DATE_ATOM), $row['expiry']);
					$row['expiry'] = 'E';
				}

				$calls[] = ['saved', $row];
				return true;
			}
		);
		$groups = $this->createMock(IGroupManager::class);
		$group  = null;
		if ($admins === true) {
			$group = $this->createMock(IGroup::class);
			$group->method('getUsers')->willReturn(
				array_map(
					function (string $uid): IUser {
						$user = $this->createMock(IUser::class);
						$user->method('getUID')->willReturn($uid);
						return $user;
					},
					['anna', 'bert', 'cees']
				)
			);
		}

		$groups->method('get')->with('admin')->willReturn($group);
		$notes = [];
		$notifications = $this->createMock(INotificationManager::class);
		$notifications->method('createNotification')->willReturnCallback(
			function () use (&$notes, $expiry): INotification {
				$seen = ['notify'];
				$note = $this->createMock(INotification::class);
				$note->method('setApp')->willReturnSelf();
				$note->method('setDateTime')->willReturnSelf();
				$note->method('setUser')->willReturnCallback(
					function (string $uid) use (&$seen, $note): INotification {
						$seen[] = $uid;
						return $note;
					}
				);
				$note->method('setObject')->willReturnCallback(
					function (string $type, string $id) use (&$seen, $note): INotification {
						$seen[] = $id;
						return $note;
					}
				);
				$note->method('setSubject')->willReturnCallback(
					function (string $subject, array $parameters) use (&$seen, $note, $expiry): INotification {
						$this->assertSame($expiry->format('Y-m-d'), $parameters['expiry']);
						$parameters['expiry'] = 'D';
						$seen[] = $subject;
						$seen[] = $parameters;
						return $note;
					}
				);
				$notes[spl_object_id($note)] = &$seen;
				return $note;
			}
		);
		$notifications->method('notify')->willReturnCallback(
			function (INotification $note) use (&$calls, &$notes): void {
				$seen = $notes[spl_object_id($note)];
				if ($seen[1] === 'bert') {
					throw new RuntimeException('push down');
				}

				$calls[] = $seen;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use (&$calls, $level): void {
					$calls[] = [$level, $message, $context];
				}
			);
		}

		$job = new BrpHealthCheckJob($this->createMock(ITimeFactory::class), $config, $groups, $notifications, $client, $logger);
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
		$this->assertSame($expected, $calls);
	}//end testRun()
}//end class
