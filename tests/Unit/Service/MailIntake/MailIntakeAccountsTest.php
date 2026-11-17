<?php

/**
 * MailIntakeAccounts tests: the Mail accounts are read with their owners, and only known ids are stored.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-names-the-ticket-mailboxes-req-itq-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\MailIntake;

use OCA\Pipelinq\Service\MailIntake\MailIntakeAccounts;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for {@see MailIntakeAccounts}.
 *
 * @covers \OCA\Pipelinq\Service\MailIntake\MailIntakeAccounts
 */
final class MailIntakeAccountsTest extends TestCase {

	public function testEveryMailAccountIsListedWithItsOwner(): void {
		$service = new MailIntakeAccounts($this->db(rows: self::rows()), $this->createMock(IAppConfig::class), $this->createMock(LoggerInterface::class));

		$this->assertSame(
			[
				['id' => 3, 'address' => 'service@gemeente.nl', 'name' => 'Service', 'owner' => 'functional'],
				['id' => 9, 'address' => 'bob@gemeente.nl', 'name' => 'Bob', 'owner' => 'bob'],
			],
			$service->all()
		);
	}

	public function testAnUnreadableMailTableIsNoAccountsAndALoggedWarning(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willThrowException(new RuntimeException('no such table'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$this->assertSame([], (new MailIntakeAccounts($db, $this->createMock(IAppConfig::class), $logger))->all());
	}

	public function testOnlyIdsThatNameAMailAccountAreStoredEachOnce(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->once())->method('setValueString')->with('pipelinq', 'inboundMail.accounts', '[3,9]');
		$service = new MailIntakeAccounts($this->db(rows: self::rows()), $config, $this->createMock(LoggerInterface::class));

		$this->assertSame([3, 9], $service->select(values: [3, '9', 3, 42, 'x', -1, 0, ['9']]));
	}

	public function testTheSelectionIsReadBackAndJunkIgnored(): void {
		foreach (['[3,"9",0,"x"]' => [3, 9], 'not json' => [], '{"a":3}' => [3]] as $stored => $expected) {
			$config = $this->createMock(IAppConfig::class);
			$config->method('getValueString')->with('pipelinq', 'inboundMail.accounts', '[]')->willReturn($stored);
			$service = new MailIntakeAccounts($this->createMock(IDBConnection::class), $config, $this->createMock(LoggerInterface::class));

			$this->assertSame($expected, $service->selected(), $stored);
		}
	}

	public function testTheMailboxesAreTheSelectedAccountsThatStillExist(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('[9,77]');
		$service = new MailIntakeAccounts($this->db(rows: self::rows()), $config, $this->createMock(LoggerInterface::class));

		$this->assertSame([['id' => 9, 'address' => 'bob@gemeente.nl', 'name' => 'Bob', 'owner' => 'bob']], $service->mailboxes());
	}

	/**
	 * Two Mail accounts.
	 *
	 * @return list<array<string, string>>
	 */
	private static function rows(): array {
		return [
			['id' => '3', 'email' => 'service@gemeente.nl', 'name' => 'Service', 'user_id' => 'functional'],
			['id' => '9', 'email' => 'bob@gemeente.nl', 'name' => 'Bob', 'user_id' => 'bob'],
		];
	}

	/**
	 * A connection answering these rows from mail_accounts.
	 *
	 * @param list<array<string, string>> $rows The rows.
	 *
	 * @return IDBConnection The connection.
	 */
	private function db(array $rows): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(function () use ($rows): IQueryBuilder {
			$pending = array_merge($rows, [false]);
			$result = $this->createMock(IResult::class);
			$result->method('fetch')->willReturnCallback(static function () use (&$pending) {
				return array_shift($pending);
			});
			$qb = $this->createMock(IQueryBuilder::class);
			$qb->method('select')->willReturnSelf();
			$qb->expects($this->once())->method('from')->with('mail_accounts')->willReturnSelf();
			$qb->method('orderBy')->willReturnSelf();
			$qb->method('executeQuery')->willReturn($result);

			return $qb;
		});

		return $db;
	}
}
