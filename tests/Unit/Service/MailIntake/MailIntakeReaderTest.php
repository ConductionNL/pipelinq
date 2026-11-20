<?php

/**
 * MailIntakeReader tests: only the INBOX above the cursor is read, and the sender comes back lower case.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-names-the-ticket-mailboxes-req-itq-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\MailIntake;

use OCA\Pipelinq\Service\MailIntake\MailIntakeReader;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for {@see MailIntakeReader}.
 *
 * @covers \OCA\Pipelinq\Service\MailIntake\MailIntakeReader
 */
final class MailIntakeReaderTest extends TestCase {

	/** @var list<array{0: string, 1: string, 2: mixed}> */
	private array $conditions = [];

	/**
	 * The inbox above the cursor is read oldest first.
	 *
	 * @return void
	 */
	public function testTheInboxAboveTheCursorIsReadOldestFirst(): void {
		$qb = $this->builder(rows: [['id' => '11', 'uid' => '7', 'subject' => 'Vraag', 'preview_text' => 'Hallo', 'thread_root_id' => '<a@b>'], false]);
		$qb->expects($this->once())->method('orderBy')->with('m.id', 'ASC')->willReturnSelf();
		$qb->expects($this->once())->method('setMaxResults')->with(200)->willReturnSelf();

		$rows = (
			new MailIntakeReader(db: $this->db(qb: $qb), logger: $this->createMock(originalClassName: LoggerInterface::class))
		)->inboxSince(accountId: 3, sinceId: 10);

		$this->assertSame(expected: [['id' => 11, 'uid' => '7', 'subject' => 'Vraag', 'preview' => 'Hallo', 'threadRootId' => '<a@b>']], actual: $rows);
		$this->assertContains(needle: ['eq', 'mb.account_id', 3], haystack: $this->conditions);
		$this->assertContains(needle: ['eq', 'mb.name', 'INBOX'], haystack: $this->conditions);
		$this->assertContains(needle: ['gt', 'm.id', 10], haystack: $this->conditions);
	}

	/**
	 * The sender is the type zero recipient in lower case.
	 *
	 * @return void
	 */
	public function testTheSenderIsTheTypeZeroRecipientInLowerCase(): void {
		$qb = $this->builder(rows: [['email' => ' Info@Bakkerij.NL ']]);

		$sender = (
			new MailIntakeReader(db: $this->db(qb: $qb), logger: $this->createMock(originalClassName: LoggerInterface::class))
		)->senderOf(messageId: 11);

		$this->assertSame(expected: 'info@bakkerij.nl', actual: $sender);
		$this->assertContains(needle: ['eq', 'message_id', 11], haystack: $this->conditions);
		$this->assertContains(needle: ['eq', 'type', 0], haystack: $this->conditions);
	}

	/**
	 * No sender row is an empty sender.
	 *
	 * @return void
	 */
	public function testNoSenderRowIsAnEmptySender(): void {
		$this->assertSame(
			expected: '',
			actual: (
				new MailIntakeReader(db: $this->db(qb: $this->builder(rows: [false])), logger: $this->createMock(originalClassName: LoggerInterface::class))
			)->senderOf(messageId: 11)
		);
	}

	/**
	 * An unreadable mail table is logged and reads as nothing.
	 *
	 * @return void
	 */
	public function testAnUnreadableMailTableIsLoggedAndReadsAsNothing(): void {
		$db = $this->createMock(originalClassName: IDBConnection::class);
		$db->method('getQueryBuilder')->willThrowException(new RuntimeException('no table'));
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->exactly(count: 2))->method('warning');
		$reader = new MailIntakeReader(db: $db, logger: $logger);

		$this->assertSame(expected: [], actual: $reader->inboxSince(accountId: 3, sinceId: 0));
		$this->assertSame(expected: '', actual: $reader->senderOf(messageId: 1));
	}

	/**
	 * A builder recording its conditions and answering these rows.
	 *
	 * @param list<array<string, string>|false> $rows The rows, false ending them.
	 *
	 * @return IQueryBuilder&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function builder(array $rows): IQueryBuilder {
		$result = $this->createMock(originalClassName: IResult::class);
		$result->method('fetch')->willReturnCallback(static function () use (&$rows) {
			return array_shift($rows) ?? false;
		});
		$expr = $this->createMock(originalClassName: IExpressionBuilder::class);
		foreach (['eq', 'gt'] as $op) {
			$expr->method($op)->willReturnCallback(function (string $field, mixed $value) use ($op): string {
				$this->conditions[] = [$op, $field, $value];
				return $op . ':' . $field;
			});
		}

		$qb = $this->createMock(originalClassName: IQueryBuilder::class);
		foreach (['select', 'from', 'join', 'where', 'andWhere'] as $method) {
			$qb->method($method)->willReturnSelf();
		}

		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(static fn (mixed $value): mixed => $value);
		$qb->method('executeQuery')->willReturn($result);

		return $qb;
	}

	/**
	 * Stand-in for db().
	 *
	 * @param IQueryBuilder $qb The qb.
	 *
	 * @return IDBConnection
	 */
	private function db(IQueryBuilder $qb): IDBConnection {
		$db = $this->createMock(originalClassName: IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return $db;
	}
}
