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

	public function testTheInboxAboveTheCursorIsReadOldestFirst(): void {
		$qb = $this->builder(rows: [['id' => '11', 'uid' => '7', 'subject' => 'Vraag', 'preview_text' => 'Hallo', 'thread_root_id' => '<a@b>'], false]);
		$qb->expects($this->once())->method('orderBy')->with('m.id', 'ASC')->willReturnSelf();
		$qb->expects($this->once())->method('setMaxResults')->with(200)->willReturnSelf();

		$rows = (new MailIntakeReader($this->db(qb: $qb), $this->createMock(LoggerInterface::class)))->inboxSince(accountId: 3, sinceId: 10);

		$this->assertSame([['id' => 11, 'uid' => '7', 'subject' => 'Vraag', 'preview' => 'Hallo', 'threadRootId' => '<a@b>']], $rows);
		$this->assertContains(['eq', 'mb.account_id', 3], $this->conditions);
		$this->assertContains(['eq', 'mb.name', 'INBOX'], $this->conditions);
		$this->assertContains(['gt', 'm.id', 10], $this->conditions);
	}

	public function testTheSenderIsTheTypeZeroRecipientInLowerCase(): void {
		$qb = $this->builder(rows: [['email' => ' Info@Bakkerij.NL ']]);

		$sender = (new MailIntakeReader($this->db(qb: $qb), $this->createMock(LoggerInterface::class)))->senderOf(messageId: 11);

		$this->assertSame('info@bakkerij.nl', $sender);
		$this->assertContains(['eq', 'message_id', 11], $this->conditions);
		$this->assertContains(['eq', 'type', 0], $this->conditions);
	}

	public function testNoSenderRowIsAnEmptySender(): void {
		$this->assertSame('', (new MailIntakeReader($this->db(qb: $this->builder(rows: [false])), $this->createMock(LoggerInterface::class)))->senderOf(messageId: 11));
	}

	public function testAnUnreadableMailTableIsLoggedAndReadsAsNothing(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willThrowException(new RuntimeException('no table'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('warning');
		$reader = new MailIntakeReader($db, $logger);

		$this->assertSame([], $reader->inboxSince(accountId: 3, sinceId: 0));
		$this->assertSame('', $reader->senderOf(messageId: 1));
	}

	/**
	 * A builder recording its conditions and answering these rows.
	 *
	 * @param list<array<string, string>|false> $rows The rows, false ending them.
	 *
	 * @return IQueryBuilder&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function builder(array $rows): IQueryBuilder {
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnCallback(static function () use (&$rows) {
			return array_shift($rows) ?? false;
		});
		$expr = $this->createMock(IExpressionBuilder::class);
		foreach (['eq', 'gt'] as $op) {
			$expr->method($op)->willReturnCallback(function (string $field, mixed $value) use ($op): string {
				$this->conditions[] = [$op, $field, $value];
				return $op . ':' . $field;
			});
		}

		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'join', 'where', 'andWhere'] as $method) {
			$qb->method($method)->willReturnSelf();
		}

		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(static fn (mixed $value): mixed => $value);
		$qb->method('executeQuery')->willReturn($result);

		return $qb;
	}

	private function db(IQueryBuilder $qb): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return $db;
	}
}
