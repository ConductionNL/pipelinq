<?php

/**
 * Pins one user's email matching run: every gate, call, log line and cursor move.
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
 * @spec openspec/specs/email-calendar-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\EmailMatchService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Settings, ownership, the inbound messages and their outcomes, against the exact calls.
 */
class EmailMatchRunGoldenTest extends TestCase {

	/**
	 * Settings, owner answer, message ids, failing ids, links per id, and the expected result and calls.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public static function cases(): array {
		$on = ['account' => 4, 'enabled' => true, 'excludedAddresses' => [], 'cursor' => 10];
		return [
			'disabled'         => [['enabled' => false] + $on, true, [], [], ['linked' => 0, 'scanned' => 0], []],
			'no account'       => [['account' => 0] + $on, true, [], [], ['linked' => 0, 'scanned' => 0], []],
			'foreign account'  => [$on, false, [11], [], ['linked' => 0, 'scanned' => 0], [['owns', 'u-1', 4]]],
			'nothing new'      => [$on, true, [], [], ['linked' => 0, 'scanned' => 0], [['owns', 'u-1', 4], ['status', 'u-1', 0, 0, null]]],
			'mixed run'        => [
				$on,
				true,
				[11, 14, 12, 15],
				[15],
				['linked' => 3, 'scanned' => 4],
				[
					['owns', 'u-1', 4],
					['match', 4, 11, 'u-1'],
					['match', 4, 14, 'u-1'],
					['match', 4, 12, 'u-1'],
					['match', 4, 15, 'u-1'],
					['warning', 'Pipelinq: email match per-message failure', ['messageId' => 15]],
					['status', 'u-1', 3, 4, null],
					['settings', 'u-1', ['account' => 4, 'enabled' => true, 'excludedAddresses' => [], 'cursor' => 14]],
				],
			],
			'only failures'    => [
				$on,
				true,
				[20],
				[20],
				['linked' => 0, 'scanned' => 1],
				[['owns', 'u-1', 4], ['match', 4, 20, 'u-1'], ['warning', 'Pipelinq: email match per-message failure', ['messageId' => 20]], ['status', 'u-1', 0, 1, null]],
			],
		];
	}//end cases()

	/**
	 * Each run makes exactly the pinned calls and answers the pinned counts.
	 *
	 * @param array<string, mixed>          $settings The stored settings.
	 * @param bool                          $owns     Whether the user owns the account.
	 * @param array<int, int>               $ids      The inbound message ids.
	 * @param array<int, int>               $failing  Ids whose match throws.
	 * @param array<string, int>            $result   The expected counts.
	 * @param array<int, array<int, mixed>> $expected The expected calls.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testRun(array $settings, bool $owns, array $ids, array $failing, array $result, array $expected): void {
		$calls  = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []) use (&$calls): void {
				$calls[] = ['warning', $message, $context];
			}
		);
		$rows = array_map(static fn (int $id): array => ['id' => (string)$id], $ids);
		$rows[] = false;
		$query = $this->createMock(IResult::class);
		$query->method('fetch')->willReturnOnConsecutiveCalls(...$rows);
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'join', 'where', 'andWhere', 'orderBy', 'setMaxResults'] as $fluent) {
			$qb->method($fluent)->willReturnSelf();
		}

		$qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$qb->method('executeQuery')->willReturn($query);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$service = $this->getMockBuilder(EmailMatchService::class)
			->setConstructorArgs([$this->createMock(ContainerInterface::class), $this->createMock(IAppConfig::class), $db, $logger])
			->onlyMethods(['getSettings', 'ownsAccount', 'matchAndLinkMessage', 'writeStatus', 'writeSettings'])
			->getMock();
		$service->method('getSettings')->willReturn($settings);
		$service->method('ownsAccount')->willReturnCallback(
			function (string $userId, int $accountId) use (&$calls, $owns): bool {
				$calls[] = ['owns', $userId, $accountId];
				return $owns;
			}
		);
		$service->method('matchAndLinkMessage')->willReturnCallback(
			function (int $account, int $message, string $userId) use (&$calls, $failing): int {
				$calls[] = ['match', $account, $message, $userId];
				if (in_array($message, $failing, true) === true) {
					throw new RuntimeException('imap');
				}

				return ([11 => 1, 14 => 2][$message] ?? 0);
			}
		);
		$service->method('writeStatus')->willReturnCallback(
			function (string $userId, int $linked, int $scanned, ?string $error = null) use (&$calls): void {
				$calls[] = ['status', $userId, $linked, $scanned, $error];
			}
		);
		$service->method('writeSettings')->willReturnCallback(
			function (string $userId, array $saved) use (&$calls): void {
				$calls[] = ['settings', $userId, $saved];
			}
		);

		$this->assertSame($result, $service->runForUser(userId: 'u-1'));
		$this->assertSame($expected, $calls);
	}//end testRun()
}//end class
