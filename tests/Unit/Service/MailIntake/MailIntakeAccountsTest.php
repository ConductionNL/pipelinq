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

	/**
	 * Every mail account is listed with its owner.
	 *
	 * @return void
	 */
	public function testEveryMailAccountIsListedWithItsOwner(): void {
		$service = new MailIntakeAccounts(
			db: $this->db(rows: self::rows()),
			appConfig: $this->createMock(originalClassName: IAppConfig::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

		$this->assertSame(
			expected: [
				['id' => 3, 'address' => 'service@gemeente.nl', 'name' => 'Service', 'owner' => 'functional'],
				['id' => 9, 'address' => 'bob@gemeente.nl', 'name' => 'Bob', 'owner' => 'bob'],
			],
			actual: $service->all()
		);
	}

	/**
	 * An unreadable mail table is no accounts and a logged warning.
	 *
	 * @return void
	 */
	public function testAnUnreadableMailTableIsNoAccountsAndALoggedWarning(): void {
		$db = $this->createMock(originalClassName: IDBConnection::class);
		$db->method('getQueryBuilder')->willThrowException(new RuntimeException('no such table'));
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$this->assertSame(
			expected: [],
			actual: (new MailIntakeAccounts(db: $db, appConfig: $this->createMock(originalClassName: IAppConfig::class), logger: $logger))->all()
		);
	}

	/**
	 * Only ids that name a mail account are stored each once.
	 *
	 * @return void
	 */
	public function testOnlyIdsThatNameAMailAccountAreStoredEachOnce(): void {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->expects($this->once())->method('setValueString')->with('pipelinq', 'inboundMail.accounts', '[3,9]');
		$service = new MailIntakeAccounts(
			db: $this->db(rows: self::rows()),
			appConfig: $config,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

		$this->assertSame(expected: [3, 9], actual: $service->select(values: [3, '9', 3, 42, 'x', -1, 0, ['9']]));
	}

	/**
	 * The selection is read back and junk ignored.
	 *
	 * @return void
	 */
	public function testTheSelectionIsReadBackAndJunkIgnored(): void {
		foreach (['[3,"9",0,"x"]' => [3, 9], 'not json' => [], '{"a":3}' => [3]] as $stored => $expected) {
			$config = $this->createMock(originalClassName: IAppConfig::class);
			$config->method('getValueString')->with('pipelinq', 'inboundMail.accounts', '[]')->willReturn($stored);
			$service = new MailIntakeAccounts(
				db: $this->createMock(originalClassName: IDBConnection::class),
				appConfig: $config,
				logger: $this->createMock(originalClassName: LoggerInterface::class)
			);

			$this->assertSame(expected: $expected, actual: $service->selected(), message: $stored);
		}
	}

	/**
	 * The mailboxes are the selected accounts that still exist.
	 *
	 * @return void
	 */
	public function testTheMailboxesAreTheSelectedAccountsThatStillExist(): void {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturn('[9,77]');
		$service = new MailIntakeAccounts(
			db: $this->db(rows: self::rows()),
			appConfig: $config,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

		$this->assertSame(expected: [['id' => 9, 'address' => 'bob@gemeente.nl', 'name' => 'Bob', 'owner' => 'bob']], actual: $service->mailboxes());
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
		$db = $this->createMock(originalClassName: IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(function () use ($rows): IQueryBuilder {
			$pending = array_merge($rows, [false]);
			$result = $this->createMock(originalClassName: IResult::class);
			$result->method('fetch')->willReturnCallback(static function () use (&$pending) {
				return array_shift($pending);
			});
			$qb = $this->createMock(originalClassName: IQueryBuilder::class);
			$qb->method('select')->willReturnSelf();
			$qb->expects($this->once())->method('from')->with('mail_accounts')->willReturnSelf();
			$qb->method('orderBy')->willReturnSelf();
			$qb->method('executeQuery')->willReturn($result);

			return $qb;
		});

		return $db;
	}
}
